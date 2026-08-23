<?php
/**
 * Populate HEALTH_HR_RAW (see admin/upgrades/hr_raw_table_upgrade.sql for
 * the table's own full design rationale) from both Samsung sources that
 * carry heart-rate "traces":
 *   - com.samsung.shealth.tracker.heart_rate's binning_data (source='background')
 *   - com.samsung.shealth.exercise's live_data (source='exercise')
 *
 * One row per raw entry from both JSON sets - no reduction, no slot
 * bucketing, no threshold logic here at all. This is purely a parse-and-
 * load step; PULSE/RAISEDHR/any future HR-derived feature reads back from
 * this table instead of re-parsing the source files themselves.
 *
 * Full-refresh, not incremental: TRUNCATEs the table first, then reloads
 * everything from the current export in one pass. Safe to re-run any time
 * a new/updated export lands - there's no "existing row" concept to dedupe
 * against, only "what's the current full picture."
 *
 * Expects, in HEALTH_IMPORT_PATH (storage/health/):
 *   com.samsung.shealth.tracker.heart_rate.<date>.csv + its jsons/ blobs
 *   com.samsung.shealth.exercise.<date>.csv + its jsons/ blobs (live_data only needed)
 * (copy all four from a health_lester_<date> split). Reuses ImportPulse.php's
 * shared Samsung CSV/binning helpers.
 *
 * @package health
 */

require_once __DIR__.'/ImportPulse.php'; // shared Samsung CSV/binning helpers

const HEALTH_HR_RAW_BATCH_SIZE = 5000; // rows per commit

/**
 * Insert one raw HR row. Returns TRUE on success, FALSE on failure
 * (including a START_TIME primary-key collision - genuinely rare, not
 * expected to recur across sources given background goes quiet exactly
 * when exercise is active, but not assumed impossible).
 */
function healthStoreHRRaw( \DateTime $pStart, ?\DateTime $pEnd, float $pHeartRate, ?float $pMin, ?float $pMax, string $pSource, ?string $pDatauuid ): bool {
	global $gBitDb;

	$row = [
		'start_time' => $pStart->format( 'Y-m-d H:i:s' ),
		'end_time'   => $pEnd ? $pEnd->format( 'Y-m-d H:i:s' ) : null,
		'heart_rate' => $pHeartRate,
		'heart_rate_min' => $pMin,
		'heart_rate_max' => $pMax,
		'source'     => $pSource,
		'datauuid'   => $pDatauuid,
	];
	return (bool)$gBitDb->associateInsert( 'health_hr_raw', $row );
}

/**
 * Load the background source (tracker.heart_rate binning_data) and insert
 * every bin as its own row.
 *
 * @return array{inserted:int,duplicate:int,rowsNoBinning:int,errors:string[]}
 */
function healthImportHRRawBackground( string $pCsvFile, string $pJsonBaseDir ): array {
	global $gBitDb;
	$result = [ 'inserted' => 0, 'duplicate' => 0, 'rowsNoBinning' => 0, 'errors' => [] ];

	if( !is_readable( $pCsvFile ) ) {
		$result['errors'][] = "Can't read $pCsvFile";
		return $result;
	}

	$tz = new \DateTimeZone( 'UTC' ); // source epoch ms is already UTC, store as-is
	$sinceCommit = 0;
	$gBitDb->StartTrans();

	foreach( healthParseSamsungCsv( $pCsvFile ) as $row ) {
		$filename = trim( $row['com.samsung.health.heart_rate.binning_data'] ?? '' );
		$datauuid = trim( $row['datauuid'] ?? '' );
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
			$start = ( new \DateTime( '@'.intdiv( (int)$bin['start_time'], 1000 ) ) )->setTimezone( $tz );
			$end   = isset( $bin['end_time'] ) ? ( new \DateTime( '@'.intdiv( (int)$bin['end_time'], 1000 ) ) )->setTimezone( $tz ) : null;

			$ok = healthStoreHRRaw(
				$start, $end, (float)$bin['heart_rate'],
				isset( $bin['heart_rate_min'] ) ? (float)$bin['heart_rate_min'] : null,
				isset( $bin['heart_rate_max'] ) ? (float)$bin['heart_rate_max'] : null,
				'background', $datauuid ?: null
			);
			$ok ? $result['inserted']++ : $result['duplicate']++;

			if( ++$sinceCommit >= HEALTH_HR_RAW_BATCH_SIZE ) {
				$gBitDb->CompleteTrans();
				$gBitDb->StartTrans();
				$sinceCommit = 0;
			}
		}
	}

	$gBitDb->CompleteTrans();
	return $result;
}

