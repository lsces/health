<?php
/**
 * Import OXIDESAT (minutes with SpO2 below three thresholds, per sleep
 * session) from the same Samsung `tracker.oxygen_saturation` export
 * ImportOxiSamsung.php already reads.
 *
 * Companion to that item, not a replacement — OXI stores one row's own
 * session-average SpO2/pulse; this reads the same rows' `binning` field
 * (a per-~1-minute-slice detail array `{spo2, spo2_max, spo2_min,
 * start_time, end_time}` covering the whole tracked window) that
 * ImportOxiSamsung.php never opens, and reduces it to "how long was spent
 * below 90/85/80%" — the shape Lester actually wants to compare against
 * subjectively bad nights, the same way RAISEDHR answers "how long was HR
 * raised" rather than just a session average. See ImportRaisedHR.php for
 * the sibling design this one is modeled on.
 *
 * **Only CSV rows carrying a `binning` filename are imported** — of 648 rows
 * in the 2026-08-26 export, 618 are real sleep-length sessions with one;
 * the rest are short spot-checks with a single flat `spo2` value and no
 * detail to reduce (already ImportOxiSamsung.php's whole job).
 *
 * **Minutes-below-threshold uses each slice's own `start_time`/`end_time`
 * span, not a gap-capped diff between consecutive slice starts** (unlike
 * RAISEDHR's `healthRaisedHRMinutes()`). Real data shows slices aren't
 * evenly spaced — the watch takes periodic SpO2 readings through the night,
 * not continuous ones, so consecutive `start_time`s can be minutes apart
 * even though each slice's own window is a clean ~59s. Summing each
 * qualifying slice's own span only counts time actually measured — a real
 * gap between readings is silently excluded rather than guessed at via a
 * gap cap, so this is a floor on time-below-threshold, not an estimate
 * across the whole night. `coverage_mins` (sum of every slice's span,
 * regardless of value) is stored alongside so a low-coverage night doesn't
 * get silently read as "no dips."
 *
 * **Parsed as UTC, not Europe/London** — same fix/reasoning as
 * ImportOxiSamsung.php's own docblock: Samsung's `start_time` for this CSV
 * is already the UTC-equivalent value.
 *
 * Expects, in HEALTH_IMPORT_PATH (storage/health/):
 *   com.samsung.shealth.tracker.oxygen_saturation.<date>.csv
 *   jsons/com.samsung.shealth.tracker.oxygen_saturation/<first-char>/<uuid>....json
 * (copy both from a health_name_<date> split). Reuses ImportPulse.php's
 * shared Samsung CSV/binning helpers.
 *
 * One OXIDESAT xref row per session: xkey = mins <90%, xkey_ext = mins
 * <85%, data = {mins_80, low_value, low_time, sample_count, coverage_mins,
 * session_mins, spo2_avg, spo2_min, spo2_max} (the last three straight off
 * the CSV row, the same session-average figures OXI itself stores, kept
 * here too as a cross-check without needing to join back to OXI). Safe to
 * re-run: dedupes on (content_id, item, start_date), start_date being the
 * session's own start instant, same as SLEEP/EXERCISE/OXI.
 *
 * @package health
 */

require_once __DIR__.'/ImportPulse.php'; // healthParseSamsungCsv(), healthLoadBinningData()

use Bitweaver\Health\HealthDay;
use Bitweaver\Liberty\LibertyContent;

const HEALTH_OXIDESAT_THRESHOLD_1 = 90.0;
const HEALTH_OXIDESAT_THRESHOLD_2 = 85.0;
const HEALTH_OXIDESAT_THRESHOLD_3 = 80.0;

/**
 * Insert an OXIDESAT xref row for one session, unless one already exists
 * for this exact content_id + start_date.
 *
 * @param  int   $pContentId  The day's HealthDay content_id.
 * @param  int   $pTimestamp  Unix timestamp of the session start (UTC).
 * @param  float $pMinsBelow90
 * @param  float $pMinsBelow85
 * @param  array $pDetail
 * @return bool  TRUE if a new row was inserted, FALSE if one already existed (skipped).
 */
function healthStoreOxiDesat( int $pContentId, int $pTimestamp, float $pMinsBelow90, float $pMinsBelow85, array $pDetail ): bool {
	return LibertyContent::insertXrefReadingIfNew( $pContentId, 'OXIDESAT', $pTimestamp, [
		'xkey'     => (string)round( $pMinsBelow90, 1 ),
		'xkey_ext' => (string)round( $pMinsBelow85, 1 ),
		'edit'     => json_encode( $pDetail ),
	] );
}

/**
 * Reduce one session's binning slices to minutes-below-threshold for all
 * three tiers plus the session's lowest point, using each slice's own
 * start/end span (see this file's own docblock for why, not a gap-capped
 * diff like RAISEDHR).
 *
 * @param  array $pSlices  Decoded binning_data.json for one session.
 * @return array{mins90:float,mins85:float,mins80:float,lowValue:?float,
 *               lowTimeMs:?int,sampleCount:int,coverageMins:float}
 */
