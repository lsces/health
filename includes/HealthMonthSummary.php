<?php
/**
 * Month-by-month aggregates for report_month.php - the longer-view companion to the
 * per-day/weekly summaries in HealthDaySummary.php. One query per item over the whole
 * range (not a query per day), reduced to a handful of figures per calendar month.
 *
 * Months are taken from each HealthDay's own title (its local ISO date), so a reading is
 * always filed under the local day the rest of the package already uses, never a UTC
 * month boundary. The only epoch-to-clock conversion is the night/day split of PULSE
 * slots, done in the caller's timezone.
 *
 * "Floor" = a half-hour PULSE slot whose lowest minute reading is at or below the watch's
 * 30 bpm measuring floor (it cannot report lower, so such a slot means "30 or less", not
 * "exactly 30"). A day only counts towards the floor percentage when the watch recorded
 * enough slots that day for a missing floor to mean something (HEALTH_MONTH_MIN_SLOTS).
 * Counts are of days, not of readings - the question the report answers is "how many days
 * did it happen", so a single long dip and several short ones on one day count once.
 *
 * @package health
 */

namespace Bitweaver\Health;

require_once __DIR__.'/HealthDaySummary.php';

defined( 'HEALTH_WATCH_FLOOR_BPM' )   || define( 'HEALTH_WATCH_FLOOR_BPM', 30 );
defined( 'HEALTH_MONTH_MIN_SLOTS' )   || define( 'HEALTH_MONTH_MIN_SLOTS', 15 );
defined( 'HEALTH_NIGHT_END_HOUR' )    || define( 'HEALTH_NIGHT_END_HOUR', 6 );
// A gap between two sleep sessions counts as a real break in the night when it is at least this many
// minutes and begins overnight (23:00-07:00); shorter gaps are the watch restarting a session.
defined( 'HEALTH_NIGHT_GAP_MINS' )    || define( 'HEALTH_NIGHT_GAP_MINS', 20 );

/**
 * @param  array $pValues  numbers, any order.
 * @return float|null      middle value (mean of the middle two for an even count), null if empty.
 */
function healthMedian( array $pValues ): ?float {
	if( !$pValues ) {
		return null;
	}
	sort( $pValues );
	$n   = count( $pValues );
	$mid = intdiv( $n, 2 );
	return $n % 2 ? (float)$pValues[$mid] : ( $pValues[$mid - 1] + $pValues[$mid] ) / 2;
}

/**
 * @param  array $pValues  numbers.
 * @return float|null      arithmetic mean, null if empty.
 */
function healthMean( array $pValues ): ?float {
	return $pValues ? array_sum( $pValues ) / count( $pValues ) : null;
}

/**
 * Decimal-place formatting that leaves a missing figure blank rather than "0".
 */
function healthFormatMonthFigure( ?float $pVal, int $pDecimals = 1 ): string {
	return $pVal === null ? '' : number_format( $pVal, $pDecimals, '.', '' );
}

/**
 * Per-month figures for every calendar month touched by [$pFrom, $pTo].
 *
 * @param  string        $pFrom  ISO date; the whole month it falls in is included.
 * @param  string        $pTo    ISO date; the whole month it falls in is included.
 * @param  \DateTimeZone $pTz    zone for the night (00:00-06:00) / day split of PULSE slots.
 * @return array<string,array>   keyed 'YYYY-MM', ascending; every key present even when a month has no data.
 */
