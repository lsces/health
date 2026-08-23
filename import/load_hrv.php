<?php
/**
 * Import HRV (half-hour heart-rate-variability slot summaries) from Samsung
 * Health's hrv export — see ImportHRV.php's own docblock for the full shape
 * and scoping.
 *
 * Expects, in HEALTH_IMPORT_PATH (storage/health/):
 *   com.samsung.health.hrv.<date>.csv
 *   jsons/com.samsung.health.hrv/<first-char>/<uuid>....json
 * (copy both from a health_lester_<date> split). Safe to re-run.
 *
 * @package health
 */

require_once '../../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'health' );
$gBitSystem->verifyPermission( 'p_health_admin' );

require_once __DIR__.'/ImportHRV.php';

$csvFile = healthFindLatestSamsungCsv( HEALTH_IMPORT_PATH, 'com.samsung.health.hrv' );
$jsonDir = HEALTH_IMPORT_PATH.'jsons/com.samsung.health.hrv/';

if( !$csvFile ) {
	$result = [ 'created' => 0, 'skipped' => 0, 'rowsNoBinning' => 0,
		'errors' => [ 'No com.samsung.health.hrv.*.csv found in '.HEALTH_IMPORT_PATH ] ];
} else {
	$result = healthImportHRV( $csvFile, $jsonDir );
}

$gBitSmarty->assign( 'csvFile', $csvFile ?? '(none found)' );
$gBitSmarty->assign( 'created', $result['created'] );
$gBitSmarty->assign( 'skipped', $result['skipped'] );
$gBitSmarty->assign( 'rowsNoBinning', $result['rowsNoBinning'] );
$gBitSmarty->assign( 'errors',  $result['errors'] );

$gBitSystem->display( 'bitpackage:health/import_results.tpl', 'Import HRV' );
