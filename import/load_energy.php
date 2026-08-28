<?php
/**
 * Import ENERGY (Samsung's daily vitality/readiness score, including the
 * shrv_value HRV figure) from Samsung Health's vitality_score export.
 *
 * Expects com.samsung.shealth.vitality_score.<date>.csv in HEALTH_IMPORT_PATH
 * (storage/health/) — copy it from a health_name_<date> split. Safe to
 * re-run.
 *
 * @package health
 */

require_once '../../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'health' );
$gBitSystem->verifyPermission( 'p_health_admin' );

require_once __DIR__.'/ImportEnergy.php';

$csvFile = healthFindLatestSamsungCsv( HEALTH_IMPORT_PATH, 'com.samsung.shealth.vitality_score' );

if( !$csvFile ) {
	$result = [ 'created' => 0, 'skipped' => 0,
		'errors' => [ 'No com.samsung.shealth.vitality_score.*.csv found in '.HEALTH_IMPORT_PATH ] ];
} else {
	$result = healthImportEnergy( $csvFile );
}

$gBitSmarty->assign( 'csvFile', $csvFile ?? '(none found)' );
$gBitSmarty->assign( 'created', $result['created'] );
$gBitSmarty->assign( 'skipped', $result['skipped'] );
$gBitSmarty->assign( 'errors',  $result['errors'] );

$gBitSystem->display( 'bitpackage:health/import_results.tpl', 'Import ENERGY' );
