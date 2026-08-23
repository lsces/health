<?php
/**
 * Import STEPTRACK (one row per day, full intraday step-count track) from
 * Samsung Health's step_daily_trend export.
 *
 * A genuinely richer companion to STEPS (ImportSteps.php), which only reads
 * the coarse daily total from activity.day_summary. Each step_daily_trend
 * row's own binning_data.json is a **fixed 144-entry array** (10-minute
 * intervals across the day, 144*10=1440min=24h) of `count`/`calorie`/
 * `distance`/`speed` — unlike PULSE/RESP/STEMP/HRV's bins, these carry no
 * embedded timestamp of their own; bin index * 10 minutes from local
 * (Europe/London) midnight is the only way to place them in time.
 *
 * Expects, in HEALTH_IMPORT_PATH (storage/health/):
 *   com.samsung.shealth.step_daily_trend.<date>.csv
 *   jsons/com.samsung.shealth.step_daily_trend/<first-char>/<uuid>.binning_data.json
 * (copy both from a health_lester_<date> split). Reuses ImportPulse.php's
 * shared Samsung CSV/binning helpers.
 *
 * `day_time` is always local midnight with no real clock-time meaning, same
 * as STEPS/ENERGY's own `day_time` field — only its date portion is used.
 *
 * One STEPTRACK xref row per day (not per slot - the whole day's 144 bins
 * are one track, meant to be charted as a single day view rather than
 * fragmented into 144 separate rows): xkey = daily step total (sum of all
 * bins' `count`), xkey_ext = peak 10-minute count, data = the full 144-bin
 * array as json. Safe to re-run: dedupes on (content_id, item, start_date),
 * start_date being that day's own local midnight.
 *
 * @package health
 */

require_once __DIR__.'/ImportPulse.php'; // shared Samsung CSV/binning helpers

use Bitweaver\Health\HealthDay;
use Bitweaver\Liberty\LibertyXref;

/**
 * Insert a STEPTRACK xref row for one day, unless one already exists for
 * this exact content_id + start_date.
 *
 * @param  int   $pContentId   The day's HealthDay content_id.
 * @param  int   $pDayStart    Unix timestamp of local midnight (UTC instant).
 * @param  int   $pTotalSteps
 * @param  int   $pPeakCount   Highest single 10-minute bin count.
 * @param  array $pBins        The day's full 144-bin array, as-is from source.
 * @return bool  TRUE if a new row was inserted, FALSE if one already existed (skipped).
 */
function healthStoreStepTrack( int $pContentId, int $pDayStart, int $pTotalSteps, int $pPeakCount, array $pBins ): bool {
	global $gBitDb;

	$startDate = gmdate( 'Y-m-d H:i:s', $pDayStart );
	$existing = $gBitDb->getOne(
		"SELECT `xref_id` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'STEPTRACK' AND `start_date` = ?",
		[ $pContentId, $startDate ]
	);
	if( $existing ) {
		return false;
	}

	$pHash = [
		'content_id' => $pContentId,
		'item'       => 'STEPTRACK',
		'xorder'     => 0,
		'xkey'       => (string)$pTotalSteps,
		'xkey_ext'   => (string)$pPeakCount,
		'edit'       => json_encode( $pBins ),
		'start_date' => $pDayStart,
	];
	$xref = new LibertyXref();
	$xref->store( $pHash );
	return true;
}

/**
 * Run the full step_daily_trend import: one STEPTRACK row per CSV row (one
 * row per day) that carries a binning_data file.
 *
 * @param  string $pCsvFile
 * @param  string $pJsonBaseDir  Directory containing the per-first-char subfolders.
 * @return array{created:int,skipped:int,rowsNoBinning:int,errors:string[]}
 */
function healthImportStepTrack( string $pCsvFile, string $pJsonBaseDir ): array {
	$result = [ 'created' => 0, 'skipped' => 0, 'rowsNoBinning' => 0, 'errors' => [] ];

	if( !is_readable( $pCsvFile ) ) {
		$result['errors'][] = "Can't read $pCsvFile";
		return $result;
	}

	$tz = new \DateTimeZone( 'Europe/London' );

	foreach( healthParseSamsungCsv( $pCsvFile ) as $row ) {
		$filename = trim( $row['binning_data'] ?? '' );
		$dayTime  = trim( $row['day_time'] ?? '' );
		if( $filename === '' || $dayTime === '' ) {
			$result['rowsNoBinning']++;
			continue;
		}

		$bins = healthLoadBinningData( $pJsonBaseDir, $filename );
		if( !$bins ) {
			$result['errors'][] = "Unreadable/empty binning_data: $filename";
			continue;
		}

		$date = substr( $dayTime, 0, 10 ); // 'Y-m-d' out of 'Y-m-d H:i:s.uuu'
		try {
			$dayStart = new \DateTime( $date.' 00:00:00', $tz );
		} catch( \Exception $e ) {
			$result['errors'][] = "Unparseable day_time '$dayTime'";
			continue;
		}

		$counts = array_column( $bins, 'count' );
		$total  = (int)array_sum( $counts );
		$peak   = $counts ? (int)max( $counts ) : 0;

		$day = HealthDay::findOrCreate( $date );
		if( healthStoreStepTrack( $day->mContentId, $dayStart->getTimestamp(), $total, $peak, $bins ) ) {
			$result['created']++;
		} else {
			$result['skipped']++;
		}
	}

	return $result;
}
