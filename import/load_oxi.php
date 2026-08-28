<?php
/**
 * Import OXI (finger-probe pulse oximeter) from a HealthForYou
 * pulse_oximeter.csv export.
 *
 * Expects the file in HEALTH_IMPORT_PATH (storage/health/) — copy it from a
 * healthforyou_name_<date>/pulse_oximeter.csv split. Safe to re-run.
 *
 * @package health
 */

require_once '../../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'health' );
$gBitSystem->verifyPermission( 'p_health_admin' );

require_once __DIR__.'/ImportOxi.php';

$csvFile = HEALTH_IMPORT_PATH.'pulse_oximeter.csv';
$result  = healthImportOxi( $csvFile );

$gBitSmarty->assign( 'csvFile', $csvFile );
$gBitSmarty->assign( 'created', $result['created'] );
$gBitSmarty->assign( 'skipped', $result['skipped'] );
$gBitSmarty->assign( 'errors',  $result['errors'] );

$gBitSystem->display( 'bitpackage:health/import_results.tpl', 'Import OXI' );
