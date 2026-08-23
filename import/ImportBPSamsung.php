<?php
/**
 * Import BP xref rows from Samsung Health's own blood_pressure export — both
 * sources it contains, distinguished by `pkg_name`:
 *
 *   - `com.samsung.android.shealthmonitor` (watch-PPG, ~391 rows) — tagged
 *     `source:'watch'`. Carries a `calibration_id` on every row (100%),
 *     included as detail so a future day view can judge how stale
 *     calibration was for a given reading. A few real free-text `comment`s
 *     exist only here (e.g. "After physio").
 *   - `com.sec.android.app.shealth` (cuff-synced, ~2,670 real rows after
 *     dropping known device-setup placeholder rows) — tagged `source:'cuff'`,
 *     same tag HealthForYou's own BP import uses (it's the same physical
 *     device/method). **Checked before assuming this was redundant with
 *     HealthForYou's own BP import**: this Samsung data has real duplicate
 *     inflation (the cuff monitor's 150-reading onboard buffer got fully
 *     re-synced on every connection before a fix landed — see
 *     project_health_package_scoping memory) — collapsed via
 *     `(start_time,systolic,diastolic,pulse)` down from ~2,670 raw rows to
 *     ~806 truly unique readings. Compared those against what HealthForYou's
 *     own export already covers: **~441 of the 806 (over half) aren't there
 *     at all** — HealthForYou's own export doesn't retain everything Samsung
 *     Health ends up syncing. Genuinely additive, not redundant — built
 *     rather than skipped.
 *
 * Rows dated before 2024-06-29 (confirmed phone-acquisition date) are
 * dropped outright — the only such rows are 4 device-setup placeholder
 * entries at 2023-01-01 00:00–00:03, not real readings.
 *
 * **Minute-truncated for dedup, not second-precise** — HealthForYou's own
 * timestamps have no seconds (always :00), Samsung's do. Truncating
 * Samsung's `start_time` to the minute before computing `start_date` (the
 * dedupe key `healthStoreBP()` matches on) means a Samsung reading landing
 * in the same minute as an already-imported HealthForYou reading correctly
 * skips as a duplicate instead of being re-inserted as a false new one
 * purely because its seconds differ.
 *
 * Each reading is a single instant, not a session — no BST/GMT two-ends
 * problem the way sleep sessions have, but resolved via Europe/London
 * directly anyway (ignoring the row's own `time_offset`), same convention as
 * every other Samsung timestamp this package parses.
 *
 * Expects `com.samsung.shealth.blood_pressure.<date>.csv` in HEALTH_IMPORT_PATH
 * (storage/health/) — copy it from a `health_lester_<date>` split. Reuses
 * `healthStoreBP()` from `ImportBP.php` (the storage/dedupe logic is source-
 * agnostic) and `healthParseSamsungCsv()`/`healthFindLatestSamsungCsv()` from
 * `ImportPulse.php`.
 *
 * @package health
 */

require_once __DIR__.'/ImportBP.php';    // healthStoreBP()
require_once __DIR__.'/ImportPulse.php'; // healthParseSamsungCsv(), healthFindLatestSamsungCsv()

use Bitweaver\Health\HealthDay;

const HEALTH_BP_WATCH_PKG = 'com.samsung.android.shealthmonitor';
const HEALTH_BP_CUFF_PKG  = 'com.sec.android.app.shealth';
const HEALTH_BP_EARLIEST  = '2024-06-29'; // confirmed phone-acquisition date

/**
 * Store one BP reading, given an already-parsed local start DateTime.
 *
 * @param  \DateTime $pStart
 * @param  float     $pSys
 * @param  float     $pDia
 * @param  array     $pDetail
 * @return bool  TRUE if a new row was inserted, FALSE if skipped (dup or bad data).
 */
function healthStoreBPSamsungRow( \DateTime $pStart, float $pSys, float $pDia, array $pDetail ): bool {
	if( $pSys <= 0 || $pDia <= 0 ) {
		return false;
	}
	$date = $pStart->format( 'Y-m-d' );
	$day  = HealthDay::findOrCreate( $date );
	return healthStoreBP( $day->mContentId, $pStart->getTimestamp(), $pSys, $pDia, $pDetail );
}

