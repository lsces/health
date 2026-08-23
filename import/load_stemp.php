<?php
/**
 * Import STEMP (half-hour skin-temperature slot summaries) from Samsung
 * Health's skin_temperature export — see ImportSkinTemperature.php's own
 * docblock for the full shape and scoping.
 *
 * Expects, in HEALTH_IMPORT_PATH (storage/health/):
 *   com.samsung.health.skin_temperature.<date>.csv
 *   jsons/com.samsung.health.skin_temperature/<first-char>/<uuid>....json
 * (copy both from a health_lester_<date> split). Safe to re-run.
 *
 * @package health
 */

require_once '../../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'health' );
$gBitSystem->verifyPermission( 'p_health_admin' );

require_once __DIR__.'/ImportSkinTemperature.php';

$csvFile = healthFindLatestSamsungCsv( HEALTH_IMPORT_PATH, 'com.samsung.health.skin_temperature' );
$jsonDir = HEALTH_IMPORT_PATH.'jsons/com.samsung.health.skin_temperature/';

if( !$csvFile ) {
	$result = [ 'created' => 0, 'skipped' => 0, 'rowsNoBinning' => 0,
		'errors' => [ 'No com.samsung.health.skin_temperature.*.csv found in '.HEALTH_IMPORT_PATH ] ];
} else {
	$result = healthImportSkinTemperature( $csvFile, $jsonDir );
}

$gBitSmarty->assign( 'csvFile', $csvFile ?? '(none found)' );
$gBitSmarty->assign( 'created', $result['created'] );
$gBitSmarty->assign( 'skipped', $result['skipped'] );
$gBitSmarty->assign( 'rowsNoBinning', $result['rowsNoBinning'] );
$gBitSmarty->assign( 'errors',  $result['errors'] );

$gBitSystem->display( 'bitpackage:health/import_results.tpl', 'Import STEMP' );
