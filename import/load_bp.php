<?php
/**
 * Import BP (systolic/diastolic/pulse/MAP) from a HealthForYou
 * blood_pressure.csv export (cuff source only — see ImportBP.php's docblock).
 *
 * Expects the file in HEALTH_IMPORT_PATH (storage/health/) — copy it from a
 * healthforyou_lester_<date>/blood_pressure.csv split (see
 * ~/Personal/Health/HealthForYouApp/split_healthforyou.py). Safe to re-run.
 *
 * @package health
 */

require_once '../../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'health' );
$gBitSystem->verifyPermission( 'p_health_admin' );

require_once __DIR__.'/ImportBP.php';

$csvFile = HEALTH_IMPORT_PATH.'blood_pressure.csv';
$result  = healthImportBP( $csvFile );

$gBitSmarty->assign( 'csvFile', $csvFile );
$gBitSmarty->assign( 'created', $result['created'] );
$gBitSmarty->assign( 'skipped', $result['skipped'] );
$gBitSmarty->assign( 'errors',  $result['errors'] );

$gBitSystem->display( 'bitpackage:health/import_results.tpl', 'Import BP' );
