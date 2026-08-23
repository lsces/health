<?php
/**
 * Rebuild PULSE (half-hour slots) and RAISEDHR (daily threshold minutes)
 * directly from HEALTH_HR_RAW's unified, deduped timeline - replacing both
 * items' original file-parsing importers (ImportPulse.php's healthImportPulse()
 * and ImportRaisedHR.php's healthImportRaisedHR()), which each independently
 * re-parsed and combined the same two raw Samsung sources.
 *
 * Real fix, not just a refactor: PULSE was background-only from the day it
 * was built (no exercise data existed to combine with yet) - genuinely
 * missing coverage during exercise the whole time, the same gap RAISEDHR
 * had before its own two-source fix. Both now read the same rows.
 *
 * One day at a time (Europe/London calendar day): query HEALTH_HR_RAW for
 * that day, both sources - each row's own `source` column already means no
 * exercise-window-exclusion pass is needed the way ImportRaisedHR.php's
 * background parse required, since a background row genuinely never exists
 * during an active exercise session (confirmed empirically, see that file's
 * own docblock) - delete that day's existing PULSE/RAISEDHR rows, then
 * insert fresh ones from the combined rows. Reuses healthStorePulseSlot()/
 * healthStoreRaisedHR()/healthRaisedHRMinutes() as-is from the two files
 * above; the delete-first step means their own internal "skip if a row
 * already exists" dedup never has anything left to skip.
 *
 * @package health
 */

require_once __DIR__.'/ImportPulse.php';     // healthStorePulseSlot()
require_once __DIR__.'/ImportRaisedHR.php';  // healthStoreRaisedHR()/healthRaisedHRMinutes()/thresholds/gap caps

use Bitweaver\Health\HealthDay;

/**
 * Fetch every HEALTH_HR_RAW row for one Europe/London calendar day, both
 * sources, time-ordered. Day boundaries are computed in local time then
 * converted to their true UTC instant before querying - start_time is
 * stored as a UTC instant, so comparing against a bare local-time string
 * would shift the window by the current BST/GMT offset.
 *
 * @return array<int, array{heart_rate:float, heart_rate_min:?float, heart_rate_max:?float, start_time:int, source:string}>
 */
function healthRebuildFetchDayRows( string $pDate ): array {
	global $gBitDb;
	$tz  = new \DateTimeZone( 'Europe/London' );
	$utc = new \DateTimeZone( 'UTC' );
	$start = new \DateTime( $pDate.' 00:00:00', $tz );
	$end   = ( clone $start )->modify( '+1 day' );
	$startUtc = ( clone $start )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
	$endUtc   = ( clone $end )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );

	$rows = $gBitDb->getAll(
		"SELECT `start_time`, `heart_rate`, `heart_rate_min`, `heart_rate_max`, `source`
		 FROM `".BIT_DB_PREFIX."health_hr_raw`
		 WHERE `start_time` >= ? AND `start_time` < ?
		 ORDER BY `start_time`",
		[ $startUtc, $endUtc ]
	);

	$out = [];
	foreach( $rows as $row ) {
		$out[] = [
			'heart_rate'     => (float)$row['heart_rate'],
			'heart_rate_min' => isset( $row['heart_rate_min'] ) && $row['heart_rate_min'] !== '' ? (float)$row['heart_rate_min'] : null,
			'heart_rate_max' => isset( $row['heart_rate_max'] ) && $row['heart_rate_max'] !== '' ? (float)$row['heart_rate_max'] : null,
			'start_time'     => ( new \DateTime( $row['start_time'], $utc ) )->getTimestamp(),
			'source'         => $row['source'],
		];
	}
	return $out;
}

/**
 * Delete every existing xref row for one item on one day, so a rebuild
 * genuinely replaces rather than accumulates alongside the old result.
 */
function healthRebuildDeleteDayItem( int $pContentId, string $pItem ): void {
	global $gBitDb;
	$gBitDb->query(
		"DELETE FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = ?",
		[ $pContentId, $pItem ]
	);
}

