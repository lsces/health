<?php
/**
 * Import RESP (half-hour respiratory-rate slot summaries) from Samsung
 * Health's respiratory_rate export.
 *
 * Expects, in HEALTH_IMPORT_PATH (storage/health/):
 *   com.samsung.health.respiratory_rate.<date>.csv
 *   jsons/com.samsung.health.respiratory_rate/<first-char>/<uuid>.binning_data.json
 * (copy both from a health_name_<date> split). Same shape as PULSE
 * (ImportPulse.php) — reuses its healthParseSamsungCsv()/healthLoadBinningData()/
 * healthFindLatestSamsungCsv() helpers rather than duplicating them.
 *
 * **Only CSV rows carrying a binning_data filename are imported**, same
 * reasoning as PULSE — rows without one are summary-only and deliberately
 * skipped rather than inventing a slot-assignment rule for them.
 *
 * Each binning_data.json is a flat array of ~1-minute bins (`respiratory_rate`/
 * `start_time`/`end_time`, epoch milliseconds, unambiguous UTC). Bins are
 * re-bucketed into fixed half-hour clock slots aligned to Europe/London local
 * time, same convention as PULSE. Unlike PULSE, a bin carries only a single
 * `respiratory_rate` value (no embedded min/max) — slot low/high are computed
 * across the slot's own bin values instead.
 *
 * One RESP xref row per populated slot: xkey = slot average, xkey_ext =
 * low/high json, data = the slot's own bins as json. Safe to re-run: dedupes
 * on (content_id, item, start_date), start_date being the slot's own start
 * instant (UTC).
 *
 * @package health
 */

require_once __DIR__.'/ImportPulse.php'; // shared Samsung CSV/binning helpers

use Bitweaver\Health\HealthDay;
use Bitweaver\Liberty\LibertyContent;

/**
 * Insert a RESP xref row for one half-hour slot, unless one already exists
 * for this exact content_id + start_date.
 *
 * @param  int   $pContentId  The day's HealthDay content_id.
 * @param  int   $pSlotStart  Unix timestamp of the slot's start (UTC).
 * @param  float $pAverage
 * @param  float $pLow
 * @param  float $pHigh
 * @param  array $pBins       This slot's own minute-level readings, as-is from source.
 * @return bool  TRUE if a new row was inserted, FALSE if one already existed (skipped).
 */
function healthStoreRespSlot( int $pContentId, int $pSlotStart, float $pAverage, float $pLow, float $pHigh, array $pBins ): bool {
	return LibertyContent::insertXrefReadingIfNew( $pContentId, 'RESP', $pSlotStart, [
		'xkey'     => (string)round( $pAverage, 1 ),
		'xkey_ext' => json_encode( [ 'low' => $pLow, 'high' => $pHigh ] ),
		'edit'     => json_encode( $pBins ),
	] );
}

/**
 * Run the full respiratory_rate import: read the CSV, pull every row's
 * binning_data, re-bucket every minute-bin into half-hour Europe/London
 * slots, and store one RESP row per populated slot.
 *
 * @param  string $pCsvFile
 * @param  string $pJsonBaseDir  Directory containing the per-first-char subfolders.
 * @return array{created:int,skipped:int,rowsNoBinning:int,errors:string[]}
 */
function healthImportRespiratoryRate( string $pCsvFile, string $pJsonBaseDir ): array {
	$result = [ 'created' => 0, 'skipped' => 0, 'rowsNoBinning' => 0, 'errors' => [] ];

	if( !is_readable( $pCsvFile ) ) {
		$result['errors'][] = "Can't read $pCsvFile";
		return $result;
	}

	global $gBitUser;
	$tz = $gBitUser->getUserTimezone();
	$slots = [];

	foreach( healthParseSamsungCsv( $pCsvFile ) as $row ) {
		$filename = trim( $row['binning_data'] ?? '' );
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
			if( !isset( $bin['start_time'], $bin['respiratory_rate'] ) || (float)$bin['respiratory_rate'] === 0.0 ) {
				continue; // 0.0 = no reading in this bin, not a real zero rate
			}
			$dt = ( new \DateTime( '@'.intdiv( (int)$bin['start_time'], 1000 ) ) )->setTimezone( $tz );
			$slotStartLocal = ( clone $dt )->setTime(
				(int)$dt->format( 'H'), (int)( intdiv( (int)$dt->format( 'i' ), 30 ) * 30 ), 0
			);
			$slotKey = $slotStartLocal->format( 'Y-m-d H:i' );

			if( !isset( $slots[$slotKey] ) ) {
				$slots[$slotKey] = [
					'date'      => $slotStartLocal->format( 'Y-m-d' ),
					'slotStart' => $slotStartLocal->getTimestamp(),
					'bins'      => [],
				];
			}
			$slots[$slotKey]['bins'][] = [
				'respiratory_rate' => (float)$bin['respiratory_rate'],
				'start_time'       => (int)$bin['start_time'],
			];
		}
	}

	foreach( $slots as $slot ) {
		$rates = array_column( $slot['bins'], 'respiratory_rate' );

		$day = HealthDay::findOrCreate( $slot['date'] );
		if( healthStoreRespSlot(
			$day->mContentId, $slot['slotStart'],
			array_sum( $rates ) / count( $rates ), min( $rates ), max( $rates ),
			$slot['bins']
		) ) {
			$result['created']++;
		} else {
			$result['skipped']++;
		}
	}

	return $result;
}
