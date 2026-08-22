<?php
/**
 * Import WT (weight/BMI/body-composition) from a HealthForYou weight.csv export.
 *
 * Expects the file in HEALTH_IMPORT_PATH (storage/health/) — copy it from a
 * healthforyou_lester_<date>/weight.csv split (see
 * ~/Personal/Health/HealthForYouApp/split_healthforyou.py). Safe to re-run,
 * see ImportWT.php's own docblock.
 *
 * @package health
 */

require_once '../../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'health' );
$gBitSystem->verifyPermission( 'p_health_admin' );

require_once __DIR__.'/ImportWT.php';

$csvFile = HEALTH_IMPORT_PATH.'weight.csv';
$result  = healthImportWT( $csvFile );

$gBitSmarty->assign( 'csvFile', $csvFile );
$gBitSmarty->assign( 'created', $result['created'] );
$gBitSmarty->assign( 'skipped', $result['skipped'] );
$gBitSmarty->assign( 'errors',  $result['errors'] );

$gBitSystem->display( 'bitpackage:health/import_results.tpl', 'Import WT' );