function healthMonthSummary( string $pFrom, string $pTo, \DateTimeZone $pTz ): array {
	global $gBitDb;

	$first = substr( $pFrom, 0, 7 ).'-01';
	$last  = ( new \DateTime( substr( $pTo, 0, 7 ).'-01' ) )->modify( 'last day of this month' )->format( 'Y-m-d' );

	$months = [];
	for( $cursor = new \DateTime( $first ); $cursor->format( 'Y-m-d' ) <= $last; $cursor->modify( '+1 month' ) ) {
		$months[$cursor->format( 'Y-m' )] = [
			'pulse' => [], 'night' => [], 'slotDays' => [], 'floorDays' => [],
			'sdnn' => [], 'peak' => [], 'floorSlots' => 0, 'gapNights' => 0, 'gapMins' => [], 'mins100' => [], 'days130' => 0, 'raisedDays' => 0,
			'sys' => [], 'dia' => [], 'cuffPulse' => [],
			'desatMins90' => [], 'desatLow' => [], 'desatCoverage' => [],
			'steps' => [], 'weight' => [],
		];
	}

	$fetch = function( string $pItem, string $pColumns ) use ( $gBitDb, $first, $last ): array {
		return $gBitDb->getAll(
			"SELECT lc.`title`, $pColumns FROM `".BIT_DB_PREFIX."liberty_xref` x
				JOIN `".BIT_DB_PREFIX."liberty_content` lc ON ( lc.`content_id` = x.`content_id` )
				WHERE lc.`content_type_guid` = 'healthday' AND x.`item` = ?
				  AND lc.`title` BETWEEN ? AND ?",
			[ $pItem, $first, $last ]
		) ?: [];
	};
	$monthOf = fn( string $pTitle ): string => substr( $pTitle, 0, 7 );

	// PULSE: slot average -> mean/night mean; slot low -> floor days. slotsByDay collects counts so a
	// day with only a few slots (watch mostly off the wrist) can't count as "no floor".
	$clock       = new \DateTime( 'now', $pTz );
	$slotsByDay  = [];
	$floorByDay  = [];
	foreach( $fetch( 'PULSE', 'x.`xkey`, x.`xkey_ext`, x.`start_date`' ) as $row ) {
		$m = $monthOf( $row['title'] );
		if( !isset( $months[$m] ) ) {
			continue;
		}
		$avg = (float)$row['xkey'];
		$months[$m]['pulse'][] = $avg;
		$clock->setTimestamp( (int)$row['start_date'] );
		if( (int)$clock->format( 'G' ) < HEALTH_NIGHT_END_HOUR ) {
			$months[$m]['night'][] = $avg;
		}
		$slotsByDay[$row['title']] = ( $slotsByDay[$row['title']] ?? 0 ) + 1;
		$low = json_decode( (string)$row['xkey_ext'], true )['low'] ?? null;
		if( $low !== null && (float)$low <= HEALTH_WATCH_FLOOR_BPM ) {
			$floorByDay[$row['title']] = true;
			$months[$m]['floorSlots']++;
		}
	}
	foreach( $slotsByDay as $title => $slots ) {
		if( $slots >= HEALTH_MONTH_MIN_SLOTS ) {
			$m = $monthOf( $title );
			$months[$m]['slotDays'][$title] = true;
			if( isset( $floorByDay[$title] ) ) {
				$months[$m]['floorDays'][$title] = true;
			}
		}
	}

	// HRV: SDNN per half-hour slot, monthly median (the mean is dragged by the occasional huge slot).
	foreach( $fetch( 'HRV', 'x.`xkey`' ) as $row ) {
		$months[$monthOf( $row['title'] )]['sdnn'][] = (float)$row['xkey'];
	}

	// RAISEDHR: one row per day - time at/over 100 bpm, whether it reached 130, and the day's peak.
	foreach( $fetch( 'RAISEDHR', 'x.`data`' ) as $row ) {
		$d = json_decode( (string)$row['data'], true );
		if( !is_array( $d ) ) {
			continue;
		}
		$m = $monthOf( $row['title'] );
		$months[$m]['raisedDays']++;
		$months[$m]['mins100'][] = (float)( $d['exercise_mins_100'] ?? 0 ) + (float)( $d['background_mins_100'] ?? 0 );
		if( (float)( $d['mins_130'] ?? 0 ) > 0 ) {
			$months[$m]['days130']++;
		}
		if( isset( $d['hr_max'] ) ) {
			$months[$m]['peak'][] = (float)$d['hr_max'];
		}
	}

	// BP: cuff readings only - the watch's PPG estimate is deliberately not mixed in (see MANUAL.md).
	foreach( $fetch( 'BP', 'x.`xkey`, x.`xkey_ext`, x.`data`' ) as $row ) {
		$d = json_decode( (string)$row['data'], true ) ?: [];
		if( ( $d['source'] ?? '' ) !== 'cuff' ) {
			continue;
		}
		$m = $monthOf( $row['title'] );
		$months[$m]['sys'][] = (float)$row['xkey'];
		$months[$m]['dia'][] = (float)$row['xkey_ext'];
		if( isset( $d['pulse'] ) && $d['pulse'] !== '' ) {
			$months[$m]['cuffPulse'][] = (float)$d['pulse'];
		}
	}

	// OXIDESAT: one row per watch sleep session, but a night is often recorded as two or more (a split
	// session, a short early-morning fragment, an evening nap), and filing by the session's own day would
	// put a night's tail on the next day. So sessions are grouped into noon-to-noon nights first, then
	// each night is reduced to one figure: minutes under 90% summed, lowest reading the night's minimum,
	// minutes measured summed (low coverage means the other figures understate the night).
	$nights = [];
	foreach( $fetch( 'OXIDESAT', 'x.`xkey`, x.`start_date`, x.`data`' ) as $row ) {
		$d = json_decode( (string)$row['data'], true ) ?: [];
		$clock->setTimestamp( (int)$row['start_date'] - 12 * 3600 );
		$night = $clock->format( 'Y-m-d' );
		$nights[$night] ??= [ 'mins90' => 0.0, 'low' => null, 'coverage' => 0.0, 'sessions' => [] ];
		$nights[$night]['sessions'][] = [ (int)$row['start_date'], (float)( $d['session_mins'] ?? 0 ) ];
		$nights[$night]['mins90']   += (float)$row['xkey'];
		$nights[$night]['coverage'] += (float)( $d['coverage_mins'] ?? 0 );
		if( isset( $d['low_value'] ) ) {
			$nights[$night]['low'] = $nights[$night]['low'] === null ? (float)$d['low_value'] : min( $nights[$night]['low'], (float)$d['low_value'] );
		}
	}
	foreach( $nights as $night => $n ) {
		$m = $monthOf( $night );
		if( !isset( $months[$m] ) ) {
			continue;
		}
		// Breaks between this night's sessions: end of one to start of the next, only those beginning overnight.
		usort( $n['sessions'], fn( $a, $b ) => $a[0] <=> $b[0] );
		$gaps = [];
		for( $i = 0; $i < count( $n['sessions'] ) - 1; $i++ ) {
			$end = $n['sessions'][$i][0] + (int)round( $n['sessions'][$i][1] * 60 );
			$gap = ( $n['sessions'][$i + 1][0] - $end ) / 60;
			$clock->setTimestamp( $end );
			$hour = (int)$clock->format( 'G' );
			if( $gap >= HEALTH_NIGHT_GAP_MINS && ( $hour >= 23 || $hour < 7 ) ) {
				$gaps[] = $gap;
			}
		}
		if( $gaps ) {
			$months[$m]['gapNights']++;
			$months[$m]['gapMins'][] = array_sum( $gaps );
		}
		$months[$m]['desatMins90'][]    = $n['mins90'];
		$months[$m]['desatCoverage'][]  = $n['coverage'];
		if( $n['low'] !== null ) {
			$months[$m]['desatLow'][] = $n['low'];
		}
	}

	foreach( $fetch( 'STEPS', 'x.`xkey`' ) as $row ) {
		$months[$monthOf( $row['title'] )]['steps'][] = (float)$row['xkey'];
	}

	// Weight: the package's own day headline (lowest valid AM reading), not a raw mean of every reading.
	$weightDays = $gBitDb->getAll(
		"SELECT DISTINCT lc.`title`, lc.`content_id` FROM `".BIT_DB_PREFIX."liberty_xref` x
			JOIN `".BIT_DB_PREFIX."liberty_content` lc ON ( lc.`content_id` = x.`content_id` )
			WHERE lc.`content_type_guid` = 'healthday' AND x.`item` = 'WT'
			  AND lc.`title` BETWEEN ? AND ?",
		[ $first, $last ]
	) ?: [];
	foreach( $weightDays as $day ) {
		if( $wt = healthDaySummaryWT( (int)$day['content_id'] ) ) {
			$months[$monthOf( $day['title'] )]['weight'][] = (float)$wt['weight'];
		}
	}

	$out = [];
	foreach( $months as $key => $m ) {
		$counted = count( $m['slotDays'] );
		$floor   = count( $m['floorDays'] );
		$out[$key] = [
			'month'        => $key,
			'days'         => $counted,
			'floorDays'    => $counted ? $floor : null,
			'floorPct'     => $counted ? 100 * $floor / $counted : null,
			'pulse'        => healthMean( $m['pulse'] ),
			'night'        => healthMean( $m['night'] ),
			'peak'         => healthMean( $m['peak'] ),
			'peakMax'      => $m['peak'] ? max( $m['peak'] ) : null,
			'days130'      => $m['raisedDays'] ? $m['days130'] : null,
			'mins100'      => healthMean( $m['mins100'] ),
			'sdnn'         => healthMedian( $m['sdnn'] ),
			'cuffCount'    => count( $m['sys'] ),
			'sys'          => healthMean( $m['sys'] ),
			'dia'          => healthMean( $m['dia'] ),
			'cuffPulse'    => healthMean( $m['cuffPulse'] ),
			'floorSlots'   => $m['floorSlots'],
			'gapNights'    => $m['gapNights'],
			'gapMean'      => healthMean( $m['gapMins'] ),
			'desatNights'  => count( $m['desatMins90'] ),
			'desatMins90'  => healthMean( $m['desatMins90'] ),
			'desatLow'     => healthMean( $m['desatLow'] ),
			'desatCoverage'=> healthMean( $m['desatCoverage'] ),
			'desatPct'     => ( $m['desatCoverage'] && array_sum( $m['desatCoverage'] ) > 0 ) ? 100 * array_sum( $m['desatMins90'] ) / array_sum( $m['desatCoverage'] ) : null,
			'weight'       => healthMean( $m['weight'] ),
			'steps'        => healthMean( $m['steps'] ),
		];
	}
	return $out;
}
