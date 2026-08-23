<?php
/**
 * Populate HEALTH_HR_RAW from both Samsung HR sources - see
 * ImportHRRaw.php's own docblock and admin/upgrades/hr_raw_table_upgrade.sql
 * for the full reasoning.
 *
 * Expects, in HEALTH_IMPORT_PATH (storage/health/):
 *   com.samsung.shealth.tracker.heart_rate.<date>.csv + its jsons/ blobs
 *   com.samsung.shealth.exercise.<date>.csv + its jsons/ blobs
 * (copy all four from a health_lester_<date> split - for a routine future
 * top-up, just the current period's export is fine, no need to keep
 * re-uploading full history). Incremental: safe to re-run, START_TIME's own
 * PRIMARY KEY does the dedup work, no wipe. First full-history backfill:
 * ~2.5 million rows across both sources - this will take a genuinely long
 * time, longer than any import run so far. A routine monthly top-up
 * against just the new period will be far quicker.
 *
 * @package health
 */

require_once '../../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'health' );
$gBitSystem->verifyPermission( 'p_health_admin' );

require_once __DIR__.'/ImportHRRaw.php';

set_time_limit( 0 );

$pulseCsv      = healthFindLatestPulseCsv( HEALTH_IMPORT_PATH );
$pulseJsons    = HEALTH_IMPORT_PATH.'jsons/com.samsung.shealth.tracker.heart_rate/';
$exerciseCsv   = healthFindLatestSamsungCsv( HEALTH_IMPORT_PATH, 'com.samsung.shealth.exercise' );
$exerciseJsons = HEALTH_IMPORT_PATH.'jsons/com.samsung.shealth.exercise/';

if( !$pulseCsv || !$exerciseCsv ) {
	$missing = [];
	if( !$pulseCsv ) {
		$missing[] = 'com.samsung.shealth.tracker.heart_rate.*.csv';
	}
	if( !$exerciseCsv ) {
		$missing[] = 'com.samsung.shealth.exercise.*.csv';
	}
	$result = [ 'inserted' => 0, 'duplicate' => 0, 'rowsSkipped' => 0,
		'errors' => [ 'Not found in '.HEALTH_IMPORT_PATH.': '.implode( ', ', $missing ) ] ];
} else {
	$result = healthImportHRRaw( $pulseCsv, $pulseJsons, $exerciseCsv, $exerciseJsons );
}

$gBitSmarty->assign( 'csvFile', ( $pulseCsv ?? '(none found)' ).' + '.( $exerciseCsv ?? '(none found)' ) );
$gBitSmarty->assign( 'created', $result['inserted'] );
$gBitSmarty->assign( 'skipped', $result['duplicate'] );
$gBitSmarty->assign( 'rowsNoBinning', $result['rowsSkipped'] );
$gBitSmarty->assign( 'errors',  $result['errors'] );

$gBitSystem->display( 'bitpackage:health/import_results.tpl', 'Import HR Raw' );
