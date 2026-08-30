<?php
/**
 * Import SLEEP xref rows from Samsung Health's sleep export.
 *
 * Expects `com.samsung.shealth.sleep.<date>.csv` in HEALTH_IMPORT_PATH
 * (storage/health/) — copy it from a `health_name_<date>` split.
 *
 * **One row per sleep *session*, not per day** — checked real data first: a
 * single night can have several sleep rows (a real example: 27→28/06/2026
 * had three — a short evening nap, the main overnight sleep, and another
 * the following evening), and none of them cleanly matched the legacy
 * spreadsheet's single "Sleep Score" figure for that date. Rather than
 * invent an unverified "which session counts as the day's sleep" rule,
 * every session gets imported as its own row — same "don't reduce at import
 * time" principle already used for WT/BP/PULSE/OXI. Picking/aggregating a
 * day's headline sleep figure is a query-time concern for whatever builds
 * the day-summary rollup, not this importer's job.
 *
 * A session is assigned to the **local (Europe/London) calendar date its own
 * start_time falls on** — e.g. a sleep starting 23:03 and ending 06:19 the
 * next morning belongs to the day it started, not the day it ended.
 *
 * **BST/GMT handling — fixed 2026-08-27**: `start_time` is parsed as UTC, not
 * Europe/London. Samsung's own `start_time` for this CSV is already the
 * UTC-equivalent value (confirmed cross-checking against HealthForYou's
 * independent log of the same reading for BP — see ImportBPSamsung.php's
 * docblock); parsing with Europe/London here double-subtracted the BST hour,
 * same bug, same fix. The row's own `time_offset` is still ignored, but not
 * because it's redundant with a local-time conversion — start_time needs no
 * conversion at all. `end_time` isn't actually read by this importer despite
 * the "both ends" framing above (only `sleep_duration` minutes is stored) —
 * that stale claim predates this fix, left as-is since fixing it isn't in
 * scope here.
 *
 * `xkey`=sleep_score, `xkey_ext`=sleep_duration (minutes), `data`=json
 * `{efficiency}`.
 *
 * @package health
 */

require_once __DIR__.'/ImportPulse.php'; // healthParseSamsungCsv(), healthFindLatestSamsungCsv()

use Bitweaver\Health\HealthDay;
use Bitweaver\Liberty\LibertyContent;

/**
 * Insert a SLEEP xref row for one session, unless one already exists for
 * this exact content_id + start_date. start_date carries the session's own
 * start timestamp; entry_date is left to LibertyXref's own default (when the
 * row was created).
 *
 * @param  int   $pContentId  The day's HealthDay content_id.
 * @param  int   $pTimestamp  Unix timestamp of the session start (UTC).
 * @param  float $pScore
 * @param  float $pDurationMinutes
 * @param  array $pDetail          ['efficiency'=>..]
 * @return bool  TRUE if a new row was inserted, FALSE if one already existed (skipped).
 */
function healthStoreSleep( int $pContentId, int $pTimestamp, float $pScore, float $pDurationMinutes, array $pDetail ): bool {
	return LibertyContent::insertXrefReadingIfNew( $pContentId, 'SLEEP', $pTimestamp, [
		'xkey'     => (string)$pScore,
		'xkey_ext' => (string)$pDurationMinutes,
		'edit'     => json_encode( $pDetail ),
	] );
}

/**
 * Run the full sleep import.
 *
 * @param  string $pCsvFile
 * @return array{created:int,skipped:int,errors:string[]}
 */
function healthImportSleep( string $pCsvFile ): array {
	$result = [ 'created' => 0, 'skipped' => 0, 'errors' => [] ];

	if( !is_readable( $pCsvFile ) ) {
		$result['errors'][] = "Can't read $pCsvFile";
		return $result;
	}

	$tz = new \DateTimeZone( 'UTC' );

	foreach( healthParseSamsungCsv( $pCsvFile ) as $rowNum => $row ) {
		$startStr = $row['com.samsung.health.sleep.start_time'] ?? '';
		if( !$startStr ) {
			$result['errors'][] = "Row $rowNum: no start_time";
			continue;
		}
		try {
			$start = new \DateTime( $startStr, $tz );
		} catch( \Exception $e ) {
			$result['errors'][] = "Row $rowNum: unparseable start_time '$startStr'";
			continue;
		}

		$score = (float)( $row['sleep_score'] ?? 0 );
		if( $score <= 0 ) {
			$result['errors'][] = "Row $rowNum: no sleep_score";
			continue;
		}

		$detail = [];
		if( !empty( $row['efficiency'] ) ) {
			$detail['efficiency'] = round( (float)$row['efficiency'], 1 );
		}

		$date = $start->format( 'Y-m-d' );
		$day  = HealthDay::findOrCreate( $date );
		if( healthStoreSleep( $day->mContentId, $start->getTimestamp(), $score, (float)( $row['sleep_duration'] ?? 0 ), $detail ) ) {
			$result['created']++;
		} else {
			$result['skipped']++;
		}
	}

	return $result;
}
