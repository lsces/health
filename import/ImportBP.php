<?php
/**
 * Import BP (systolic/diastolic/pulse/MAP) xref rows from a HealthForYou
 * blood_pressure.csv export.
 *
 * Expects `blood_pressure.csv` in HEALTH_IMPORT_PATH (storage/health/) — copy it
 * from a `healthforyou_name_<date>/blood_pressure.csv` split (see
 * ~/Personal/Health/HealthForYouApp/split_healthforyou.py). Same CSV shape as
 * weight.csv (semicolon-delimited, UK dd/mm/yyyy dates) — reuses
 * healthParseHealthForYouCsv()/healthParseHealthForYouTimestamp() from
 * ImportWT.php rather than duplicating them, same convention Food's
 * ImportFoodIntake.php already uses against ImportFoodInfo.php's helpers.
 * Columns: Date;Time;Sys;Dia;Pulse;MAP;Added manually.
 *
 * **HealthForYou (cuff) only for this pass** — every row here is tagged
 * `source: 'cuff'`. Samsung's own blood_pressure.csv also has real BP data
 * (both cuff-synced *and* the watch's own PPG-estimate readings, distinguished
 * by pkg_name — see project_health_package_scoping memory) but isn't imported
 * yet: it needs its own dedup pass first (the cuff monitor's 150-reading
 * buffer got fully re-synced on every connection before a fix landed, so
 * historical Samsung rows have real same-reading duplicates to collapse) and
 * cross-source dedup against this HealthForYou data (confirmed to be the same
 * physical readings for at least one spot-checked date range). Deliberately
 * deferred, not forgotten.
 *
 * Every row is imported as its own BP xref row — even the "reliable" cuff
 * source shows real short-interval variability given the author's arrhythmia, so
 * no daily reduction here either, same as WT/PULSE.
 *
 * @package health
 */

require_once __DIR__.'/ImportWT.php'; // healthParseHealthForYouCsv(), healthParseHealthForYouTimestamp()

use Bitweaver\Health\HealthDay;
use Bitweaver\Liberty\LibertyXref;

/**
 * Insert a BP xref row for one reading, unless a row already exists for this
 * exact content_id + start_date (reimport safety — see this file's own
 * docblock). start_date carries the reading's own timestamp (when it
 * happened); entry_date is left to LibertyXref's own default (when the row
 * was created). Computes its own xorder rather than using LibertyXref's
 * fAddXref path, same reasoning as healthStoreWT() in ImportWT.php.
 *
 * @param  int    $pContentId  The day's HealthDay content_id.
 * @param  int    $pTimestamp  Unix timestamp of the reading (UTC).
 * @param  float  $pSystolic
 * @param  float  $pDiastolic
 * @param  array  $pDetail     ['pulse'=>.., 'map'=>.., 'source'=>'cuff'|'watch', 'comment'=>..] — sparse, only real values included.
 * @return bool  TRUE if a new row was inserted, FALSE if one already existed (skipped).
 */
function healthStoreBP( int $pContentId, int $pTimestamp, float $pSystolic, float $pDiastolic, array $pDetail ): bool {
	global $gBitDb;

	$startDate = gmdate( 'Y-m-d H:i:s', $pTimestamp );
	$existing = $gBitDb->getOne(
		"SELECT `xref_id` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'BP' AND `start_date` = ?",
		[ $pContentId, $startDate ]
	);
	if( $existing ) {
		return false;
	}

	$nextXorder = (int)$gBitDb->getOne(
		"SELECT COALESCE( MAX(`xorder`) + 1, 0 ) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'BP'",
		[ $pContentId ]
	);

	$pHash = [
		'content_id' => $pContentId,
		'item'       => 'BP',
		'xorder'     => $nextXorder,
		'xkey'       => (string)$pSystolic,
		'xkey_ext'   => (string)$pDiastolic,
		'edit'       => json_encode( $pDetail ),
		'start_date' => $pTimestamp,
	];
	$xref = new LibertyXref();
	$xref->store( $pHash );
	return true;
}

/**
 * Run the full blood_pressure.csv import (HealthForYou/cuff source only).
 *
 * @param  string $pFile
 * @return array{created:int,skipped:int,errors:string[]}
 */
function healthImportBP( string $pFile ): array {
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

		$sys = (float)( $row['Sys'] ?? 0 );
		$dia = (float)( $row['Dia'] ?? 0 );
		if( $sys <= 0 || $dia <= 0 ) {
			$result['errors'][] = "Row $rowNum ($date): no systolic/diastolic value";
			continue;
		}

		$detail = [ 'source' => 'cuff' ];
		if( !empty( $row['Pulse'] ) ) {
			$detail['pulse'] = (int)$row['Pulse'];
		}
		if( !empty( $row['MAP'] ) ) {
			$detail['map'] = (int)$row['MAP'];
		}

		$day = HealthDay::findOrCreate( $date );
		if( healthStoreBP( $day->mContentId, $timestamp, $sys, $dia, $detail ) ) {
			$result['created']++;
		} else {
			$result['skipped']++;
		}
	}

	return $result;
}
