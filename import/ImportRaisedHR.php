<?php
/**
 * Import RAISEDHR (minutes with heart rate above two personal thresholds,
 * per day) from **two** Samsung Health sources combined.
 *
 * This is the legacy spreadsheet's "Raised HR" column (fghi tier), deferred
 * back when STEPS was built ("no source found" — see ImportSteps.php).
 *
 * **Two sources, not one — first cut (same session, background-only) was
 * wrong.** PULSE's background tracker.heart_rate source alone misses a
 * day's actual exercise entirely: Samsung's watch stops recording rich
 * background bins *during* an active exercise session (confirmed real gap
 * 2025-03-17: PULSE slots 00:00–06:30 then nothing until 22:30, with a
 * known 12:06–13:28 exercise session sitting squarely in that gap) —
 * presumably because the exercise-specific `live_data` stream takes over
 * while a session is active. The exercise export's own `live_data` covers
 * that window at ~1s resolution, but only exists when the exercise-tracking
 * app was actually started (the opposite gap — see this file's own git
 * history for the physio-session-with-no-app-start case that motivated
 * moving off the exercise source in the first place). Neither alone is
 * sufficient; this combines both:
 *   - **Exercise `live_data`** for the time window each real session covers.
 *   - **PULSE's background binning_data** for everything else that day —
 *     any background bin whose own timestamp falls inside an already-
 *     counted exercise window is skipped, to avoid double-counting the
 *     same minute twice.
 *
 * **Not** Samsung's own exercise.hr_zone thresholds (139/150/164 — generic
 * sports-training zones, would read ~0 minutes almost every day). Real
 * basis is medical/personal: GP-approved is >90bpm, Lester's own working
 * target is >100bpm — both computed side by side for direct comparison.
 *
 * Expects, in HEALTH_IMPORT_PATH (storage/health/):
 *   com.samsung.shealth.tracker.heart_rate.<date>.csv + its jsons/ blobs (PULSE's source)
 *   com.samsung.shealth.exercise.<date>.csv + its jsons/ blobs (exercise live_data)
 * (copy all four from a health_lester_<date> split). Reuses ImportPulse.php's
 * shared Samsung CSV/binning helpers.
 *
 * **Gap caps differ by source, not one constant for both** — exercise
 * live_data samples run ~1s apart (p95 1.003s, confirmed against real data),
 * background tracker.heart_rate bins run a clean 60s apart. Using the same
 * cap for both was the first cut's second bug: a cap sized for 1s data
 * silently halved every background-bin contribution once applied there
 * instead. HEALTH_RAISEDHR_EXERCISE_GAP_CAP (10s) and
 * HEALTH_RAISEDHR_BACKGROUND_GAP_CAP (90s) are each sized with real margin
 * over their own source's actual spacing, not shared.
 *
 * One RAISEDHR xref row per day: xkey = minutes >=90 (both sources
 * combined), xkey_ext = minutes >=100, data = {exercise_mins_90,
 * exercise_mins_100, background_mins_90, background_mins_100,
 * exercise_sample_count, background_sample_count} so the split stays
 * visible, not just the combined total. Safe to re-run: dedupes on
 * (content_id, item, start_date), start_date being that day's own local
 * midnight.
 *
 * @package health
 */

require_once __DIR__.'/ImportPulse.php'; // shared Samsung CSV/binning helpers

use Bitweaver\Health\HealthDay;
use Bitweaver\Liberty\LibertyXref;

const HEALTH_RAISEDHR_EXERCISE_GAP_CAP   = 10;  // seconds; live_data runs ~1s apart
const HEALTH_RAISEDHR_BACKGROUND_GAP_CAP = 90;  // seconds; tracker.heart_rate bins run 60s apart
const HEALTH_RAISEDHR_THRESHOLD_LOW      = 90.0;  // GP-approved
const HEALTH_RAISEDHR_THRESHOLD_HIGH     = 100.0; // Lester's own working target
const HEALTH_RAISEDHR_THRESHOLD_TOP      = 130.0; // third tier, detail-only (not a headline xkey/xkey_ext slot)

