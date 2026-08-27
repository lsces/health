<?php
/**
 * Import OXI xref rows from Samsung Health's own tracker.oxygen_saturation
 * export — real, calibrated SpO2%+pulse readings, one row per instant.
 *
 * **Not** `com.samsung.health.oxygen_saturation.raw` — checked that file
 * first: its `channel` bin values are uncalibrated sensor noise (e.g.
 * `2.7e-20`, `-1.18e-38`), not usable SpO2 data at all. This tracker export
 * is the real, already-processed one — `com.samsung.health.oxygen_saturation.
 * spo2`/`.heart_rate` per row, no binning_data/jsons blobs needed, same flat
 * per-reading shape HealthForYou's own OXI export already has. 633 rows in
 * the 2026-08-14 export, single source (`com.sec.android.app.shealth`), no
 * watch/cuff split the way BP has.
 *
 * Expects `com.samsung.shealth.tracker.oxygen_saturation.<date>.csv` in
 * HEALTH_IMPORT_PATH (storage/health/) — copy it from a `health_lester_<date>`
 * split. Reuses `healthStoreOxi()` from `ImportOxi.php` (the storage/dedupe
 * logic is source-agnostic) and `healthParseSamsungCsv()`/
 * `healthFindLatestSamsungCsv()` from `ImportPulse.php`, same pattern
 * `ImportBPSamsung.php` already uses against `ImportBP.php`.
 *
 * **Parsed as UTC, not Europe/London (fixed 2026-08-27)** — Samsung's own
 * `start_time` for this CSV is already the UTC-equivalent value, same bug
 * and same fix as ImportBPSamsung.php (see that file's docblock for the
 * cross-check that established this). Applying Europe/London here
 * double-subtracted the BST hour, landing every BST-period reading an hour
 * early.
 *
 * @package health
 */

require_once __DIR__.'/ImportOxi.php';   // healthStoreOxi()
require_once __DIR__.'/ImportPulse.php'; // healthParseSamsungCsv(), healthFindLatestSamsungCsv()

use Bitweaver\Health\HealthDay;

/**
 * Run the full Samsung tracker.oxygen_saturation import.
 *
 * @param  string $pCsvFile
 * @return array{created:int,skipped:int,errors:string[]}
 */
function healthImportOxiSamsung( string $pCsvFile ): array {
	$result = [ 'created' => 0, 'skipped' => 0, 'errors' => [] ];

	if( !is_readable( $pCsvFile ) ) {
		$result['errors'][] = "Can't read $pCsvFile";
		return $result;
	}

	$tz = new \DateTimeZone( 'UTC' );
	$P  = 'com.samsung.health.oxygen_saturation.';

	foreach( healthParseSamsungCsv( $pCsvFile ) as $rowNum => $row ) {
		$startStr = $row[$P.'start_time'] ?? '';
		$spo2     = (float)( $row[$P.'spo2'] ?? 0 );
		if( !$startStr || $spo2 <= 0 ) {
			continue;
		}

		try {
			$start = new \DateTime( $startStr, $tz );
		} catch( \Exception $e ) {
			$result['errors'][] = "Row $rowNum: unparseable start_time '$startStr'";
			continue;
		}

		$pulse  = (int)round( (float)( $row[$P.'heart_rate'] ?? 0 ) );
		$detail = [ 'source' => 'watch' ];
		if( !empty( $row['tag_id'] ) ) {
			$detail['tag_id'] = $row['tag_id'];
		}

		$date = $start->format( 'Y-m-d' );
		$day  = HealthDay::findOrCreate( $date );
		if( healthStoreOxi( $day->mContentId, $start->getTimestamp(), $spo2, $pulse, $detail ) ) {
			$result['created']++;
		} else {
			$result['skipped']++;
		}
	}

	return $result;
}
