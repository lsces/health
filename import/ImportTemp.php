<?php
/**
 * Import TEMP (ear temperature) xref rows from a HealthForYou temperature.csv
 * export.
 *
 * Expects `temperature.csv` in HEALTH_IMPORT_PATH (storage/health/) — copy it
 * from a `healthforyou_name_<date>/temperature.csv` split (see
 * ~/Personal/Health/HealthForYouApp/split_healthforyou.py). Same CSV shape as
 * weight.csv/blood_pressure.csv — reuses ImportWT.php's parsing helpers.
 * Columns: Date;Time;TEMPERATURE;Mode;Added manually.
 *
 * Every row imported as its own TEMP row, no reduction. Same-day duplicates
 * are normal (a retake after cleaning the probe tip), not a data-quality
 * issue — no AM-only-style selection rule needed here, unlike WT.
 *
 * @package health
 */

require_once __DIR__.'/ImportWT.php'; // healthParseHealthForYouCsv(), healthParseHealthForYouTimestamp()

use Bitweaver\Health\HealthDay;
use Bitweaver\Liberty\LibertyXref;

/**
 * Insert a TEMP xref row for one reading, unless a row already exists for
 * this exact content_id + start_date. start_date carries the reading's own
 * timestamp; entry_date is left to LibertyXref's own default (when the row
 * was created).
 *
 * @param  int    $pContentId  The day's HealthDay content_id.
 * @param  int    $pTimestamp  Unix timestamp of the reading (UTC).
 * @param  float  $pTemp       Degrees C.
 * @param  string $pMode       e.g. "Ear temperature".
 * @return bool  TRUE if a new row was inserted, FALSE if one already existed (skipped).
 */
function healthStoreTemp( int $pContentId, int $pTimestamp, float $pTemp, string $pMode ): bool {
	global $gBitDb;

	$startDate = gmdate( 'Y-m-d H:i:s', $pTimestamp );
	$existing = $gBitDb->getOne(
		"SELECT `xref_id` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'TEMP' AND `start_date` = ?",
		[ $pContentId, $startDate ]
	);
	if( $existing ) {
		return false;
	}

	$nextXorder = (int)$gBitDb->getOne(
		"SELECT COALESCE( MAX(`xorder`) + 1, 0 ) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'TEMP'",
		[ $pContentId ]
	);

	$pHash = [
		'content_id' => $pContentId,
		'item'       => 'TEMP',
		'xorder'     => $nextXorder,
		'xkey'       => (string)$pTemp,
		'xkey_ext'   => $pMode,
		'start_date' => $pTimestamp,
	];
	$xref = new LibertyXref();
	$xref->store( $pHash );
	return true;
}

/**
 * Run the full temperature.csv import.
 *
 * @param  string $pFile
 * @return array{created:int,skipped:int,errors:string[]}
 */
function healthImportTemp( string $pFile ): array {
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

		$temp = (float)( $row['TEMPERATURE'] ?? 0 );
		if( $temp <= 0 ) {
			$result['errors'][] = "Row $rowNum ($date): no temperature value";
			continue;
		}

		$day = HealthDay::findOrCreate( $date );
		if( healthStoreTemp( $day->mContentId, $timestamp, $temp, (string)( $row['Mode'] ?? '' ) ) ) {
			$result['created']++;
		} else {
			$result['skipped']++;
		}
	}

	return $result;
}
