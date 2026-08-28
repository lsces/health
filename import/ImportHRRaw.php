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
 * Incremental, not full-refresh: no wipe - START_TIME's own PRIMARY KEY
 * does the dedup work (a timestamp already present just fails the insert,
 * caught and counted as a duplicate rather than crashing the run - see
 * healthStoreHRRaw()). Safe to re-run any time a new/updated export lands;
 * going forward only the new period's files need staging, not the full
 * history every time.
 *
 * Expects, in HEALTH_IMPORT_PATH (storage/health/):
 *   com.samsung.shealth.tracker.heart_rate.<date>.csv + its jsons/ blobs
 *   com.samsung.shealth.exercise.<date>.csv + its jsons/ blobs (live_data only needed)
 * (copy all four from a health_name_<date> split). Reuses ImportPulse.php's
 * shared Samsung CSV/binning helpers.
 *
 * @package health
 */

require_once __DIR__.'/ImportPulse.php'; // shared Samsung CSV/binning helpers

const HEALTH_HR_RAW_BATCH_SIZE = 5000; // rows per commit

/**
 * Insert one raw HR row. Returns TRUE on success, FALSE if a row already
 * exists for this exact START_TIME (the table's own primary key - a
 * single-column key, deliberately: background and exercise sources are
 * assumed never to land on the exact same instant, so no source-qualified
 * check is needed here - see RebuildHRDerived.php's own docblock).
 *
 * **Checks first rather than catching the PK-violation exception (fixed
 * 2026-08-27)**: the original exception-driven approach reset the whole
 * transaction (CompleteTrans()+StartTrans()) on every single duplicate,
 * because a failed statement leaves this ADOdb/PDO driver's transaction
 * unusable for further inserts otherwise. Fine when duplicates are rare
 * (a first-time import of genuinely new data), catastrophic on a re-run
 * against data that's mostly already present - confirmed live: a ~15,500-row
 * 2026-only re-run against the already-2.47M-row table never completed in
 * 28+ minutes (thousands of forced fsync-commits, one per duplicate), while
 * the exact same code against 2024's near-empty data returned in 0.15s. A
 * plain indexed SELECT before the insert avoids the exception path (and its
 * transaction-reset cost) for every duplicate, at the cost of one extra
 * cheap read per row - net a massive win whenever re-running against
 * substantially-already-imported data, which is the normal case for this
 * importer (Samsung's export is always a full history, never incremental).
 */
function healthStoreHRRaw( \DateTime $pStart, ?\DateTime $pEnd, float $pHeartRate, ?float $pMin, ?float $pMax, string $pSource, ?string $pDatauuid ): bool {
	global $gBitDb;

	$startStr = $pStart->format( 'Y-m-d H:i:s' );

	$existing = $gBitDb->getOne(
		"SELECT `start_time` FROM `health_hr_raw` WHERE `start_time` = ?",
		[ $startStr ]
	);
	if( $existing ) {
		return false;
	}

	$row = [
		'start_time' => $startStr,
		'end_time'   => $pEnd ? $pEnd->format( 'Y-m-d H:i:s' ) : null,
		'heart_rate' => $pHeartRate,
		'heart_rate_min' => $pMin,
		'heart_rate_max' => $pMax,
		'source'     => $pSource,
		'datauuid'   => $pDatauuid,
	];

	try {
		return (bool)$gBitDb->associateInsert( 'health_hr_raw', $row );
	} catch( \Throwable $e ) {
		// Still possible in principle (a genuine race between the SELECT
		// above and this INSERT, two rows sharing a start_time within the
		// same batch before either commits) - same defensive transaction
		// reset as before, just no longer the normal/expected path.
		$gBitDb->CompleteTrans();
		$gBitDb->StartTrans();
		return false;
	}
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
 * Run the raw HR sync against both sources. **Incremental, not a wipe-and-
 * reload** - relies entirely on START_TIME's own PRIMARY KEY to do the
 * dedup work: a timestamp already in the table from a previous run just
 * fails the insert and gets caught/counted as a duplicate (see
 * healthStoreHRRaw()), a genuinely new one inserts cleanly. Same code path
 * serves both the first full-history backfill and every future top-up
 * against just the current month's export - no need to distinguish them,
 * and no risk of a routine future run silently deleting history that
 * happens not to be in whatever's currently staged in storage/health/.
 *
 * @return array{inserted:int,duplicate:int,rowsSkipped:int,errors:string[]}
 */
function healthImportHRRaw( string $pPulseCsvFile, string $pPulseJsonDir, string $pExerciseCsvFile, string $pExerciseJsonDir ): array {
	$bg = healthImportHRRawBackground( $pPulseCsvFile, $pPulseJsonDir );
	$ex = healthImportHRRawExercise( $pExerciseCsvFile, $pExerciseJsonDir );

	return [
		'inserted'    => $bg['inserted'] + $ex['inserted'],
		'duplicate'   => $bg['duplicate'] + $ex['duplicate'],
		'rowsSkipped' => $bg['rowsNoBinning'] + $ex['rowsNoHrData'],
		'errors'      => array_merge( $bg['errors'], $ex['errors'] ),
	];
}