/**
 * Rebuild PULSE's half-hour slots for one day from already-fetched
 * HEALTH_HR_RAW rows - both sources combined (see this file's own docblock
 * for why that's now correct where it wasn't before). A slot's low/high are
 * the min/max of each sample's own heart_rate_min/heart_rate_max, falling
 * back to its plain heart_rate when null (true for every exercise-source
 * row, which has no internal per-bin min/max the way a 60s background bin
 * does - a single ~1s instant has nothing to average within itself).
 *
 * @return int  Slots created.
 */
function healthRebuildDayPulse( int $pContentId, array $pRows ): int {
	$tz = new \DateTimeZone( 'Europe/London' );
	$slots = []; // slotKey => [ 'slotStart' => unix ts, 'bins' => [...] ]

	foreach( $pRows as $r ) {
		$dt = ( new \DateTime( '@'.$r['start_time'] ) )->setTimezone( $tz );
		$slotStart = ( clone $dt )->setTime( (int)$dt->format( 'H' ), (int)( intdiv( (int)$dt->format( 'i' ), 30 ) * 30 ), 0 );
		$slotKey = $slotStart->format( 'Y-m-d H:i' );

		if( !isset( $slots[$slotKey] ) ) {
			$slots[$slotKey] = [ 'slotStart' => $slotStart->getTimestamp(), 'bins' => [] ];
		}
		$slots[$slotKey]['bins'][] = [
			'heart_rate'     => $r['heart_rate'],
			'heart_rate_min' => $r['heart_rate_min'] ?? $r['heart_rate'],
			'heart_rate_max' => $r['heart_rate_max'] ?? $r['heart_rate'],
			'start_time'     => $r['start_time'] * 1000, // ms, matching the original bin shape
			'source'         => $r['source'],
		];
	}

	healthRebuildDeleteDayItem( $pContentId, 'PULSE' );

	$created = 0;
	foreach( $slots as $slot ) {
		$rates = array_column( $slot['bins'], 'heart_rate' );
		$lows  = array_column( $slot['bins'], 'heart_rate_min' );
		$highs = array_column( $slot['bins'], 'heart_rate_max' );
		if( healthStorePulseSlot(
			$pContentId, $slot['slotStart'],
			array_sum( $rates ) / count( $rates ), min( $lows ), max( $highs ),
			$slot['bins']
		) ) {
			$created++;
		}
	}
	return $created;
}

/**
 * Rebuild RAISEDHR for one day from already-fetched HEALTH_HR_RAW rows -
 * simpler than ImportRaisedHR.php's original two-file version: no exercise-
 * window-exclusion pass needed, each row is already tagged by source.
 *
 * @return bool  TRUE if a row was written.
 */
function healthRebuildDayRaisedHR( int $pContentId, string $pDate, array $pRows ): bool {
	$tz = new \DateTimeZone( 'Europe/London' );
	$exercise = $background = [];
	foreach( $pRows as $r ) {
		if( $r['source'] === 'exercise' ) {
			$exercise[] = $r;
		} else {
			$background[] = $r;
		}
	}

	$exLow  = healthRaisedHRMinutes( $exercise, HEALTH_RAISEDHR_THRESHOLD_LOW, HEALTH_RAISEDHR_EXERCISE_GAP_CAP );
	$exHigh = healthRaisedHRMinutes( $exercise, HEALTH_RAISEDHR_THRESHOLD_HIGH, HEALTH_RAISEDHR_EXERCISE_GAP_CAP );
	$exTop  = healthRaisedHRMinutes( $exercise, HEALTH_RAISEDHR_THRESHOLD_TOP, HEALTH_RAISEDHR_EXERCISE_GAP_CAP );
	$bgLow  = healthRaisedHRMinutes( $background, HEALTH_RAISEDHR_THRESHOLD_LOW, HEALTH_RAISEDHR_BACKGROUND_GAP_CAP );
	$bgHigh = healthRaisedHRMinutes( $background, HEALTH_RAISEDHR_THRESHOLD_HIGH, HEALTH_RAISEDHR_BACKGROUND_GAP_CAP );
	$bgTop  = healthRaisedHRMinutes( $background, HEALTH_RAISEDHR_THRESHOLD_TOP, HEALTH_RAISEDHR_BACKGROUND_GAP_CAP );

	$detail = [
		'mins_130'                => round( $exTop + $bgTop, 1 ),
		'exercise_mins_90'        => round( $exLow, 1 ),
		'exercise_mins_100'       => round( $exHigh, 1 ),
		'exercise_mins_130'       => round( $exTop, 1 ),
		'exercise_sample_count'   => count( $exercise ),
		'background_mins_90'      => round( $bgLow, 1 ),
		'background_mins_100'     => round( $bgHigh, 1 ),
		'background_mins_130'     => round( $bgTop, 1 ),
		'background_sample_count' => count( $background ),
	];

	healthRebuildDeleteDayItem( $pContentId, 'RAISEDHR' );

	$dayStart = new \DateTime( $pDate.' 00:00:00', $tz );
	return healthStoreRaisedHR( $pContentId, $dayStart->getTimestamp(), $exLow + $bgLow, $exHigh + $bgHigh, $detail );
}

