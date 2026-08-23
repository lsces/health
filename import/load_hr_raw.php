<?php
/**
 * Populate HEALTH_HR_RAW from both Samsung HR sources - see
 * ImportHRRaw.php's own docblock and admin/upgrades/5.0.2.php for the full
 * reasoning.
 *
 * Expects, in HEALTH_IMPORT_PATH (storage/health/) or, with ?year=YYYY,
 * storage/health/history/YYYY/:
 *   com.samsung.shealth.tracker.heart_rate.<date>.csv + its jsons/ blobs
 *   com.samsung.shealth.exercise.<date>.csv + its jsons/ blobs
 * Either source may genuinely be absent for a given year (e.g. 2024 has no
 * background tracker.heart_rate data at all) - that's not an error, just
 * fewer rows from that pass. Incremental: safe to re-run, START_TIME's own
 * PRIMARY KEY does the dedup work, no wipe. A full-history run in one pass
 * proved too slow (see health/CLAUDE.md) - ?year=YYYY runs one year's chunk
 * at a time instead, same idempotent importer either way.
 *
 * @package health
 */

require_once '../../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'health' );
$gBitSystem->verifyPermission( 'p_health_admin' );

require_once __DIR__.'/ImportHRRaw.php';

set_time_limit( 0 );

// Optional ?year=YYYY to stage a single year's chunk from storage/health/history/YYYY/
// instead of the flat storage/health/ - see health/CLAUDE.md's staged-import entries for why
// (the full-history run in one pass was too slow; re-running a year at a time against this
// incremental, PK-deduped importer is safe and idempotent either way).
$importDir = HEALTH_IMPORT_PATH;
if( !empty( $_GET['year'] ) && preg_match( '/^\d{4}$/', $_GET['year'] ) ) {
	$importDir = HEALTH_IMPORT_PATH.'history/'.$_GET['year'].'/';
}

$pulseCsv      = healthFindLatestPulseCsv( $importDir );
$pulseJsons    = $importDir.'jsons/com.samsung.shealth.tracker.heart_rate/';
$exerciseCsv   = healthFindLatestSamsungCsv( $importDir, 'com.samsung.shealth.exercise' );
$exerciseJsons = $importDir.'jsons/com.samsung.shealth.exercise/';

if( !$pulseCsv && !$exerciseCsv ) {
	$result = [ 'inserted' => 0, 'duplicate' => 0, 'rowsSkipped' => 0,
		'errors' => [ 'Neither source found in '.$importDir ] ];
} else {
	// A year genuinely missing one source (e.g. 2024 has no background tracker.heart_rate
	// data at all - see health/CLAUDE.md) is expected, not an error; healthImportHRRaw*()'s
	// own is_readable() check on an empty path just yields a clean 0-rows-processed result.
	$result = healthImportHRRaw( $pulseCsv ?? '', $pulseJsons, $exerciseCsv ?? '', $exerciseJsons );
}

$gBitSmarty->assign( 'csvFile', ( $pulseCsv ?? '(none found)' ).' + '.( $exerciseCsv ?? '(none found)' ) );
$gBitSmarty->assign( 'created', $result['inserted'] );
$gBitSmarty->assign( 'skipped', $result['duplicate'] );
$gBitSmarty->assign( 'rowsNoBinning', $result['rowsSkipped'] );
$gBitSmarty->assign( 'errors',  $result['errors'] );

$gBitSystem->display( 'bitpackage:health/import_results.tpl', 'Import HR Raw' );
