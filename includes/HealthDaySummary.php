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
 * "12" if $pMin/$pMax are equal (a single reading, or a slot average - no
 * real range to show), else "12–18" - shared formatting for BP's day-wide
 * range and used wherever else a min/max pair needs the same collapse-if-
 * equal treatment.
 */
function healthFormatRange( float $pMin, float $pMax ): string {
	$fmtMin = rtrim( rtrim( number_format( $pMin, 1 ), '0' ), '.' );
	$fmtMax = rtrim( rtrim( number_format( $pMax, 1 ), '0' ), '.' );
	return $fmtMin === $fmtMax ? $fmtMin : "$fmtMin\u{2013}$fmtMax";
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
 * SLEEP day-summary: every session that day, since a night can genuinely
 * have several (see ImportSleep.php's own docblock) - picking/aggregating
 * a single headline figure from multiple real sessions is a display
 * decision, not made here. Returns every session's own score/duration/
 * efficiency, longest-duration first.
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
