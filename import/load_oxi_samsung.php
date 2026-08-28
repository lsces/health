<?php
/**
 * Import OXI from Samsung Health's own tracker.oxygen_saturation export —
 * see ImportOxiSamsung.php's own docblock for why not the .raw file, and
 * the reasoning behind reusing OXI rather than a separate item.
 *
 * Expects com.samsung.shealth.tracker.oxygen_saturation.<date>.csv in
 * HEALTH_IMPORT_PATH (storage/health/) — copy it from a health_name_<date>
 * split. Safe to re-run, and safe to run alongside/after load_oxi.php
 * (HealthForYou) — both dedupe via minute-level start_date, so genuinely
 * overlapping readings correctly skip regardless of which importer ran
 * first.
 *
 * @package health
 */

require_once '../../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'health' );
$gBitSystem->verifyPermission( 'p_health_admin' );

require_once __DIR__.'/ImportOxiSamsung.php';

$csvFile = healthFindLatestSamsungCsv( HEALTH_IMPORT_PATH, 'com.samsung.shealth.tracker.oxygen_saturation' );

if( !$csvFile ) {
	$result = [ 'created' => 0, 'skipped' => 0,
		'errors' => [ 'No com.samsung.shealth.tracker.oxygen_saturation.*.csv found in '.HEALTH_IMPORT_PATH ] ];
} else {
	$result = healthImportOxiSamsung( $csvFile );
}

$gBitSmarty->assign( 'csvFile', $csvFile ?? '(none found)' );
$gBitSmarty->assign( 'created', $result['created'] );
$gBitSmarty->assign( 'skipped', $result['skipped'] );
$gBitSmarty->assign( 'errors',  $result['errors'] );

$gBitSystem->display( 'bitpackage:health/import_results.tpl', 'Import OXI (Samsung)' );
