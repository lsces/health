<?php
/**
 * Import STEPTRACK (full intraday step-count track, one row per day) from
 * Samsung Health's step_daily_trend export — see ImportStepTrack.php's own
 * docblock for the full shape and scoping.
 *
 * Expects, in HEALTH_IMPORT_PATH (storage/health/):
 *   com.samsung.shealth.step_daily_trend.<date>.csv
 *   jsons/com.samsung.shealth.step_daily_trend/<first-char>/<uuid>....json
 * (copy both from a health_name_<date> split). Safe to re-run.
 *
 * @package health
 */

require_once '../../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'health' );
$gBitSystem->verifyPermission( 'p_health_admin' );

require_once __DIR__.'/ImportStepTrack.php';

$csvFile = healthFindLatestSamsungCsv( HEALTH_IMPORT_PATH, 'com.samsung.shealth.step_daily_trend' );
$jsonDir = HEALTH_IMPORT_PATH.'jsons/com.samsung.shealth.step_daily_trend/';

if( !$csvFile ) {
	$result = [ 'created' => 0, 'skipped' => 0, 'rowsNoBinning' => 0,
		'errors' => [ 'No com.samsung.shealth.step_daily_trend.*.csv found in '.HEALTH_IMPORT_PATH ] ];
} else {
	$result = healthImportStepTrack( $csvFile, $jsonDir );
}

$gBitSmarty->assign( 'csvFile', $csvFile ?? '(none found)' );
$gBitSmarty->assign( 'created', $result['created'] );
$gBitSmarty->assign( 'skipped', $result['skipped'] );
$gBitSmarty->assign( 'rowsNoBinning', $result['rowsNoBinning'] );
$gBitSmarty->assign( 'errors',  $result['errors'] );

$gBitSystem->display( 'bitpackage:health/import_results.tpl', 'Import STEPTRACK' );
