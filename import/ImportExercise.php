<?php
/**
 * Import EXERCISE xref rows from Samsung Health's exercise export.
 *
 * Expects `com.samsung.shealth.exercise.<date>.csv` in HEALTH_IMPORT_PATH
 * (storage/health/) — copy it from a `health_lester_<date>` split. Only the
 * session-summary columns are read here; the same file's embedded
 * `live_data` heart-rate trace is a separate concern already handled by
 * ImportHRRaw.php/ImportRaisedHR.php — no overlap, this importer never
 * touches `live_data`.
 *
 * One row per exercise *session* — same "don't reduce at import time"
 * principle as SLEEP/WT/BP/PULSE.
 *
 * A session is assigned to the **local (Europe/London) calendar date its own
 * start_time falls on** — same rule as ImportSleep.php.
 *
 * **BST/GMT handling**: `start_time` is parsed as UTC, not Europe/London —
 * same fix already applied to ImportSleep.php/ImportBPSamsung.php. Samsung's
 * `start_time` is already the UTC-equivalent value; parsing with
 * Europe/London here would double-subtract the BST hour.
 *
 * **`xkey_ext` is the raw clock-span duration (`end_time - start_time`),
 * deliberately not Samsung's own `duration` field.** Confirmed against real
 * data: ~32% of sessions have a clock-span that disagrees with Samsung's own
 * `duration` by more than 10 minutes (sessions left running after the user
 * forgot to stop them — some by hours), while Samsung's own `duration`
 * stays plausible right through those outliers. The raw (sometimes wrong)
 * clock-span is stored anyway, on purpose — a bad value here is exactly
 * what should surface later via the xref history/archive mechanism
 * (`LibertyXref::stepXref()`), not something to silently correct at import
 * time. Samsung's own `duration` is kept in `edit` as the trustworthy
 * cross-check instead.
 *
 * `xkey`=healthExerciseTypeLabel() text (Walk/Physio/Untagged) - resolved at
 * import time, not display time: the generic xref view template
 * (liberty/templates/xref/view_key-json-detail_item.tpl) has no hook for a
 * per-item value lookup, it only shows xkey as stored. The raw Samsung
 * exercise_type code is kept in `data` as `type_code` for reference.
 * `xkey_ext`=clock-span duration in minutes,
 * `data`=json `{type_code, duration_min (Samsung's own value, converted
 * from ms), source_type, calorie, distance, mean_heart_rate,
 * max_heart_rate, min_heart_rate, count, title}`.
 *
 * @package health
 */

require_once __DIR__.'/ImportPulse.php'; // healthParseSamsungCsv(), healthFindLatestSamsungCsv()

use Bitweaver\Health\HealthDay;
use Bitweaver\Liberty\LibertyXref;

/**
 * Text label for an EXERCISE type code — written into xkey at import time
 * (see healthStoreExercise()). `12001` is Lester's own account-specific
 * custom-exercise id (Samsung assigns these per account when a custom
 * exercise is created) currently meaning "Knee Physio" — it is NOT a
 * documented standard Samsung code like `1001` is, and will only keep
 * meaning Physio as long as no second custom exercise is ever defined.
 * Every other code (confirmed in real data: `0`, `1002`, `11007`, plus any
 * future unrecognised code) deliberately merges to "Untagged" rather than
 * growing a long one-off lookup table.
 *
 * @param  string $pType Raw exercise_type code.
 * @return string
 */
function healthExerciseTypeLabel( string $pType ): string {
	switch( $pType ) {
		case '1001':
			return 'Walk';
		case '12001':
			return 'Physio';
		default:
			return 'Untagged';
	}
}

/**
 * Insert an EXERCISE xref row for one session, unless one already exists
 * for this exact content_id + start_date.
 *
 * @param  int    $pContentId       The day's HealthDay content_id.
 * @param  int    $pTimestamp       Unix timestamp of the session start (UTC).
 * @param  string $pType            Raw exercise_type code — resolved to a label for xkey,
 *                                  kept as-is in $pDetail['type_code'] for reference.
 * @param  float  $pClockSpanMinutes  end_time - start_time, in minutes.
 * @param  array  $pDetail
 * @return bool  TRUE if a new row was inserted, FALSE if one already existed (skipped).
 */
