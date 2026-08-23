<?php
/**
 * Import PULSE (half-hour heart-rate slot summaries) from Samsung Health's
 * tracker.heart_rate export.
 *
 * Expects, in HEALTH_IMPORT_PATH (storage/health/):
 *   com.samsung.shealth.tracker.heart_rate.<date>.csv
 *   jsons/com.samsung.shealth.tracker.heart_rate/<first-char>/<uuid>.com.samsung.health.heart_rate.binning_data.json
 * (copy both from a health_lester_<date> split — see
 * ~/Personal/Health/Samsung Health/split_health.sh). Picks the most recent date
 * suffix present on the CSV, same "find latest export" idea as Food's own
 * importers, just simpler (one file type, not a paired set).
 *
 * **Only CSV rows carrying a binning_data filename are imported** — checked
 * against the real 2026-08-14 export: 4,687 of 33,943 rows (14%) have one, the
 * rest are summary-only (a single row-level heart_rate/min/max with no minute
 * detail). Importing those too would mean inventing an assignment rule for
 * "which half-hour slot(s) does a summary-only, possibly slot-straddling
 * session belong to" that was never part of the design — deliberately
 * deferred, not attempted here, same as Samsung's BP data was deferred from
 * the BP importer. The 4,687 rich rows alone span 517 distinct days
 * (2025-03-16 to the export date), real usable coverage.
 *
 * Each binning_data.json is a flat array of ~1-minute bins (`heart_rate`/
 * `heart_rate_max`/`heart_rate_min`/`start_time`/`end_time`, epoch
 * milliseconds — already unambiguous UTC, unlike the CSV's own start_time
 * column which needs its time_offset; no BST/GMT wrinkle here). Every bin
 * across every session is re-bucketed into a fixed half-hour clock slot
 * (00:00-00:30, 00:30-01:00, ...), aligned to **Europe/London local time**
 * (a "half past two" slot means local wall-clock time, not UTC) — a session
 * bin lands wherever its own timestamp falls, regardless of which CSV row/
 * session it originally came from. A missing slot is not necessarily
 * meaningful on its own (the watch charges off-wrist 1hr+/day) — see
 * health/MANUAL.md.
 *
 * One PULSE xref row per populated slot: xkey = slot average, xkey_ext =
 * low/high json, data = the slot's own bins as json. Safe to re-run: dedupes
 * on (content_id, item, start_date) same as WT/BP, start_date being the
 * slot's own start instant (UTC).
 *
 * @package health
 */

use Bitweaver\Health\HealthDay;
use Bitweaver\Liberty\LibertyXref;

const HEALTH_PULSE_SLOT_SECONDS = 1800; // half hour

/**
 * Find the most recent tracker.heart_rate.<date>.csv in $pDir.
 *
 * @return string|null  Full path, or null if none found.
 */
function healthFindLatestPulseCsv( string $pDir ): ?string {
	$matches = glob( $pDir.'com.samsung.shealth.tracker.heart_rate.*.csv' );
	if( !$matches ) {
		return null;
	}
	sort( $matches ); // date suffix sorts lexically = chronologically
	return end( $matches );
}

/**
 * Find the most recent <basename>.<date>.csv in $pDir, for any Samsung export
 * type — same "date suffix sorts lexically = chronologically" idea as
 * healthFindLatestPulseCsv(), generalised once a second Samsung CSV type
 * needed it (activity.day_summary/vitality_score/sleep).
 *
 * **Anchored, not a loose glob** — `$pBasename.*.csv` also matches any
 * sub-type file sharing the same prefix (found live: 'com.samsung.shealth.
 * exercise' also glob-matched exercise.weather/.extension/.recovery_heart_
 * rate/etc., and sort()+end() silently picked exercise.recovery_heart_rate
 * instead of the real file - wrong shape entirely, every row read back as
 * "no data" rather than a clear error). Only <basename> followed by a pure
 * digit date suffix counts as a match now.
 *
 * @param  string $pDir       HEALTH_IMPORT_PATH, trailing slash included.
 * @param  string $pBasename  e.g. 'com.samsung.shealth.activity.day_summary'.
 * @return string|null
 */
function healthFindLatestSamsungCsv( string $pDir, string $pBasename ): ?string {
	$matches = glob( $pDir.$pBasename.'.*.csv' );
	if( !$matches ) {
		return null;
	}
	$pattern = '/^'.preg_quote( $pBasename, '/' ).'\.\d+\.csv$/';
	$matches = array_filter( $matches, fn( $m ) => preg_match( $pattern, basename( $m ) ) === 1 );
	if( !$matches ) {
		return null;
	}
	sort( $matches );
	return end( $matches );
}

/**
 * Parse a Samsung Health CSV (2-line preamble, then a real header row) into
 * an array of associative rows keyed by header column name. Every data row in
 * these exports carries one trailing blank field the header doesn't declare
 * (confirmed against real data across multiple Samsung CSV types, not a
 * corrupt row) — sliced off before combining, same fix `ImportPulse.php`
 * originally found and fixed inline before this got extracted as a shared
 * helper for STEPS/ENERGY/SLEEP to reuse.
 *
 * @param  string $pFile
 * @return iterable<int, array<string,string>>  Yielded one row at a time —
 *                                               these files can be large (the
 *                                               tracker.heart_rate export is
 *                                               ~8MB/34k rows), don't buffer
 *                                               the whole thing in memory.
 */
