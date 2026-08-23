<?php
/**
 * Import WT (weight/BMI/body-composition) xref rows from a HealthForYou
 * weight.csv export.
 *
 * Expects `weight.csv` in HEALTH_IMPORT_PATH (storage/health/) — copy it from a
 * `healthforyou_lester_<date>/weight.csv` split (see
 * ~/Personal/Health/HealthForYouApp/split_healthforyou.py). Semicolon-delimited,
 * UK dd/mm/yyyy dates, columns: Date;Time;Weight;BMI;Body fat;Water;Muscle;Bones;
 * Added manually.
 *
 * Every row is imported as its own WT xref row against that date's HealthDay
 * content_id — no AM-only/lowest-weight reduction here, that's a query-time
 * concern for whatever eventually builds the day summary (see health/MANUAL.md's
 * "Content model" section). Safe to re-run against an overlapping/refreshed
 * export: dedupes on (content_id, item, start_date) — a WT row already stored
 * for that exact reading's timestamp is left untouched, not reinserted.
 * start_date carries the reading's own timestamp; entry_date is left to
 * LibertyXref's own default (when the row was created).
 *
 * @package health
 */

use Bitweaver\Health\HealthDay;
use Bitweaver\Liberty\LibertyXref;

/**
 * Parse a HealthForYou-format CSV (semicolon-delimited, 1 header row) into an
 * array of associative rows keyed by header column name.
 *
 * @param  string $pFile
 * @return array<int, array<string,string>>
 */
function healthParseHealthForYouCsv( string $pFile ): array {
	$rows = [];
	if( ( $fh = fopen( $pFile, 'r' ) ) === false ) {
		return $rows;
	}
	$header = fgetcsv( $fh, 0, ';', '"', '' );
	while( ( $row = fgetcsv( $fh, 0, ';', '"', '' ) ) !== false ) {
		if( count( $row ) < count( $header ) ) {
			continue; // trailing blank line
		}
		$rows[] = array_combine( $header, array_map( 'trim', $row ) );
	}
	fclose( $fh );
	return $rows;
}

/**
 * Combine a HealthForYou 'dd/mm/yyyy' date and 'hh:mm am/pm' time into a real
 * UTC unix timestamp, resolving the wall-clock reading against the Europe/London
 * zone (source has no embedded UTC offset at all, unlike Samsung's own CSVs —
 * see health/CLAUDE.md's 2026-08-22 entry on the same BST/GMT issue elsewhere).
 *
 * @param  string $pDate  'dd/mm/yyyy'
 * @param  string $pTime  'hh:mm am'/'hh:mm pm'
 * @return array{0:string,1:int}|null  [ 'YYYY-MM-DD', unix timestamp ], or null if unparseable.
 */
function healthParseHealthForYouTimestamp( string $pDate, string $pTime ): ?array {
	$dt = \DateTime::createFromFormat( 'd/m/Y h:i a', $pDate.' '.strtolower( $pTime ), new \DateTimeZone( 'Europe/London' ) );
	if( !$dt ) {
		return null;
	}
	return [ $dt->format( 'Y-m-d' ), $dt->getTimestamp() ];
}

/**
 * Insert a WT xref row for one weight reading, unless a row already exists for
 * this exact content_id + start_date (reimport safety — see this file's own
 * docblock). Computes its own xorder rather than using LibertyXref's fAddXref
 * path, since WT is flagged multiple=-1 (read-only) and fAddXref would be
 * rejected outright — see health/admin/schema_inc.php's WT comment.
 *
 * @param  int    $pContentId  The day's HealthDay content_id.
 * @param  int    $pTimestamp  Unix timestamp of the reading (UTC).
 * @param  float  $pWeight     kg
 * @param  float  $pBmi
 * @param  array  $pBodyComp   ['body_fat_pct'=>..,'water_pct'=>..,'muscle_pct'=>..,'bone_mass_kg'=>..]
 * @return bool  TRUE if a new row was inserted, FALSE if one already existed (skipped).
 */
function healthStoreWT( int $pContentId, int $pTimestamp, float $pWeight, float $pBmi, array $pBodyComp ): bool {
	global $gBitDb;

	$startDate = gmdate( 'Y-m-d H:i:s', $pTimestamp );
	$existing = $gBitDb->getOne(
		"SELECT `xref_id` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'WT' AND `start_date` = ?",
		[ $pContentId, $startDate ]
	);
	if( $existing ) {
		return false;
	}

	$nextXorder = (int)$gBitDb->getOne(
		"SELECT COALESCE( MAX(`xorder`) + 1, 0 ) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'WT'",
		[ $pContentId ]
	);

	$pHash = [
		'content_id' => $pContentId,
		'item'       => 'WT',
		'xorder'     => $nextXorder,
		'xkey'       => (string)$pWeight,
		'xkey_ext'   => (string)$pBmi,
		'edit'       => json_encode( $pBodyComp ),
		'start_date' => $pTimestamp,
	];
	$xref = new LibertyXref();
	$xref->store( $pHash );
	return true;
}

/**
 * Run the full weight.csv import.
 *
 * @param  string $pFile
 * @return array{created:int,skipped:int,errors:string[]}
 */
function healthImportWT( string $pFile ): array {
	$result = [ 'created' => 0, 'skipped' => 0, 'errors' => [] ];

	if( !is_readable( $pFile ) ) {
		$result['errors'][] = "Can't read $pFile";
		return $result;
	}

	foreach( healthParseHealthForYouCsv( $pFile ) as $rowNum => $row ) {
		$parsed = healthParseHealthForYouTimestamp( $row['Date'] ?? '', $row['Time'] ?? '' );
		if( !$parsed ) {
			$result['errors'][] = "Row $rowNum: unparseable date/time '{$row['Date']}' '{$row['Time']}'";
			continue;
		}
		[ $date, $timestamp ] = $parsed;

		$weight = (float)( $row['Weight'] ?? 0 );
		if( $weight <= 0 ) {
			$result['errors'][] = "Row $rowNum ($date): no weight value";
			continue;
		}

		$bodyComp = [
			'body_fat_pct'  => (float)( $row['Body fat'] ?? 0 ),
			'water_pct'     => (float)( $row['Water'] ?? 0 ),
			'muscle_pct'    => (float)( $row['Muscle'] ?? 0 ),
			'bone_mass_kg'  => (float)( $row['Bones'] ?? 0 ),
		];

		$day = HealthDay::findOrCreate( $date );
		if( healthStoreWT( $day->mContentId, $timestamp, $weight, (float)( $row['BMI'] ?? 0 ), $bodyComp ) ) {
			$result['created']++;
		} else {
			$result['skipped']++;
		}
	}

	return $result;
}