function healthStoreExercise( int $pContentId, int $pTimestamp, string $pType, float $pClockSpanMinutes, array $pDetail ): bool {
	global $gBitDb;

	$startDate = gmdate( 'Y-m-d H:i:s', $pTimestamp );
	$existing = $gBitDb->getOne(
		"SELECT `xref_id` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'EXERCISE' AND `start_date` = ?",
		[ $pContentId, $startDate ]
	);
	if( $existing ) {
		return false;
	}

	$nextXorder = (int)$gBitDb->getOne(
		"SELECT COALESCE( MAX(`xorder`) + 1, 0 ) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'EXERCISE'",
		[ $pContentId ]
	);

	$pDetail = [ 'type_code' => $pType ] + $pDetail;

	$pHash = [
		'content_id' => $pContentId,
		'item'       => 'EXERCISE',
		'xorder'     => $nextXorder,
		'xkey'       => healthExerciseTypeLabel( $pType ),
		'xkey_ext'   => (string)round( $pClockSpanMinutes, 2 ),
		'edit'       => json_encode( $pDetail ),
		'start_date' => $pTimestamp,
	];
	$xref = new LibertyXref();
	$xref->store( $pHash );
	return true;
}

/**
 * Run the full exercise import.
 *
 * @param  string $pCsvFile
 * @return array{created:int,skipped:int,errors:string[]}
 */
function healthImportExercise( string $pCsvFile ): array {
	$result = [ 'created' => 0, 'skipped' => 0, 'errors' => [] ];

	if( !is_readable( $pCsvFile ) ) {
		$result['errors'][] = "Can't read $pCsvFile";
		return $result;
	}

	$tz = new \DateTimeZone( 'UTC' );
	$P  = 'com.samsung.health.exercise.';

	foreach( healthParseSamsungCsv( $pCsvFile ) as $rowNum => $row ) {
		$startStr = $row[$P.'start_time'] ?? '';
		if( !$startStr ) {
			$result['errors'][] = "Row $rowNum: no start_time";
			continue;
		}
		try {
			$start = new \DateTime( $startStr, $tz );
		} catch( \Exception $e ) {
			$result['errors'][] = "Row $rowNum: unparseable start_time '$startStr'";
			continue;
		}

		$type = $row[$P.'exercise_type'] ?? '';
		if( $type === '' ) {
			$result['errors'][] = "Row $rowNum: no exercise_type";
			continue;
		}

		$clockSpanMinutes = 0.0;
		$endStr = $row[$P.'end_time'] ?? '';
		if( $endStr ) {
			try {
				$end = new \DateTime( $endStr, $tz );
				$clockSpanMinutes = ( $end->getTimestamp() - $start->getTimestamp() ) / 60;
			} catch( \Exception $e ) {
				$result['errors'][] = "Row $rowNum: unparseable end_time '$endStr', xkey_ext set to 0";
			}
		} else {
			$result['errors'][] = "Row $rowNum: no end_time, xkey_ext set to 0";
		}

		$samsungDurationMs = (float)( $row[$P.'duration'] ?? 0 );

		$detail = [
			'duration_min'    => round( $samsungDurationMs / 60000, 2 ), // Samsung's duration column is milliseconds
			'source_type'     => $row['source_type'] ?? '',
			'calorie'         => (float)( $row[$P.'calorie'] ?? 0 ),
			'distance'        => (float)( $row[$P.'distance'] ?? 0 ),
			'mean_heart_rate' => (float)( $row[$P.'mean_heart_rate'] ?? 0 ),
			'max_heart_rate'  => (float)( $row[$P.'max_heart_rate'] ?? 0 ),
			'min_heart_rate'  => (float)( $row[$P.'min_heart_rate'] ?? 0 ),
			'count'           => (float)( $row[$P.'count'] ?? 0 ),
		];
		if( !empty( $row['title'] ) ) {
			$detail['title'] = $row['title'];
		}

		$date = $start->format( 'Y-m-d' );
		$day  = HealthDay::findOrCreate( $date );
		if( healthStoreExercise( $day->mContentId, $start->getTimestamp(), $type, $clockSpanMinutes, $detail ) ) {
			$result['created']++;
		} else {
			$result['skipped']++;
		}
	}

	return $result;
}
