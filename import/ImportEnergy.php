<?php
/**
 * Import ENERGY (Samsung's daily vitality/readiness score) xref rows from
 * Samsung Health's vitality_score export.
 *
 * Expects `com.samsung.shealth.vitality_score.<date>.csv` in
 * HEALTH_IMPORT_PATH (storage/health/) — copy it from a `health_lester_<date>`
 * split. One row per calendar day (`day_time`, always midnight — no real
 * clock-time, no BST handling needed, same as STEPS).
 *
 * **Folds in Lester's "interesting variation" — HRV doesn't need its own
 * import after all.** `vitality_score`'s own `shrv_value`/`shrv_score`
 * fields ("sleep HRV") ride along in the exact same row as `total_score`
 * (Energy) — confirmed against the source spreadsheet: `shrv_value` for
 * 2026-06-28 was 67.73, the spreadsheet's own "HRV" column for that date was
 * 67 — close enough (different reference-night boundary, not a mismatch) to
 * treat as the same figure. Health's own `health.hrv` CSV+jsons/ tier (the
 * shape-2 raw per-reading data, still flagged as the hard/deferred case in
 * health/MANUAL.md) is a *different*, much more granular thing — this only
 * covers the daily HRV summary number, not that.
 *
 * `xkey`=total_score (Energy), `xkey_ext`=shrv_value (HRV), `data`=json
 * `{shrv_score, activity_score, sleep_score}` — `sleep_score` here is
 * vitality_score's own composite figure, distinct from the real per-session
 * `sleep.sleep_score` the SLEEP item imports (see ImportSleep.php) — kept as
 * reference detail, not conflated with it.
 *
 * @package health
 */

require_once __DIR__.'/ImportPulse.php'; // healthParseSamsungCsv(), healthFindLatestSamsungCsv()

use Bitweaver\Health\HealthDay;
use Bitweaver\Liberty\LibertyXref;

/**
 * Insert an ENERGY xref row for one day, unless one already exists for this
 * exact content_id + entry_date.
 *
 * @param  int   $pContentId  The day's HealthDay content_id.
 * @param  int   $pTimestamp  Unix timestamp (midday UTC of that date).
 * @param  float $pTotalScore
 * @param  float $pShrvValue
 * @param  array $pDetail     ['shrv_score'=>.., 'activity_score'=>.., 'sleep_score'=>..]
 * @return bool  TRUE if a new row was inserted, FALSE if one already existed (skipped).
 */
function healthStoreEnergy( int $pContentId, int $pTimestamp, float $pTotalScore, float $pShrvValue, array $pDetail ): bool {
	global $gBitDb;

	$entryDate = gmdate( 'Y-m-d H:i:s', $pTimestamp );
	$existing = $gBitDb->getOne(
		"SELECT `xref_id` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'ENERGY' AND `entry_date` = ?",
		[ $pContentId, $entryDate ]
	);
	if( $existing ) {
		return false;
	}

	$pHash = [
		'content_id' => $pContentId,
		'item'       => 'ENERGY',
		'xorder'     => 0,
		'xkey'       => (string)round( $pTotalScore, 1 ),
		'xkey_ext'   => (string)round( $pShrvValue, 1 ),
		'edit'       => json_encode( $pDetail ),
		'entry_date' => $pTimestamp,
	];
	$xref = new LibertyXref();
	$xref->store( $pHash );
	return true;
}

/**
 * Run the full vitality_score import.
 *
 * @param  string $pCsvFile
 * @return array{created:int,skipped:int,errors:string[]}
 */
function healthImportEnergy( string $pCsvFile ): array {
	$result = [ 'created' => 0, 'skipped' => 0, 'errors' => [] ];

	if( !is_readable( $pCsvFile ) ) {
		$result['errors'][] = "Can't read $pCsvFile";
		return $result;
	}

	foreach( healthParseSamsungCsv( $pCsvFile ) as $rowNum => $row ) {
		$date = substr( (string)( $row['day_time'] ?? '' ), 0, 10 );
		if( !preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$result['errors'][] = "Row $rowNum: unparseable day_time '{$row['day_time']}'";
			continue;
		}
		[ $y, $m, $d ] = array_map( 'intval', explode( '-', $date ) );
		$timestamp = gmmktime( 12, 0, 0, $m, $d, $y );

		$totalScore = (float)( $row['total_score'] ?? 0 );
		if( $totalScore <= 0 ) {
			$result['errors'][] = "Row $rowNum ($date): no total_score";
			continue;
		}

		$detail = [];
		if( !empty( $row['shrv_score'] ) ) {
			$detail['shrv_score'] = round( (float)$row['shrv_score'], 1 );
		}
		if( !empty( $row['activity_score'] ) ) {
			$detail['activity_score'] = round( (float)$row['activity_score'], 1 );
		}
		if( !empty( $row['sleep_score'] ) ) {
			$detail['sleep_score'] = round( (float)$row['sleep_score'], 1 );
		}

		$day = HealthDay::findOrCreate( $date );
		if( healthStoreEnergy( $day->mContentId, $timestamp, $totalScore, (float)( $row['shrv_value'] ?? 0 ), $detail ) ) {
			$result['created']++;
		} else {
			$result['skipped']++;
		}
	}

	return $result;
}