/**
 * Load the exercise source (live_data) and insert every heart_rate-bearing
 * sample as its own row.
 *
 * @return array{inserted:int,duplicate:int,rowsNoHrData:int,errors:string[]}
 */
function healthImportHRRawExercise( string $pCsvFile, string $pJsonBaseDir ): array {
	global $gBitDb;
	$result = [ 'inserted' => 0, 'duplicate' => 0, 'rowsNoHrData' => 0, 'errors' => [] ];

	if( !is_readable( $pCsvFile ) ) {
		$result['errors'][] = "Can't read $pCsvFile";
		return $result;
	}

	$tz = new \DateTimeZone( 'UTC' );
	$P  = 'com.samsung.health.exercise.';
	$sinceCommit = 0;
	$gBitDb->StartTrans();

	foreach( healthParseSamsungCsv( $pCsvFile ) as $row ) {
		$filename = trim( $row[$P.'live_data'] ?? '' );
		$datauuid = trim( $row[$P.'datauuid'] ?? '' );
		if( $filename === '' ) {
			$result['rowsNoHrData']++;
			continue;
		}

		$samples = healthLoadBinningData( $pJsonBaseDir, $filename );
		$hadHr = false;
		foreach( $samples as $s ) {
			if( !isset( $s['heart_rate'], $s['start_time'] ) || (float)$s['heart_rate'] <= 0 ) {
				continue;
			}
			$hadHr = true;
			$start = ( new \DateTime( '@'.intdiv( (int)$s['start_time'], 1000 ) ) )->setTimezone( $tz );

			$ok = healthStoreHRRaw( $start, null, (float)$s['heart_rate'], null, null, 'exercise', $datauuid ?: null );
			$ok ? $result['inserted']++ : $result['duplicate']++;

			if( ++$sinceCommit >= HEALTH_HR_RAW_BATCH_SIZE ) {
				$gBitDb->CompleteTrans();
				$gBitDb->StartTrans();
				$sinceCommit = 0;
			}
		}
		if( !$hadHr ) {
			$result['rowsNoHrData']++;
		}
	}

	$gBitDb->CompleteTrans();
	return $result;
}

/**
 * Run the full raw HR sync: wipe HEALTH_HR_RAW, reload both sources.
 *
 * @return array{inserted:int,duplicate:int,rowsSkipped:int,errors:string[]}
 */
function healthImportHRRaw( string $pPulseCsvFile, string $pPulseJsonDir, string $pExerciseCsvFile, string $pExerciseJsonDir ): array {
	global $gBitDb;

	$gBitDb->query( 'DELETE FROM health_hr_raw' );

	$bg = healthImportHRRawBackground( $pPulseCsvFile, $pPulseJsonDir );
	$ex = healthImportHRRawExercise( $pExerciseCsvFile, $pExerciseJsonDir );

	return [
		'inserted'    => $bg['inserted'] + $ex['inserted'],
		'duplicate'   => $bg['duplicate'] + $ex['duplicate'],
		'rowsSkipped' => $bg['rowsNoBinning'] + $ex['rowsNoHrData'],
		'errors'      => array_merge( $bg['errors'], $ex['errors'] ),
	];
}
