<?php
/**
 * Import STEPS (steps/active minutes/active kcal) xref rows from Samsung
 * Health's activity.day_summary export.
 *
 * Expects `com.samsung.shealth.activity.day_summary.<date>.csv` in
 * HEALTH_IMPORT_PATH (storage/health/) — copy it from a `health_lester_<date>`
 * split (see ~/Personal/Health/Samsung Health/split_health.sh). Picks the
 * most recent date suffix present, same idea as ImportPulse.php's own
 * "find latest" helper, generalised there for this file to reuse.
 *
 * One row per calendar day already (confirmed: 738 rows for 738 distinct
 * `day_time` values in the 2026-08-14 export) — no session/BST handling
 * needed, `day_time` is always midnight with no real clock-time meaning.
 * `active_time` is in **milliseconds** (confirmed against the source
 * spreadsheet: 6,480,095ms / 60000 = 108.0016 minutes, matching the known
 * 28/06/2026 "Active Mins" value of 108 exactly) — `calorie`/`step_count`
 * matched that same reference row directly (992.9→992, 10897 exact).
 *
 * `xkey`=step_count, `xkey_ext`=active_mins (derived, rounded), `data`=json
 * `{active_kcal}`. No source found for the spreadsheet's "Exercise Raised HR"
 * column (`exercise_time` is a wildly different scale — ~85min vs the
 * spreadsheet's single-digit values for the same date — not the same thing)
 * — left out, same as it was flagged unresolved when originally scoped.
 *
 * @package health
 */

require_once __DIR__.'/ImportPulse.php'; // healthParseSamsungCsv(), healthFindLatestSamsungCsv()

use Bitweaver\Health\HealthDay;
use Bitweaver\Liberty\LibertyXref;

/**
 * Insert a STEPS xref row for one day, unless one already exists for this
 * exact content_id + start_date. start_date carries the day's own timestamp;
 * entry_date is left to LibertyXref's own default (when the row was
 * created).
 *
 * @param  int   $pContentId  The day's HealthDay content_id.
 * @param  int   $pTimestamp  Unix timestamp (midday UTC of that date — no
 *                            real clock-time in the source, see docblock).
 * @param  int   $pSteps
 * @param  float $pActiveMins
 * @param  float $pActiveKcal
 * @return bool  TRUE if a new row was inserted, FALSE if one already existed (skipped).
 */
function healthStoreSteps( int $pContentId, int $pTimestamp, int $pSteps, float $pActiveMins, float $pActiveKcal ): bool {
	global $gBitDb;

	$startDate = gmdate( 'Y-m-d H:i:s', $pTimestamp );
	$existing = $gBitDb->getOne(
		"SELECT `xref_id` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'STEPS' AND `start_date` = ?",
		[ $pContentId, $startDate ]
	);
	if( $existing ) {
		return false;
	}

	$pHash = [
		'content_id' => $pContentId,
		'item'       => 'STEPS',
		'xorder'     => 0,
		'xkey'       => (string)$pSteps,
		'xkey_ext'   => (string)round( $pActiveMins, 1 ),
		'edit'       => json_encode( [ 'active_kcal' => round( $pActiveKcal, 1 ) ] ),
		'start_date' => $pTimestamp,
	];
	$xref = new LibertyXref();
	$xref->store( $pHash );
	return true;
}

/**
 * Run the full activity.day_summary import.
 *
 * @param  string $pCsvFile
 * @return array{created:int,skipped:int,errors:string[]}
 */
function healthImportSteps( string $pCsvFile ): array {
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

		$steps = (int)( $row['step_count'] ?? 0 );
		if( $steps <= 0 ) {
			$result['errors'][] = "Row $rowNum ($date): no step count";
			continue;
		}

		$activeMins = (float)( $row['active_time'] ?? 0 ) / 60000;
		$activeKcal = (float)( $row['calorie'] ?? 0 );

		$day = HealthDay::findOrCreate( $date );
		if( healthStoreSteps( $day->mContentId, $timestamp, $steps, $activeMins, $activeKcal ) ) {
			$result['created']++;
		} else {
			$result['skipped']++;
		}
	}

	return $result;
}
