<?php
/**
 * Import HRV (half-hour heart-rate-variability slot summaries) from Samsung
 * Health's hrv export.
 *
 * This is the richer, per-reading tier deferred in ImportEnergy.php's own
 * docblock — ENERGY already folds in vitality_score's daily `shrv_value`
 * summary, but that's a coarser, different thing from this.
 *
 * Expects, in HEALTH_IMPORT_PATH (storage/health/):
 *   com.samsung.health.hrv.<date>.csv
 *   jsons/com.samsung.health.hrv/<first-char>/<uuid>.binning_data.json
 * (copy both from a health_name_<date> split). Same shape as PULSE
 * (ImportPulse.php) — reuses its shared Samsung CSV/binning helpers.
 *
 * **Only CSV rows carrying a binning_data filename are imported**, same
 * reasoning as PULSE.
 *
 * Each binning_data.json is a flat array of bins carrying `sdnn` and `rmssd`
 * (both real HRV metrics, no single scalar) plus `start_time`/`end_time`
 * (epoch milliseconds, unambiguous UTC). Bins are re-bucketed into fixed
 * half-hour clock slots aligned to Europe/London local time, same convention
 * as PULSE.
 *
 * One HRV xref row per populated slot: xkey = slot average sdnn, xkey_ext =
 * slot average rmssd (both real headline values, same 'value' template
 * convention as BP/OXI — not a nested low/high json like PULSE/RESP/STEMP),
 * data = the slot's own bins as json. Safe to re-run: dedupes on
 * (content_id, item, start_date), start_date being the slot's own start
 * instant (UTC).
 *
 * @package health
 */

require_once __DIR__.'/ImportPulse.php'; // shared Samsung CSV/binning helpers

use Bitweaver\Health\HealthDay;
use Bitweaver\Liberty\LibertyXref;

/**
 * Insert an HRV xref row for one half-hour slot, unless one already exists
 * for this exact content_id + start_date.
 *
 * @param  int   $pContentId  The day's HealthDay content_id.
 * @param  int   $pSlotStart  Unix timestamp of the slot's start (UTC).
 * @param  float $pAvgSdnn
 * @param  float $pAvgRmssd
 * @param  array $pBins       This slot's own readings, as-is from source.
 * @return bool  TRUE if a new row was inserted, FALSE if one already existed (skipped).
 */
function healthStoreHRVSlot( int $pContentId, int $pSlotStart, float $pAvgSdnn, float $pAvgRmssd, array $pBins ): bool {
	global $gBitDb;

	$startDate = gmdate( 'Y-m-d H:i:s', $pSlotStart );
	$existing = $gBitDb->getOne(
		"SELECT `xref_id` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'HRV' AND `start_date` = ?",
		[ $pContentId, $startDate ]
	);
	if( $existing ) {
		return false;
	}

	$nextXorder = (int)$gBitDb->getOne(
		"SELECT COALESCE( MAX(`xorder`) + 1, 0 ) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'HRV'",
		[ $pContentId ]
	);

	$pHash = [
		'content_id' => $pContentId,
		'item'       => 'HRV',
		'xorder'     => $nextXorder,
		'xkey'       => (string)round( $pAvgSdnn, 2 ),
		'xkey_ext'   => (string)round( $pAvgRmssd, 2 ),
		'edit'       => json_encode( $pBins ),
		'start_date' => $pSlotStart,
	];
	$xref = new LibertyXref();
	$xref->store( $pHash );
	return true;
}

/**
 * Run the full hrv import: read the CSV, pull every row's binning_data,
 * re-bucket every bin into half-hour Europe/London slots, and store one HRV
 * row per populated slot.
 *
 * @param  string $pCsvFile
 * @param  string $pJsonBaseDir  Directory containing the per-first-char subfolders.
 * @return array{created:int,skipped:int,rowsNoBinning:int,errors:string[]}
 */
function healthImportHRV( string $pCsvFile, string $pJsonBaseDir ): array {
	$result = [ 'created' => 0, 'skipped' => 0, 'rowsNoBinning' => 0, 'errors' => [] ];

	if( !is_readable( $pCsvFile ) ) {
		$result['errors'][] = "Can't read $pCsvFile";
		return $result;
	}

	$tz = new \DateTimeZone( 'Europe/London' );
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
			if( !isset( $bin['start_time'], $bin['sdnn'], $bin['rmssd'] ) ) {
				continue;
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
				'sdnn'       => (float)$bin['sdnn'],
				'rmssd'      => (float)$bin['rmssd'],
				'start_time' => (int)$bin['start_time'],
			];
		}
	}

	foreach( $slots as $slot ) {
		$sdnns  = array_column( $slot['bins'], 'sdnn' );
		$rmssds = array_column( $slot['bins'], 'rmssd' );

		$day = HealthDay::findOrCreate( $slot['date'] );
		if( healthStoreHRVSlot(
			$day->mContentId, $slot['slotStart'],
			array_sum( $sdnns ) / count( $sdnns ), array_sum( $rmssds ) / count( $rmssds ),
			$slot['bins']
		) ) {
			$result['created']++;
		} else {
			$result['skipped']++;
		}
	}

	return $result;
}