/**
 * Insert a RAISEDHR xref row for one day, unless one already exists for
 * this exact content_id + start_date.
 *
 * @param  int   $pContentId  The day's HealthDay content_id.
 * @param  int   $pDayStart   Unix timestamp of local midnight (UTC instant).
 * @param  float $pMinsLow    Minutes with HR >= HEALTH_RAISEDHR_THRESHOLD_LOW (both sources).
 * @param  float $pMinsHigh   Minutes with HR >= HEALTH_RAISEDHR_THRESHOLD_HIGH (both sources).
 * @param  array $pDetail
 * @return bool  TRUE if a new row was inserted, FALSE if one already existed (skipped).
 */
function healthStoreRaisedHR( int $pContentId, int $pDayStart, float $pMinsLow, float $pMinsHigh, array $pDetail ): bool {
	global $gBitDb;

	$startDate = gmdate( 'Y-m-d H:i:s', $pDayStart );
	$existing = $gBitDb->getOne(
		"SELECT `xref_id` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'RAISEDHR' AND `start_date` = ?",
		[ $pContentId, $startDate ]
	);
	if( $existing ) {
		return false;
	}

	$pHash = [
		'content_id' => $pContentId,
		'item'       => 'RAISEDHR',
		'xorder'     => 0,
		'xkey'       => (string)round( $pMinsLow, 1 ),
		'xkey_ext'   => (string)round( $pMinsHigh, 1 ),
		'edit'       => json_encode( $pDetail ),
		'start_date' => $pDayStart,
	];
	$xref = new LibertyXref();
	$xref->store( $pHash );
	return true;
}

/**
 * Duration-weighted minutes with heart_rate >= $pThreshold across a
 * time-sorted list of {heart_rate, start_time (unix seconds)} samples,
 * gap-capped so a real dropout isn't counted as raised time.
 *
 * @param  array $pSamples   Sorted by start_time ascending.
 * @param  float $pThreshold
 * @param  int   $pGapCapSeconds
 * @return float  Minutes.
 */
function healthRaisedHRMinutes( array $pSamples, float $pThreshold, int $pGapCapSeconds ): float {
	$seconds = 0;
	$n = count( $pSamples );
	for( $i = 0; $i < $n - 1; $i++ ) {
		if( $pSamples[$i]['heart_rate'] < $pThreshold ) {
			continue;
		}
		$gap = $pSamples[$i + 1]['start_time'] - $pSamples[$i]['start_time'];
		$seconds += min( $gap, $pGapCapSeconds );
	}
	return $seconds / 60;
}

/**
 * Parse the exercise export's own live_data for every session, returning
 * per-day exercise-window HR contributions plus the [start,end] windows
 * themselves (for the background pass to exclude).
 *
 * @param  string $pCsvFile
 * @param  string $pJsonBaseDir
 * @return array{byDay: array<string, array{minsLow:float, minsHigh:float,
 *               sampleCount:int, windows: array<int, array{0:int,1:int}>}>,
 *               rowsNoHrData:int, errors:string[]}
 */
