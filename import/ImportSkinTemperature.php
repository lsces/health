<?php
/**
 * Import STEMP (half-hour skin-temperature slot summaries) from Samsung
 * Health's skin_temperature export.
 *
 * Expects, in HEALTH_IMPORT_PATH (storage/health/):
 *   com.samsung.health.skin_temperature.<date>.csv
 *   jsons/com.samsung.health.skin_temperature/<first-char>/<uuid>.binning_data.json
 * (copy both from a health_name_<date> split). Same shape as PULSE
 * (ImportPulse.php) — reuses its shared Samsung CSV/binning helpers.
 *
 * **Only CSV rows carrying a binning_data filename are imported**, same
 * reasoning as PULSE.
 *
 * Each binning_data.json is a flat array of ~1-minute bins (`mean`/`min`/`max`/
 * `start_time`/`end_time`, epoch milliseconds, unambiguous UTC, degrees C).
 * Bins are re-bucketed into fixed half-hour clock slots aligned to
 * Europe/London local time, same convention as PULSE.
 *
 * One STEMP xref row per populated slot: xkey = slot average, xkey_ext =
 * low/high json, data = the slot's own bins as json. Safe to re-run: dedupes
 * on (content_id, item, start_date), start_date being the slot's own start
 * instant (UTC).
 *
 * @package health
 */

require_once __DIR__.'/ImportPulse.php'; // shared Samsung CSV/binning helpers

use Bitweaver\Health\HealthDay;
use Bitweaver\Liberty\LibertyXref;

/**
 * Insert a STEMP xref row for one half-hour slot, unless one already exists
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
function healthStoreSkinTempSlot( int $pContentId, int $pSlotStart, float $pAverage, float $pLow, float $pHigh, array $pBins ): bool {
	global $gBitDb;

	$startDate = gmdate( 'Y-m-d H:i:s', $pSlotStart );
	$existing = $gBitDb->getOne(
		"SELECT `xref_id` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'STEMP' AND `start_date` = ?",
		[ $pContentId, $startDate ]
	);
	if( $existing ) {
		return false;
	}

	$nextXorder = (int)$gBitDb->getOne(
		"SELECT COALESCE( MAX(`xorder`) + 1, 0 ) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'STEMP'",
		[ $pContentId ]
	);

	$pHash = [
		'content_id' => $pContentId,
		'item'       => 'STEMP',
		'xorder'     => $nextXorder,
		'xkey'       => (string)round( $pAverage, 2 ),
		'xkey_ext'   => json_encode( [ 'low' => $pLow, 'high' => $pHigh ] ),
		'edit'       => json_encode( $pBins ),
		'start_date' => $pSlotStart,
	];
	$xref = new LibertyXref();
	$xref->store( $pHash );
	return true;
}

/**
 * Run the full skin_temperature import: read the CSV, pull every row's
 * binning_data, re-bucket every minute-bin into half-hour Europe/London
 * slots, and store one STEMP row per populated slot.
 *
 * @param  string $pCsvFile
 * @param  string $pJsonBaseDir  Directory containing the per-first-char subfolders.
 * @return array{created:int,skipped:int,rowsNoBinning:int,errors:string[]}
 */
function healthImportSkinTemperature( string $pCsvFile, string $pJsonBaseDir ): array {
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
			if( !isset( $bin['start_time'], $bin['mean'] ) ) {
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
				'mean'       => (float)$bin['mean'],
				'min'        => (float)( $bin['min'] ?? $bin['mean'] ),
				'max'        => (float)( $bin['max'] ?? $bin['mean'] ),
				'start_time' => (int)$bin['start_time'],
			];
		}
	}

	foreach( $slots as $slot ) {
		$means = array_column( $slot['bins'], 'mean' );
		$lows  = array_column( $slot['bins'], 'min' );
		$highs = array_column( $slot['bins'], 'max' );

		$day = HealthDay::findOrCreate( $slot['date'] );
		if( healthStoreSkinTempSlot(
			$day->mContentId, $slot['slotStart'],
			array_sum( $means ) / count( $means ), min( $lows ), max( $highs ),
			$slot['bins']
		) ) {
			$result['created']++;
		} else {
			$result['skipped']++;
		}
	}

	return $result;
}
