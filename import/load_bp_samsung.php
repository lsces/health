<?php
/**
 * Import BP from Samsung Health's own blood_pressure export — both
 * watch-PPG and (deduped, minute-truncated) cuff-synced readings, see
 * ImportBPSamsung.php's own docblock for the full reasoning.
 *
 * Expects com.samsung.shealth.blood_pressure.<date>.csv in HEALTH_IMPORT_PATH
 * (storage/health/) — copy it from a health_lester_<date> split. Safe to
 * re-run, and safe to run alongside/after load_bp.php (HealthForYou/cuff) —
 * both dedupe via minute-level entry_date, so genuinely overlapping readings
 * correctly skip regardless of which importer ran first.
 *
 * @package health
 */

require_once '../../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'health' );
$gBitSystem->verifyPermission( 'p_health_admin' );

require_once __DIR__.'/ImportBPSamsung.php';

$csvFile = healthFindLatestSamsungCsv( HEALTH_IMPORT_PATH, 'com.samsung.shealth.blood_pressure' );

if( !$csvFile ) {
	$result = [ 'created' => 0, 'skipped' => 0,
		'errors' => [ 'No com.samsung.shealth.blood_pressure.*.csv found in '.HEALTH_IMPORT_PATH ] ];
} else {
	$result = healthImportBPSamsung( $csvFile );
}

$gBitSmarty->assign( 'csvFile', $csvFile ?? '(none found)' );
$gBitSmarty->assign( 'created', $result['created'] );
$gBitSmarty->assign( 'skipped', $result['skipped'] );
$gBitSmarty->assign( 'errors',  $result['errors'] );

$gBitSystem->display( 'bitpackage:health/import_results.tpl', 'Import BP (Samsung)' );