function healthRaisedHRParseExercise( string $pCsvFile, string $pJsonBaseDir ): array {
	$byDay = [];
	$rowsNoHrData = 0;
	$errors = [];

	if( !is_readable( $pCsvFile ) ) {
		return [ 'byDay' => $byDay, 'rowsNoHrData' => 0, 'errors' => [ "Can't read $pCsvFile" ] ];
	}

	$tz = new \DateTimeZone( 'Europe/London' );
	$P  = 'com.samsung.health.exercise.';

	foreach( healthParseSamsungCsv( $pCsvFile ) as $row ) {
		$filename = trim( $row[$P.'live_data'] ?? '' );
		$startStr = $row[$P.'start_time'] ?? '';
		if( $filename === '' || $startStr === '' ) {
			$rowsNoHrData++;
			continue;
		}

		$raw = healthLoadBinningData( $pJsonBaseDir, $filename );
		$samples = [];
		foreach( $raw as $s ) {
			if( isset( $s['heart_rate'], $s['start_time'] ) && (float)$s['heart_rate'] > 0 ) {
				$samples[] = [ 'heart_rate' => (float)$s['heart_rate'], 'start_time' => intdiv( (int)$s['start_time'], 1000 ) ];
			}
		}
		if( count( $samples ) < 2 ) {
			$rowsNoHrData++;
			continue;
		}
		usort( $samples, fn( $a, $b ) => $a['start_time'] <=> $b['start_time'] );

		try {
			$start = new \DateTime( $startStr, $tz );
		} catch( \Exception $e ) {
			$errors[] = "Unparseable exercise start_time '$startStr'";
			continue;
		}
		$date = $start->format( 'Y-m-d' );

		$minsLow  = healthRaisedHRMinutes( $samples, HEALTH_RAISEDHR_THRESHOLD_LOW, HEALTH_RAISEDHR_EXERCISE_GAP_CAP );
		$minsHigh = healthRaisedHRMinutes( $samples, HEALTH_RAISEDHR_THRESHOLD_HIGH, HEALTH_RAISEDHR_EXERCISE_GAP_CAP );
		$minsTop  = healthRaisedHRMinutes( $samples, HEALTH_RAISEDHR_THRESHOLD_TOP, HEALTH_RAISEDHR_EXERCISE_GAP_CAP );

		$byDay[$date]['minsLow']  = ( $byDay[$date]['minsLow']  ?? 0 ) + $minsLow;
		$byDay[$date]['minsHigh'] = ( $byDay[$date]['minsHigh'] ?? 0 ) + $minsHigh;
		$byDay[$date]['minsTop']  = ( $byDay[$date]['minsTop']  ?? 0 ) + $minsTop;
		$byDay[$date]['sampleCount'] = ( $byDay[$date]['sampleCount'] ?? 0 ) + count( $samples );
		$byDay[$date]['windows'][] = [ $samples[0]['start_time'], end( $samples )['start_time'] ];
	}

	return [ 'byDay' => $byDay, 'rowsNoHrData' => $rowsNoHrData, 'errors' => $errors ];
}

/**
 * Parse PULSE's own background source, contributing minutes only for bins
 * that don't fall inside any of that day's already-counted exercise
 * windows.
 *
 * @param  string $pCsvFile
 * @param  string $pJsonBaseDir
 * @param  array  $pExerciseWindowsByDay  From healthRaisedHRParseExercise()'s 'byDay'[*]['windows'].
 * @return array{byDay: array<string, array{minsLow:float, minsHigh:float, sampleCount:int}>,
 *               rowsNoBinning:int, errors:string[]}
 */
function healthRaisedHRParseBackground( string $pCsvFile, string $pJsonBaseDir, array $pExerciseWindowsByDay ): array {
	$byDay = [];
	$rowsNoBinning = 0;
	$errors = [];

	if( !is_readable( $pCsvFile ) ) {
		return [ 'byDay' => $byDay, 'rowsNoBinning' => 0, 'errors' => [ "Can't read $pCsvFile" ] ];
	}

	$tz = new \DateTimeZone( 'Europe/London' );
	$binsByDay = []; // date => [ [heart_rate, start_time], ... ]

	foreach( healthParseSamsungCsv( $pCsvFile ) as $row ) {
		$filename = trim( $row['com.samsung.health.heart_rate.binning_data'] ?? '' );
		if( $filename === '' ) {
			$rowsNoBinning++;
			continue;
		}

		$bins = healthLoadBinningData( $pJsonBaseDir, $filename );
		if( !$bins ) {
			$errors[] = "Unreadable/empty binning_data: $filename";
			continue;
		}

		foreach( $bins as $bin ) {
			if( !isset( $bin['start_time'], $bin['heart_rate'] ) || (float)$bin['heart_rate'] <= 0 ) {
				continue;
			}
			$startSeconds = intdiv( (int)$bin['start_time'], 1000 );
			$date = ( new \DateTime( '@'.$startSeconds ) )->setTimezone( $tz )->format( 'Y-m-d' );

			$windows = $pExerciseWindowsByDay[$date] ?? [];
			foreach( $windows as [ $wStart, $wEnd ] ) {
				if( $startSeconds >= $wStart && $startSeconds <= $wEnd ) {
					continue 2; // already counted via the exercise source
				}
			}

			$binsByDay[$date][] = [ 'heart_rate' => (float)$bin['heart_rate'], 'start_time' => $startSeconds ];
		}
	}

	foreach( $binsByDay as $date => $bins ) {
		usort( $bins, fn( $a, $b ) => $a['start_time'] <=> $b['start_time'] );
		$byDay[$date] = [
			'minsLow'     => healthRaisedHRMinutes( $bins, HEALTH_RAISEDHR_THRESHOLD_LOW, HEALTH_RAISEDHR_BACKGROUND_GAP_CAP ),
			'minsHigh'    => healthRaisedHRMinutes( $bins, HEALTH_RAISEDHR_THRESHOLD_HIGH, HEALTH_RAISEDHR_BACKGROUND_GAP_CAP ),
			'minsTop'     => healthRaisedHRMinutes( $bins, HEALTH_RAISEDHR_THRESHOLD_TOP, HEALTH_RAISEDHR_BACKGROUND_GAP_CAP ),
			'sampleCount' => count( $bins ),
		];
	}

	return [ 'byDay' => $byDay, 'rowsNoBinning' => $rowsNoBinning, 'errors' => $errors ];
}

