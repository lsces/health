<?php
/**
 * Import OXI (finger-probe pulse oximeter: Pulse + SpO2) xref rows from a
 * HealthForYou pulse_oximeter.csv export.
 *
 * Expects `pulse_oximeter.csv` in HEALTH_IMPORT_PATH (storage/health/) — copy
 * it from a `healthforyou_lester_<date>/pulse_oximeter.csv` split (see
 * ~/Personal/Health/HealthForYouApp/split_healthforyou.py). Same CSV shape as
 * weight.csv/blood_pressure.csv (semicolon-delimited, UK dd/mm/yyyy dates) —
 * reuses ImportWT.php's parsing helpers rather than duplicating them.
 * Columns: Date;Start time;Time;SpO2 average;SpO2 min;SpO2 max;Pulse;Added
 * manually.
 *
 * Every row imported as its own OXI xref row, no reduction — same reasoning
 * as WT/BP (real short-interval readings, not noise to average away).
 *
 * @package health
 */

require_once __DIR__.'/ImportWT.php'; // healthParseHealthForYouCsv(), healthParseHealthForYouTimestamp()

use Bitweaver\Health\HealthDay;
use Bitweaver\Liberty\LibertyXref;

/**
 * Insert an OXI xref row for one reading, unless a row already exists for
 * this exact content_id + entry_date. Computes its own xorder rather than
 * using LibertyXref's fAddXref path, same reasoning as healthStoreWT().
 *
 * @param  int   $pContentId  The day's HealthDay content_id.
 * @param  int   $pTimestamp  Unix timestamp of the reading (UTC).
 * @param  float $pSpo2Avg
 * @param  int   $pPulse
 * @param  array $pDetail     ['spo2_min'=>.., 'spo2_max'=>..]
 * @return bool  TRUE if a new row was inserted, FALSE if one already existed (skipped).
 */
function healthStoreOxi( int $pContentId, int $pTimestamp, float $pSpo2Avg, int $pPulse, array $pDetail ): bool {
	global $gBitDb;

	$entryDate = gmdate( 'Y-m-d H:i:s', $pTimestamp );
	$existing = $gBitDb->getOne(
		"SELECT `xref_id` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'OXI' AND `entry_date` = ?",
		[ $pContentId, $entryDate ]
	);
	if( $existing ) {
		return false;
	}

	$nextXorder = (int)$gBitDb->getOne(
		"SELECT COALESCE( MAX(`xorder`) + 1, 0 ) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'OXI'",
		[ $pContentId ]
	);

	$pHash = [
		'content_id' => $pContentId,
		'item'       => 'OXI',
		'xorder'     => $nextXorder,
		'xkey'       => (string)$pSpo2Avg,
		'xkey_ext'   => (string)$pPulse,
		'edit'       => json_encode( $pDetail ),
		'entry_date' => $pTimestamp,
	];
	$xref = new LibertyXref();
	$xref->store( $pHash );
	return true;
}

/**
 * Run the full pulse_oximeter.csv import.
 *
 * @param  string $pFile
 * @return array{created:int,skipped:int,errors:string[]}
 */
function healthImportOxi( string $pFile ): array {
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

		$spo2Avg = (float)( $row['SpO2 average'] ?? 0 );
		$pulse   = (int)( $row['Pulse'] ?? 0 );
		if( $spo2Avg <= 0 || $pulse <= 0 ) {
			$result['errors'][] = "Row $rowNum ($date): no SpO2/pulse value";
			continue;
		}

		$detail = [];
		if( !empty( $row['SpO2 min'] ) ) {
			$detail['spo2_min'] = (int)$row['SpO2 min'];
		}
		if( !empty( $row['SpO2 max'] ) ) {
			$detail['spo2_max'] = (int)$row['SpO2 max'];
		}

		$day = HealthDay::findOrCreate( $date );
		if( healthStoreOxi( $day->mContentId, $timestamp, $spo2Avg, $pulse, $detail ) ) {
			$result['created']++;
		} else {
			$result['skipped']++;
		}
	}

	return $result;
}