function healthParseSamsungCsv( string $pFile ): iterable {
	$fh = fopen( $pFile, 'r' );
	fgets( $fh ); // preamble line
	$header = fgetcsv( $fh, 0, ',', '"', '' );
	$headerCount = count( $header );
	while( ( $row = fgetcsv( $fh, 0, ',', '"', '' ) ) !== false ) {
		if( count( $row ) > $headerCount ) {
			$row = array_slice( $row, 0, $headerCount );
		} elseif( count( $row ) < $headerCount ) {
			continue;
		}
		yield array_combine( $header, $row );
	}
	fclose( $fh );
}

/**
 * Load and decode one binning_data.json file, bucketed by the first character
 * of its own filename — same layout Samsung's own export uses.
 *
 * @param  string $pJsonBaseDir  e.g. HEALTH_IMPORT_PATH.'jsons/com.samsung.shealth.tracker.heart_rate/'
 * @param  string $pFilename     e.g. 'c93272aa-....binning_data.json'
 * @return array  Decoded bins, or [] if unreadable/malformed.
 */
function healthLoadBinningData( string $pJsonBaseDir, string $pFilename ): array {
	$path = $pJsonBaseDir.$pFilename[0].'/'.$pFilename;
	if( !is_readable( $path ) ) {
		return [];
	}
	$decoded = json_decode( (string)file_get_contents( $path ), true );
	return is_array( $decoded ) ? $decoded : [];
}

/**
 * Insert a PULSE xref row for one half-hour slot, unless one already exists
 * for this exact content_id + start_date. Computes its own xorder rather than
 * using LibertyXref's fAddXref path, same reasoning as healthStoreWT().
 * start_date carries the slot's own timestamp; entry_date is left to
 * LibertyXref's own default (when the row was created).
 *
 * @param  int   $pContentId    The day's HealthDay content_id.
 * @param  int   $pSlotStart    Unix timestamp of the slot's start (UTC).
 * @param  float $pAverage
 * @param  float $pLow
 * @param  float $pHigh
 * @param  array $pBins         This slot's own minute-level readings, as-is from source.
 * @return bool  TRUE if a new row was inserted, FALSE if one already existed (skipped).
 */
function healthStorePulseSlot( int $pContentId, int $pSlotStart, float $pAverage, float $pLow, float $pHigh, array $pBins ): bool {
	global $gBitDb;

	$startDate = gmdate( 'Y-m-d H:i:s', $pSlotStart );
	$existing = $gBitDb->getOne(
		"SELECT `xref_id` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'PULSE' AND `start_date` = ?",
		[ $pContentId, $startDate ]
	);
	if( $existing ) {
		return false;
	}

	$nextXorder = (int)$gBitDb->getOne(
		"SELECT COALESCE( MAX(`xorder`) + 1, 0 ) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'PULSE'",
		[ $pContentId ]
	);

	$pHash = [
		'content_id' => $pContentId,
		'item'       => 'PULSE',
		'xorder'     => $nextXorder,
		'xkey'       => (string)round( $pAverage, 1 ),
		'xkey_ext'   => json_encode( [ 'low' => $pLow, 'high' => $pHigh ] ),
		'edit'       => json_encode( $pBins ),
		'start_date' => $pSlotStart,
	];
	$xref = new LibertyXref();
	$xref->store( $pHash );
	return true;
}

/**
 * Run the full tracker.heart_rate import: read the CSV, pull every row's
 * binning_data (rows without one are counted as skipped, not processed — see
 * this file's own docblock), re-bucket every minute-bin into half-hour
 * Europe/London slots, and store one PULSE row per populated slot.
 *
 * @param  string $pCsvFile
 * @param  string $pJsonBaseDir  Directory containing the per-first-char subfolders.
 * @return array{created:int,skipped:int,rowsNoBinning:int,errors:string[]}
 */
function healthImportPulse( string $pCsvFile, string $pJsonBaseDir ): array {
	$result = [ 'created' => 0, 'skipped' => 0, 'rowsNoBinning' => 0, 'errors' => [] ];

	if( !is_readable( $pCsvFile ) ) {
		$result['errors'][] = "Can't read $pCsvFile";
		return $result;
	}

	$tz = new \DateTimeZone( 'Europe/London' );

	// slotKey => [ 'date' => 'Y-m-d', 'slotStart' => unix ts, 'bins' => [...] ]
	$slots = [];

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
			if( !isset( $bin['start_time'], $bin['heart_rate'] ) ) {
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
				'heart_rate'     => (float)$bin['heart_rate'],
				'heart_rate_min' => (float)( $bin['heart_rate_min'] ?? $bin['heart_rate'] ),
				'heart_rate_max' => (float)( $bin['heart_rate_max'] ?? $bin['heart_rate'] ),
				'start_time'     => (int)$bin['start_time'],
			];
		}
	}

	foreach( $slots as $slot ) {
		$rates = array_column( $slot['bins'], 'heart_rate' );
		$lows  = array_column( $slot['bins'], 'heart_rate_min' );
		$highs = array_column( $slot['bins'], 'heart_rate_max' );

		$day = HealthDay::findOrCreate( $slot['date'] );
		if( healthStorePulseSlot(
			$day->mContentId, $slot['slotStart'],
			array_sum( $rates ) / count( $rates ), min( $lows ), max( $highs ),
			$slot['bins']
		) ) {
			$result['created']++;
		} else {
			$result['skipped']++;
		}
	}

	return $result;
}