/**
 * Rebuild both PULSE and RAISEDHR for one calendar day (Europe/London).
 *
 * @return array{date:string, rows:int, pulseSlots:int, raisedHr:bool}
 */
function healthRebuildDay( string $pDate ): array {
	$day  = HealthDay::findOrCreate( $pDate );
	$rows = healthRebuildFetchDayRows( $pDate );

	$pulseSlots = healthRebuildDayPulse( $day->mContentId, $rows );
	$raisedHr   = healthRebuildDayRaisedHR( $day->mContentId, $pDate, $rows );

	return [ 'date' => $pDate, 'rows' => count( $rows ), 'pulseSlots' => $pulseSlots, 'raisedHr' => $raisedHr ];
}

/**
 * Rebuild every day that has at least one HEALTH_HR_RAW row, across the
 * table's full date range.
 *
 * @return array{daysProcessed:int, totalPulseSlots:int, totalRaisedHr:int, firstDate:?string, lastDate:?string}
 */
function healthRebuildAllDays(): array {
	global $gBitDb;
	$range = $gBitDb->getRow(
		"SELECT MIN(`start_time`) AS min_ts, MAX(`start_time`) AS max_ts FROM `".BIT_DB_PREFIX."health_hr_raw`"
	);
	if( !$range || !$range['min_ts'] ) {
		return [ 'daysProcessed' => 0, 'totalPulseSlots' => 0, 'totalRaisedHr' => 0, 'firstDate' => null, 'lastDate' => null ];
	}

	$tz  = new \DateTimeZone( 'Europe/London' );
	$utc = new \DateTimeZone( 'UTC' );
	$cursor = ( new \DateTime( $range['min_ts'], $utc ) )->setTimezone( $tz );
	$cursor->setTime( 0, 0, 0 );
	$last = ( new \DateTime( $range['max_ts'], $utc ) )->setTimezone( $tz );

	$daysProcessed   = 0;
	$totalPulseSlots = 0;
	$totalRaisedHr   = 0;
	$firstDate = $cursor->format( 'Y-m-d' );
	$lastDate  = null;

	while( $cursor <= $last ) {
		$date   = $cursor->format( 'Y-m-d' );
		$result = healthRebuildDay( $date );
		if( $result['rows'] > 0 ) {
			$daysProcessed++;
			$totalPulseSlots += $result['pulseSlots'];
			$totalRaisedHr   += $result['raisedHr'] ? 1 : 0;
			$lastDate = $date;
		}
		$cursor->modify( '+1 day' );
	}

	return [
		'daysProcessed'   => $daysProcessed,
		'totalPulseSlots' => $totalPulseSlots,
		'totalRaisedHr'   => $totalRaisedHr,
		'firstDate'       => $firstDate,
		'lastDate'        => $lastDate,
	];
}