/**
 * Run the full Samsung blood_pressure import — both watch-PPG and (deduped,
 * minute-truncated) cuff-synced readings. See this file's own docblock for
 * the reasoning behind importing both.
 *
 * @param  string $pCsvFile
 * @return array{created:int,skipped:int,errors:string[]}
 */
function healthImportBPSamsung( string $pCsvFile ): array {
	$result = [ 'created' => 0, 'skipped' => 0, 'errors' => [] ];

	if( !is_readable( $pCsvFile ) ) {
		$result['errors'][] = "Can't read $pCsvFile";
		return $result;
	}

	$tz = new \DateTimeZone( 'Europe/London' );
	$P  = 'com.samsung.health.blood_pressure.';

	$cuffByKey = []; // dedupe key => row, collapses the buffer-resync duplicates

	foreach( healthParseSamsungCsv( $pCsvFile ) as $rowNum => $row ) {
		$pkg      = $row[$P.'pkg_name'] ?? '';
		$startStr = $row[$P.'start_time'] ?? '';
		if( !$startStr || $startStr < HEALTH_BP_EARLIEST ) {
			continue; // no timestamp, or before phone acquisition (placeholder rows)
		}

		if( $pkg === HEALTH_BP_WATCH_PKG ) {
			try {
				$start = new \DateTime( $startStr, $tz );
			} catch( \Exception $e ) {
				$result['errors'][] = "Row $rowNum: unparseable start_time '$startStr'";
				continue;
			}

			$detail = [ 'source' => 'watch' ];
			if( !empty( $row[$P.'pulse'] ) ) {
				$detail['pulse'] = (int)$row[$P.'pulse'];
			}
			if( !empty( $row[$P.'mean'] ) && (float)$row[$P.'mean'] > 0 ) {
				$detail['map'] = (int)round( (float)$row[$P.'mean'] );
			}
			if( !empty( $row['calibration_id'] ) ) {
				$detail['calibration_id'] = $row['calibration_id'];
			}
			if( !empty( trim( $row[$P.'comment'] ?? '' ) ) ) {
				$detail['comment'] = trim( $row[$P.'comment'] );
			}

			if( healthStoreBPSamsungRow( $start, (float)( $row[$P.'systolic'] ?? 0 ), (float)( $row[$P.'diastolic'] ?? 0 ), $detail ) ) {
				$result['created']++;
			} else {
				$result['skipped']++;
			}
		} elseif( $pkg === HEALTH_BP_CUFF_PKG ) {
			$key = $startStr.'|'.( $row[$P.'systolic'] ?? '' ).'|'.( $row[$P.'diastolic'] ?? '' ).'|'.( $row[$P.'pulse'] ?? '' );
			$cuffByKey[$key] = $row; // last write wins — any of the duplicate copies is fine
		}
	}

	foreach( $cuffByKey as $row ) {
		$startStr = $row[$P.'start_time'];
		try {
			$start = new \DateTime( $startStr, $tz );
		} catch( \Exception $e ) {
			$result['errors'][] = "Unparseable cuff start_time '$startStr'";
			continue;
		}
		// truncate to the minute — see this file's own docblock for why
		$start->setTime( (int)$start->format( 'H' ), (int)$start->format( 'i' ), 0 );

		$detail = [ 'source' => 'cuff' ];
		if( !empty( $row[$P.'pulse'] ) ) {
			$detail['pulse'] = (int)$row[$P.'pulse'];
		}
		if( !empty( $row[$P.'mean'] ) && (float)$row[$P.'mean'] > 0 ) {
			$detail['map'] = (int)round( (float)$row[$P.'mean'] );
		}
		if( !empty( trim( $row[$P.'comment'] ?? '' ) ) ) {
			$detail['comment'] = trim( $row[$P.'comment'] );
		}

		if( healthStoreBPSamsungRow( $start, (float)( $row[$P.'systolic'] ?? 0 ), (float)( $row[$P.'diastolic'] ?? 0 ), $detail ) ) {
			$result['created']++;
		} else {
			$result['skipped']++;
		}
	}

	return $result;
}