function healthOxiDesatReduceSession( array $pSlices ): array {
	$mins90 = $mins85 = $mins80 = 0.0;
	$coverageMins = 0.0;
	$lowValue = null;
	$lowTimeMs = null;
	$sampleCount = 0;

	foreach( $pSlices as $slice ) {
		if( !isset( $slice['spo2_min'], $slice['start_time'], $slice['end_time'] ) ) {
			continue;
		}
		$spo2Min = (float)$slice['spo2_min'];
		$spanMs  = (int)$slice['end_time'] - (int)$slice['start_time'];
		if( $spanMs <= 0 ) {
			continue;
		}
		$spanMins = $spanMs / 60000;
		$sampleCount++;
		$coverageMins += $spanMins;

		if( $spo2Min < HEALTH_OXIDESAT_THRESHOLD_1 ) $mins90 += $spanMins;
		if( $spo2Min < HEALTH_OXIDESAT_THRESHOLD_2 ) $mins85 += $spanMins;
		if( $spo2Min < HEALTH_OXIDESAT_THRESHOLD_3 ) $mins80 += $spanMins;

		if( $lowValue === null || $spo2Min < $lowValue ) {
			$lowValue  = $spo2Min;
			$lowTimeMs = (int)$slice['start_time'];
		}
	}

	return [
		'mins90' => $mins90, 'mins85' => $mins85, 'mins80' => $mins80,
		'lowValue' => $lowValue, 'lowTimeMs' => $lowTimeMs,
		'sampleCount' => $sampleCount, 'coverageMins' => $coverageMins,
	];
}

/**
 * Run the full OXIDESAT import.
 *
 * @param  string $pCsvFile
 * @param  string $pJsonBaseDir
 * @return array{created:int,skipped:int,rowsNoBinning:int,errors:string[]}
 */
function healthImportOxiDesat( string $pCsvFile, string $pJsonBaseDir ): array {
	$result = [ 'created' => 0, 'skipped' => 0, 'rowsNoBinning' => 0, 'errors' => [] ];

	if( !is_readable( $pCsvFile ) ) {
		$result['errors'][] = "Can't read $pCsvFile";
		return $result;
	}

	global $gBitUser;
	$localTz = $gBitUser->getUserTimezone();
	$tz = new \DateTimeZone( 'UTC' );
	$P  = 'com.samsung.health.oxygen_saturation.';

	foreach( healthParseSamsungCsv( $pCsvFile ) as $rowNum => $row ) {
		$filename = trim( $row[$P.'binning'] ?? '' );
		if( $filename === '' ) {
			$result['rowsNoBinning']++;
			continue;
		}

		$startStr = $row[$P.'start_time'] ?? '';
		$endStr   = $row[$P.'end_time'] ?? '';
		if( !$startStr ) {
			$result['errors'][] = "Row $rowNum: no start_time";
			continue;
		}
		try {
			$start = new \DateTime( $startStr, $tz );
			$end   = $endStr ? new \DateTime( $endStr, $tz ) : null;
		} catch( \Exception $e ) {
			$result['errors'][] = "Row $rowNum: unparseable start_time '$startStr'";
			continue;
		}

		$slices = healthLoadBinningData( $pJsonBaseDir, $filename );
		if( !$slices ) {
			$result['errors'][] = "Unreadable/empty binning: $filename";
			continue;
		}

		$reduced = healthOxiDesatReduceSession( $slices );
		if( $reduced['sampleCount'] < 2 ) {
			$result['rowsNoBinning']++;
			continue;
		}

		$detail = [
			'mins_80'       => round( $reduced['mins80'], 1 ),
			'low_value'     => $reduced['lowValue'],
			'low_time'      => $reduced['lowTimeMs']
				? ( new \DateTime( '@'.intdiv( $reduced['lowTimeMs'], 1000 ) ) )->setTimezone( $localTz )->format( 'Y-m-d H:i' )
				: null,
			'sample_count'  => $reduced['sampleCount'],
			'coverage_mins' => round( $reduced['coverageMins'], 1 ),
			'session_mins'  => $end ? round( ( $end->getTimestamp() - $start->getTimestamp() ) / 60, 1 ) : null,
			'spo2_avg'      => isset( $row[$P.'spo2'] ) ? (float)$row[$P.'spo2'] : null,
			'spo2_min'      => isset( $row[$P.'min'] )  ? (float)$row[$P.'min']  : null,
			'spo2_max'      => isset( $row[$P.'max'] )  ? (float)$row[$P.'max']  : null,
		];

		$date = $start->format( 'Y-m-d' );
		$day  = HealthDay::findOrCreate( $date );
		if( healthStoreOxiDesat( $day->mContentId, $start->getTimestamp(), $reduced['mins90'], $reduced['mins85'], $detail ) ) {
			$result['created']++;
		} else {
			$result['skipped']++;
		}
	}

	return $result;
}
