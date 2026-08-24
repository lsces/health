<?php
/**
 * Day-summary "headline" values, computed at query time rather than
 * stored - see health/MANUAL.md's Content model section for why (every
 * item is multiple=1, read-only, raw-per-reading rows; picking/reducing to
 * a single day figure is deliberately a display concern, not an import-time
 * ETL step). Each function takes a HealthDay content_id and returns
 * whatever single representative figure(s) make sense for that item, or
 * null if there's no data that day.
 *
 * @package health
 */

namespace Bitweaver\Health;

/**
 * WT day-summary: the lowest AM (before noon, Europe/London local) weight
 * reading, preferring one with a successful body-composition scan over one
 * without - per MANUAL.md's documented rule. AM/PM is checked in PHP
 * against the local zone, not a raw UTC hour cutoff, since start_date is
 * stored as a UTC instant and a GMT/BST shift can move a reading either
 * side of noon in one but not the other.
 *
 * @return array{weight:float,bmi:float,body_comp:array,start_date:string}|null
 */
function healthDaySummaryWT( int $pContentId ): ?array {
	global $gBitDb;
	$rows = $gBitDb->getAll(
		"SELECT `xkey`, `xkey_ext`, `data`, `start_date` FROM `".BIT_DB_PREFIX."liberty_xref`
			WHERE `content_id` = ? AND `item` = 'WT' ORDER BY `xkey` ASC",
		[ $pContentId ]
	);
	if( !$rows ) {
		return null;
	}

	$tz = new \DateTimeZone( 'Europe/London' );
	$amRows = [];
	foreach( $rows as $row ) {
		$local = ( new \DateTime( $row['start_date'], new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
		if( (int)$local->format( 'H' ) < 12 ) {
			$amRows[] = $row;
		}
	}
	if( !$amRows ) {
		return null; // no AM reading this day
	}

	// already sorted lowest-weight-first; prefer the first with real body-comp data
	foreach( $amRows as $row ) {
		$bodyComp = json_decode( (string)$row['data'], true ) ?: [];
		if( array_filter( $bodyComp ) ) {
			return [
				'weight'     => (float)$row['xkey'],
				'bmi'        => (float)$row['xkey_ext'],
				'body_comp'  => $bodyComp,
				'start_date' => $row['start_date'],
			];
		}
	}

	$row = $amRows[0];
	return [
		'weight'     => (float)$row['xkey'],
		'bmi'        => (float)$row['xkey_ext'],
		'body_comp'  => json_decode( (string)$row['data'], true ) ?: [],
		'start_date' => $row['start_date'],
	];
}

/**
 * BP day-summary: min/max/avg systolic, diastolic and pulse across every
 * reading that day - no AM/PM or source filtering, every BP reading
 * (HealthForYou + both Samsung sources) is already imported into the same
 * pool, see ImportBPSamsung.php's own docblock. Pulse lives in the `data`
 * json (not a plain column), so fetched raw and reduced in PHP rather than
 * via SQL aggregates - row counts per day are small (a handful), matching
 * the same all-rows-in-PHP approach healthDaySummaryWT() already uses.
 *
 * Also splits the day into three fixed local-time slots - pre-9AM
 * ('morning'), 9AM-4PM ('midday', normally sparse - Lester's post-physio
 * readings are the main real case), post-4PM ('evening') - each reduced to
 * its own average, not a range (a slot's whole point is "one representative
 * figure for that part of the day", unlike the day-wide min/max which is
 * meant to show the real spread). A slot with no readings that day is null,
 * not zeroed - the caller decides whether to render it at all.
 *
 * @return array{systolic:array{avg:float,min:float,max:float},
 *               diastolic:array{avg:float,min:float,max:float},
 *               pulse:?array{avg:float,min:float,max:float},
 *               count:int,
 *               slots:array<string,?array{systolic:float,diastolic:float,pulse:?float,count:int}>}|null
 */
function healthDaySummaryBP( int $pContentId ): ?array {
	global $gBitDb;
	$rows = $gBitDb->getAll(
		"SELECT `xkey`, `xkey_ext`, `data`, `start_date` FROM `".BIT_DB_PREFIX."liberty_xref`
			WHERE `content_id` = ? AND `item` = 'BP'",
		[ $pContentId ]
	);
	if( !$rows ) {
		return null;
	}

	$tz = new \DateTimeZone( 'Europe/London' );
	$slotRows = [ 'morning' => [], 'midday' => [], 'evening' => [] ];
	$sys = [];
	$dia = [];
	$pulse = [];

	foreach( $rows as $row ) {
		$s = (float)$row['xkey'];
		$d = (float)$row['xkey_ext'];
		$detail = json_decode( (string)$row['data'], true ) ?: [];
		$p = isset( $detail['pulse'] ) && $detail['pulse'] !== '' ? (float)$detail['pulse'] : null;

		$sys[] = $s;
		$dia[] = $d;
		if( $p !== null ) {
			$pulse[] = $p;
		}

		$local = ( new \DateTime( $row['start_date'], new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
		$hour  = (int)$local->format( 'H' );
		$slot  = $hour < 9 ? 'morning' : ( $hour < 16 ? 'midday' : 'evening' );
		$slotRows[$slot][] = [ 'sys' => $s, 'dia' => $d, 'pulse' => $p ];
	}

	$avg = fn( array $v ) => $v ? array_sum( $v ) / count( $v ) : null;

	$slots = [];
	foreach( $slotRows as $slot => $readings ) {
		if( !$readings ) {
			$slots[$slot] = null;
			continue;
		}
		$slotPulse = array_values( array_filter( array_column( $readings, 'pulse' ), fn( $v ) => $v !== null ) );
		$slots[$slot] = [
			'systolic'  => round( $avg( array_column( $readings, 'sys' ) ), 1 ),
			'diastolic' => round( $avg( array_column( $readings, 'dia' ) ), 1 ),
			'pulse'     => $slotPulse ? round( $avg( $slotPulse ), 1 ) : null,
			'count'     => count( $readings ),
		];
	}

	return [
		'systolic'  => [ 'avg' => round( $avg( $sys ), 1 ), 'min' => min( $sys ), 'max' => max( $sys ) ],
		'diastolic' => [ 'avg' => round( $avg( $dia ), 1 ), 'min' => min( $dia ), 'max' => max( $dia ) ],
		'pulse'     => $pulse ? [ 'avg' => round( $avg( $pulse ), 1 ), 'min' => min( $pulse ), 'max' => max( $pulse ) ] : null,
		'count'     => count( $rows ),
		'slots'     => $slots,
	];
}

/**
 * "12" not "12.0"/"12.3400" - one decimal place, trailing zeros (and a
 * trailing bare dot) trimmed off. Shared by every formatter below that
 * needs a single figure displayed, not just healthFormatRange()'s ranges.
 */
function healthFormatNumber( float $pVal ): string {
	return rtrim( rtrim( number_format( $pVal, 1 ), '0' ), '.' );
}

/**
 * "12" if $pMin/$pMax are equal (a single reading, or a slot average - no
 * real range to show), else "12–18" - shared formatting for BP's day-wide
 * range and used wherever else a min/max pair needs the same collapse-if-
 * equal treatment.
 */
function healthFormatRange( float $pMin, float $pMax ): string {
	$fmtMin = healthFormatNumber( $pMin );
	$fmtMax = healthFormatNumber( $pMax );
	return $fmtMin === $fmtMax ? $fmtMin : "$fmtMin\u{2013}$fmtMax";
}

/**
 * "6h 32m" (or "32m" under an hour) - not meant to be precise, just
 * readable; see healthFormatSleepLine()'s own docblock for why an
 * imprecise summed duration is good enough here.
 */
function healthFormatDuration( float $pMinutes ): string {
	$mins = (int)round( $pMinutes );
	$h = intdiv( $mins, 60 );
	$m = $mins % 60;
	return $h > 0 ? sprintf( '%dh %dm', $h, $m ) : sprintf( '%dm', $m );
}

/**
 * "132/84 (68)" or "125–140/78–92 (60–75)" - one BP line covering systolic/
 * diastolic (+ pulse if present), built from either healthDaySummaryBP()'s
 * top-level min/max (a real range across the day) or one of its per-slot
 * averages (single figures, passed as $pMin===$pMax so healthFormatRange()
 * collapses to one number) - same renderer, two calling shapes.
 */
function healthFormatBPLine( float $pSysMin, float $pSysMax, float $pDiaMin, float $pDiaMax, ?float $pPulseMin = null, ?float $pPulseMax = null ): string {
	$line = healthFormatRange( $pSysMin, $pSysMax ).'/'.healthFormatRange( $pDiaMin, $pDiaMax );
	if( $pPulseMin !== null && $pPulseMax !== null ) {
		$line .= ' ('.healthFormatRange( $pPulseMin, $pPulseMax ).')';
	}
	return $line;
}

/**
 * HRV day-summary: average sdnn/rmssd across every half-hour slot that
 * day. Only meaningful once PULSE-style HRV slots exist for the day - see
 * ImportHRV.php.
 *
 * @return array{sdnn_avg:float,rmssd_avg:float,slot_count:int}|null
 */
function healthDaySummaryHRV( int $pContentId ): ?array {
	global $gBitDb;
	$row = $gBitDb->getRow(
		"SELECT
			AVG(CAST(`xkey` AS DOUBLE PRECISION))     AS sdnn_avg,
			AVG(CAST(`xkey_ext` AS DOUBLE PRECISION)) AS rmssd_avg,
			COUNT(*) AS n
		 FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'HRV'",
		[ $pContentId ]
	);
	if( !$row || !$row['n'] ) {
		return null;
	}

	return [
		'sdnn_avg'   => round( (float)$row['sdnn_avg'], 1 ),
		'rmssd_avg'  => round( (float)$row['rmssd_avg'], 1 ),
		'slot_count' => (int)$row['n'],
	];
}

/**
 * "Score: 78, Duration: 6h 32m (2 sessions)" - the Summary tab's Sleep
 * line. Score comes from ENERGY's own `sleep_score` (vitality_score's
 * composite figure), NOT a pick/blend of SLEEP's own per-session scores -
 * Lester's own call: the SLEEP item's sessions record different, sometimes
 * overlapping periods through the night and their scores "aren't totally
 * reliable" as a single day figure. Duration IS still built from the real
 * SLEEP sessions though - summed across however many exist that day, even
 * though summing overlapping/adjacent sessions isn't strictly accurate
 * either - Lester's own explicit acceptance ("even if it isn't accurate")
 * of a rough duration over no duration at all. Returns null if there's
 * neither an ENERGY sleep_score nor any SLEEP session that day.
 */
function healthFormatSleepLine( ?float $pSleepScore, array $pSleepSessions ): ?string {
	$parts = [];
	if( $pSleepScore !== null ) {
		$parts[] = 'Score: '.healthFormatNumber( $pSleepScore );
	}
	if( $pSleepSessions ) {
		$totalMinutes = array_sum( array_column( $pSleepSessions, 'duration_minutes' ) );
		$n = count( $pSleepSessions );
		$parts[] = 'Duration: '.healthFormatDuration( $totalMinutes ).' ('.$n.' '.( $n === 1 ? 'session' : 'sessions' ).')';
	}
	return $parts ? implode( ', ', $parts ) : null;
}

/**
 * SLEEP day-summary: every session that day, since a night can genuinely
 * have several (see ImportSleep.php's own docblock) - picking/aggregating
 * a single headline figure from multiple real sessions is a display
 * decision, not made here (see healthFormatSleepLine() for the Summary
 * tab's own reduction, which uses ENERGY's score instead of these anyway).
 * Returns every session's own score/duration/efficiency, longest-duration
 * first.
 *
 * @return array<int, array{score:float,duration_minutes:float,efficiency:?float}>
 */
function healthDaySummarySleep( int $pContentId ): array {
	global $gBitDb;
	$rows = $gBitDb->getAll(
		"SELECT `xkey`, `xkey_ext`, `data` FROM `".BIT_DB_PREFIX."liberty_xref`
			WHERE `content_id` = ? AND `item` = 'SLEEP' ORDER BY CAST(`xkey_ext` AS DOUBLE PRECISION) DESC",
		[ $pContentId ]
	);
	$sessions = [];
	foreach( $rows as $row ) {
		$detail = json_decode( (string)$row['data'], true ) ?: [];
		$sessions[] = [
			'score'            => (float)$row['xkey'],
			'duration_minutes' => (float)$row['xkey_ext'],
			'efficiency'       => isset( $detail['efficiency'] ) ? (float)$detail['efficiency'] : null,
		];
	}
	return $sessions;
}

/**
 * ENERGY day-summary: already one row per day at import time (see
 * ImportEnergy.php), so this is just a plain fetch, no reduction needed.
 * Its four figures cover the Summary tab's best answer for four different
 * rows, not just its own "Energy" line (Lester's own framing, 2026-08-24):
 * `total_score` is Energy, `shrv_value` is HRV, and `detail`'s
 * `sleep_score`/`activity_score` feed the Sleep/Steps lines respectively
 * (see healthFormatSleepLine()/healthFormatStepsLine()) - ENERGY turns out
 * to be the richest single source for day-level headline figures, even for
 * items that also have their own dedicated xref item.
 *
 * @return array{total_score:float,shrv_value:float,detail:array}|null
 */
function healthDaySummaryEnergy( int $pContentId ): ?array {
	global $gBitDb;
	$row = $gBitDb->getRow(
		"SELECT `xkey`, `xkey_ext`, `data` FROM `".BIT_DB_PREFIX."liberty_xref`
			WHERE `content_id` = ? AND `item` = 'ENERGY'",
		[ $pContentId ]
	);
	if( !$row ) {
		return null;
	}

	return [
		'total_score' => (float)$row['xkey'],
		'shrv_value'  => (float)$row['xkey_ext'],
		'detail'      => json_decode( (string)$row['data'], true ) ?: [],
	];
}

/**
 * STEPS day-summary: already one row per day at import time (see
 * ImportSteps.php), so this is just a plain fetch, no reduction needed.
 *
 * @return array{count:int,active_mins:float,active_kcal:float}|null
 */
function healthDaySummarySteps( int $pContentId ): ?array {
	global $gBitDb;
	$row = $gBitDb->getRow(
		"SELECT `xkey`, `xkey_ext`, `data` FROM `".BIT_DB_PREFIX."liberty_xref`
			WHERE `content_id` = ? AND `item` = 'STEPS'",
		[ $pContentId ]
	);
	if( !$row ) {
		return null;
	}
	$detail = json_decode( (string)$row['data'], true ) ?: [];

	return [
		'count'       => (int)$row['xkey'],
		'active_mins' => (float)$row['xkey_ext'],
		'active_kcal' => (float)( $detail['active_kcal'] ?? 0 ),
	];
}

/**
 * "Count: 8321, Mins: 45, Kcal: 320, Activity: 78" - Steps' own count/
 * active-mins/active-kcal plus ENERGY's activity_score folded onto the
 * same line, rather than Activity getting a row of its own - Lester's own
 * call, 2026-08-24. Either half can be missing (a day with STEPS but no
 * ENERGY row, or vice versa) - whichever parts exist are shown, nothing
 * shown as zero. Returns null if there's nothing at all to show. Shared by
 * both the Summary tab and the Calendar day-cell, so the two always agree.
 */
function healthFormatStepsLine( ?array $pSteps, ?float $pActivityScore ): ?string {
	$parts = [];
	if( $pSteps ) {
		$parts[] = 'Count: '.number_format( $pSteps['count'] );
		$parts[] = 'Mins: '.healthFormatNumber( $pSteps['active_mins'] );
		$parts[] = 'Kcal: '.healthFormatNumber( $pSteps['active_kcal'] );
	}
	if( $pActivityScore !== null ) {
		$parts[] = 'Activity: '.healthFormatNumber( $pActivityScore );
	}
	return $parts ? implode( ', ', $parts ) : null;
}
