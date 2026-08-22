<?php
/**
 * Import SLEEP (one row per sleep session) from Samsung Health's sleep
 * export — see ImportSleep.php's own docblock for the multi-session-per-
 * night handling and BST/GMT resolution.
 *
 * Expects com.samsung.shealth.sleep.<date>.csv in HEALTH_IMPORT_PATH
 * (storage/health/) — copy it from a health_lester_<date> split. Safe to
 * re-run.
 *
 * @package health
 */

require_once '../../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'health' );
$gBitSystem->verifyPermission( 'p_health_admin' );

require_once __DIR__.'/ImportSleep.php';

$csvFile = healthFindLatestSamsungCsv( HEALTH_IMPORT_PATH, 'com.samsung.shealth.sleep' );

if( !$csvFile ) {
	$result = [ 'created' => 0, 'skipped' => 0,
		'errors' => [ 'No com.samsung.shealth.sleep.*.csv found in '.HEALTH_IMPORT_PATH ] ];
} else {
	$result = healthImportSleep( $csvFile );
}

$gBitSmarty->assign( 'csvFile', $csvFile ?? '(none found)' );
$gBitSmarty->assign( 'created', $result['created'] );
$gBitSmarty->assign( 'skipped', $result['skipped'] );
$gBitSmarty->assign( 'errors',  $result['errors'] );

$gBitSystem->display( 'bitpackage:health/import_results.tpl', 'Import SLEEP' );
