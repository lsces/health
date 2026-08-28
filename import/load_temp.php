<?php
/**
 * Import TEMP (ear temperature) from a HealthForYou temperature.csv export.
 *
 * Expects the file in HEALTH_IMPORT_PATH (storage/health/) — copy it from a
 * healthforyou_name_<date>/temperature.csv split. Safe to re-run.
 *
 * @package health
 */

require_once '../../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'health' );
$gBitSystem->verifyPermission( 'p_health_admin' );

require_once __DIR__.'/ImportTemp.php';

$csvFile = HEALTH_IMPORT_PATH.'temperature.csv';
$result  = healthImportTemp( $csvFile );

$gBitSmarty->assign( 'csvFile', $csvFile );
$gBitSmarty->assign( 'created', $result['created'] );
$gBitSmarty->assign( 'skipped', $result['skipped'] );
$gBitSmarty->assign( 'errors',  $result['errors'] );

$gBitSystem->display( 'bitpackage:health/import_results.tpl', 'Import TEMP' );
