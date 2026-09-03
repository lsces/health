<?php
/**
 * Import OXIDESAT from Samsung Health's own tracker.oxygen_saturation
 * export — see ImportOxiDesat.php's own docblock for the reduction logic
 * and why it's a companion to OXI, not a replacement.
 *
 * Expects, in HEALTH_IMPORT_PATH (storage/health/):
 *   com.samsung.shealth.tracker.oxygen_saturation.<date>.csv
 *   jsons/com.samsung.shealth.tracker.oxygen_saturation/<first-char>/<uuid>....json
 * (copy both from a health_name_<date> split). Safe to re-run.
 *
 * @package health
 */

require_once '../../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'health' );
$gBitSystem->verifyPermission( 'p_health_admin' );

require_once __DIR__.'/ImportOxiDesat.php';

$csvFile = healthFindLatestSamsungCsv( HEALTH_IMPORT_PATH, 'com.samsung.shealth.tracker.oxygen_saturation' );
$jsonDir = HEALTH_IMPORT_PATH.'jsons/com.samsung.shealth.tracker.oxygen_saturation/';

if( !$csvFile ) {
	$result = [ 'created' => 0, 'skipped' => 0, 'rowsNoBinning' => 0,
		'errors' => [ 'No com.samsung.shealth.tracker.oxygen_saturation.*.csv found in '.HEALTH_IMPORT_PATH ] ];
} else {
	$result = healthImportOxiDesat( $csvFile, $jsonDir );
}

$gBitSmarty->assign( 'csvFile', $csvFile ?? '(none found)' );
$gBitSmarty->assign( 'created', $result['created'] );
$gBitSmarty->assign( 'skipped', $result['skipped'] );
$gBitSmarty->assign( 'rowsNoBinning', $result['rowsNoBinning'] );
$gBitSmarty->assign( 'errors',  $result['errors'] );

$gBitSystem->display( 'bitpackage:health/import_results.tpl', 'Import OXIDESAT' );
