<?php
/**
 * Import RESP (half-hour respiratory-rate slot summaries) from Samsung
 * Health's respiratory_rate export — see ImportRespiratoryRate.php's own
 * docblock for the full shape and scoping.
 *
 * Expects, in HEALTH_IMPORT_PATH (storage/health/):
 *   com.samsung.health.respiratory_rate.<date>.csv
 *   jsons/com.samsung.health.respiratory_rate/<first-char>/<uuid>....json
 * (copy both from a health_lester_<date> split). Safe to re-run.
 *
 * @package health
 */

require_once '../../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'health' );
$gBitSystem->verifyPermission( 'p_health_admin' );

require_once __DIR__.'/ImportRespiratoryRate.php';

$csvFile = healthFindLatestSamsungCsv( HEALTH_IMPORT_PATH, 'com.samsung.health.respiratory_rate' );
$jsonDir = HEALTH_IMPORT_PATH.'jsons/com.samsung.health.respiratory_rate/';

if( !$csvFile ) {
	$result = [ 'created' => 0, 'skipped' => 0, 'rowsNoBinning' => 0,
		'errors' => [ 'No com.samsung.health.respiratory_rate.*.csv found in '.HEALTH_IMPORT_PATH ] ];
} else {
	$result = healthImportRespiratoryRate( $csvFile, $jsonDir );
}

$gBitSmarty->assign( 'csvFile', $csvFile ?? '(none found)' );
$gBitSmarty->assign( 'created', $result['created'] );
$gBitSmarty->assign( 'skipped', $result['skipped'] );
$gBitSmarty->assign( 'rowsNoBinning', $result['rowsNoBinning'] );
$gBitSmarty->assign( 'errors',  $result['errors'] );

$gBitSystem->display( 'bitpackage:health/import_results.tpl', 'Import RESP' );
