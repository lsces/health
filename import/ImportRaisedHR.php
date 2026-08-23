<?php
/**
 * Import RAISEDHR (minutes with heart rate above two personal thresholds,
 * per day) from Samsung Health's own tracker.heart_rate export — the same
 * source PULSE (ImportPulse.php) already uses.
 *
 * This is the legacy spreadsheet's "Raised HR" column (fghi tier), deferred
 * back when STEPS was built ("no source found" — see ImportSteps.php).
 *
 * **Deliberately built against PULSE's continuous background source, not
 * the exercise export's per-session live_data** (first draft, replaced same
 * session) — exercise sessions only exist when the exercise-tracking app is
 * explicitly started (a real gap hit live during this work: a physio
 * session with no watch-app start produces zero exercise rows at all, so a
 * session-scoped importer would silently show "no data" instead of the
 * true answer). The watch's own background heart-rate monitor runs
 * regardless, so building on that source gives a real per-day figure even
 * on days with no logged "exercise."
 *
 * **Not** Samsung's own exercise.hr_zone thresholds (at=139/ant=150/
 * max_hr_auto=164 — generic sports-training zones, would read ~0 minutes
 * almost every day). The real basis is medical/personal: GP-approved
 * threshold is >90 bpm, Lester's own working target is >100 bpm. Both
 * computed side by side so the two numbers are directly comparable, same
 * as the spreadsheet comparison this replaces.
 *
 * Expects, in HEALTH_IMPORT_PATH (storage/health/):
 *   com.samsung.shealth.tracker.heart_rate.<date>.csv
 *   jsons/com.samsung.shealth.tracker.heart_rate/<first-char>/<uuid>....json
 * (copy both from a health_lester_<date> split — same files PULSE reads).
 * Reuses ImportPulse.php's shared Samsung CSV/binning helpers.
 *
 * **Only rows carrying a binning_data filename contribute**, same scoping
 * as PULSE (4,687 of 33,943 rows in the 2026-08-14 export).
 *
 * Bins across every row are grouped by their own local (Europe/London)
 * calendar date (not the half-hour slots PULSE uses) - one day's worth of
 * bins, however many rows/sessions they came from, feed one day total.
 * Time-above-threshold is duration-weighted between consecutive bins within
 * a day (gap capped at 30s to avoid an off-wrist/charging gap being counted
 * as raised time).
 *
 * One RAISEDHR xref row per day: xkey = minutes >=90, xkey_ext = minutes
 * >=100, data = {sample_count, max_heart_rate} day summary. Safe to re-run:
 * dedupes on (content_id, item, start_date), start_date being that day's
 * own local midnight.
 *
 * @package health
 */

require_once __DIR__.'/ImportPulse.php'; // shared Samsung CSV/binning helpers

use Bitweaver\Health\HealthDay;
use Bitweaver\Liberty\LibertyXref;

const HEALTH_RAISEDHR_GAP_CAP_SECONDS = 30;
const HEALTH_RAISEDHR_THRESHOLD_LOW   = 90.0;  // GP-approved
const HEALTH_RAISEDHR_THRESHOLD_HIGH  = 100.0; // Lester's own working target

/**
 * Insert a RAISEDHR xref row for one day, unless one already exists for
 * this exact content_id + start_date.
 *
 * @param  int   $pContentId  The day's HealthDay content_id.
 * @param  int   $pDayStart   Unix timestamp of local midnight (UTC instant).
 * @param  float $pMinsLow    Minutes with HR >= HEALTH_RAISEDHR_THRESHOLD_LOW.
 * @param  float $pMinsHigh   Minutes with HR >= HEALTH_RAISEDHR_THRESHOLD_HIGH.
 * @param  array $pDetail     ['sample_count'=>.., 'max_heart_rate'=>..]
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
 * Compute duration-weighted minutes with heart_rate >= $pThreshold across a
 * time-sorted list of {heart_rate, start_time (unix seconds)} bins.
 *
 * @param  array $pBins      Sorted by start_time ascending.
 * @param  float $pThreshold
 * @return float  Minutes.
 */
function healthRaisedHRMinutes( array $pBins, float $pThreshold ): float {
	$seconds = 0;
	$n = count( $pBins );
	for( $i = 0; $i < $n - 1; $i++ ) {
		if( $pBins[$i]['heart_rate'] < $pThreshold ) {
			continue;
		}
		$gap = $pBins[$i + 1]['start_time'] - $pBins[$i]['start_time'];
		$seconds += min( $gap, HEALTH_RAISEDHR_GAP_CAP_SECONDS );
	}
	return $seconds / 60;
}

/**
 * Run the full tracker.heart_rate import for raised-HR time: read the CSV,
 * pull every row's binning_data, group every minute-bin by its own local
 * calendar date, and store one RAISEDHR row per day.
 *
 * @param  string $pCsvFile
 * @param  string $pJsonBaseDir  Directory containing the per-first-char subfolders.
 * @return array{created:int,skipped:int,rowsNoBinning:int,errors:string[]}
 */
function healthImportRaisedHR( string $pCsvFile, string $pJsonBaseDir ): array {
	$result = [ 'created' => 0, 'skipped' => 0, 'rowsNoBinning' => 0, 'errors' => [] ];

	if( !is_readable( $pCsvFile ) ) {
		$result['errors'][] = "Can't read $pCsvFile";
		return $result;
	}

	$tz = new \DateTimeZone( 'Europe/London' );

	// date ('Y-m-d') => [ 'bins' => [...] ]
	$days = [];

	foreach( healthParseSamsungCsv( $pCsvFile ) as $row ) {
		$filename = trim( $row['com.samsung.health.heart_rate.binning_data'] ?? '' );
		if( $filename === '' ) {
			$result['rowsNoBinning']++;
			continue;
		}

		$bins = healthLoadBinningData( $pJsonBaseDir, $filename );
		if( !$bins ) {
			$result['errors'][] = "Unreadable/empty binning_data: $filename";
			continue;
		}

		foreach( $bins as $bin ) {
			if( !isset( $bin['start_time'], $bin['heart_rate'] ) || (float)$bin['heart_rate'] <= 0 ) {
				continue;
			}
			$startSeconds = intdiv( (int)$bin['start_time'], 1000 );
			$date = ( new \DateTime( '@'.$startSeconds ) )->setTimezone( $tz )->format( 'Y-m-d' );

			$days[$date]['bins'][] = [
				'heart_rate' => (float)$bin['heart_rate'],
				'start_time' => $startSeconds,
			];
		}
	}

	foreach( $days as $date => $dayData ) {
		$bins = $dayData['bins'];
		usort( $bins, fn( $a, $b ) => $a['start_time'] <=> $b['start_time'] );

		$minsLow  = healthRaisedHRMinutes( $bins, HEALTH_RAISEDHR_THRESHOLD_LOW );
		$minsHigh = healthRaisedHRMinutes( $bins, HEALTH_RAISEDHR_THRESHOLD_HIGH );
		$rates    = array_column( $bins, 'heart_rate' );

		$detail = [
			'sample_count'   => count( $bins ),
			'max_heart_rate' => $rates ? max( $rates ) : 0,
		];

		$dayStart = new \DateTime( $date.' 00:00:00', $tz );
		$day = HealthDay::findOrCreate( $date );
		if( healthStoreRaisedHR( $day->mContentId, $dayStart->getTimestamp(), $minsLow, $minsHigh, $detail ) ) {
			$result['created']++;
		} else {
			$result['skipped']++;
		}
	}

	return $result;
}