/**
 * Run the full two-source RAISEDHR import.
 *
 * @param  string $pPulseCsvFile
 * @param  string $pPulseJsonDir
 * @param  string $pExerciseCsvFile
 * @param  string $pExerciseJsonDir
 * @return array{created:int,skipped:int,rowsNoBinning:int,errors:string[]}
 */
function healthImportRaisedHR( string $pPulseCsvFile, string $pPulseJsonDir, string $pExerciseCsvFile, string $pExerciseJsonDir ): array {
	$tz = new \DateTimeZone( 'Europe/London' );

	$exercise = healthRaisedHRParseExercise( $pExerciseCsvFile, $pExerciseJsonDir );
	$windowsByDay = array_map( fn( $d ) => $d['windows'] ?? [], $exercise['byDay'] );
	$background = healthRaisedHRParseBackground( $pPulseCsvFile, $pPulseJsonDir, $windowsByDay );

	$allDays = array_unique( array_merge( array_keys( $exercise['byDay'] ), array_keys( $background['byDay'] ) ) );

	$result = [
		'created' => 0, 'skipped' => 0,
		'rowsNoBinning' => $exercise['rowsNoHrData'] + $background['rowsNoBinning'],
		'errors' => array_merge( $exercise['errors'], $background['errors'] ),
	];

	foreach( $allDays as $date ) {
		$ex = $exercise['byDay'][$date] ?? [ 'minsLow' => 0, 'minsHigh' => 0, 'minsTop' => 0, 'sampleCount' => 0 ];
		$bg = $background['byDay'][$date] ?? [ 'minsLow' => 0, 'minsHigh' => 0, 'minsTop' => 0, 'sampleCount' => 0 ];

		$detail = [
			'mins_130'                  => round( $ex['minsTop'] + $bg['minsTop'], 1 ),
			'exercise_mins_90'          => round( $ex['minsLow'], 1 ),
			'exercise_mins_100'         => round( $ex['minsHigh'], 1 ),
			'exercise_mins_130'         => round( $ex['minsTop'], 1 ),
			'exercise_sample_count'     => $ex['sampleCount'],
			'background_mins_90'        => round( $bg['minsLow'], 1 ),
			'background_mins_100'       => round( $bg['minsHigh'], 1 ),
			'background_mins_130'       => round( $bg['minsTop'], 1 ),
			'background_sample_count'   => $bg['sampleCount'],
		];

		$dayStart = new \DateTime( $date.' 00:00:00', $tz );
		$day = HealthDay::findOrCreate( $date );
		if( healthStoreRaisedHR(
			$day->mContentId, $dayStart->getTimestamp(),
			$ex['minsLow'] + $bg['minsLow'], $ex['minsHigh'] + $bg['minsHigh'],
			$detail
		) ) {
			$result['created']++;
		} else {
			$result['skipped']++;
		}
	}

	return $result;
}
