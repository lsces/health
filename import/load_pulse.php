<?php
/**
 * Import PULSE (half-hour heart-rate slot summaries) from Samsung Health's
 * tracker.heart_rate export — see ImportPulse.php's own docblock for the full
 * shape and scoping (binning_data rows only, Europe/London slot alignment).
 *
 * Expects, in HEALTH_IMPORT_PATH (storage/health/):
 *   com.samsung.shealth.tracker.heart_rate.<date>.csv
 *   jsons/com.samsung.shealth.tracker.heart_rate/<first-char>/<uuid>....json
 * (copy both from a health_lester_<date> split). Safe to re-run.
 *
 * @package health
 */

require_once '../../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'health' );
$gBitSystem->verifyPermission( 'p_health_admin' );

require_once __DIR__.'/ImportPulse.php';

$csvFile = healthFindLatestPulseCsv( HEALTH_IMPORT_PATH );
$jsonDir = HEALTH_IMPORT_PATH.'jsons/com.samsung.shealth.tracker.heart_rate/';

if( !$csvFile ) {
	$result = [ 'created' => 0, 'skipped' => 0, 'rowsNoBinning' => 0,
		'errors' => [ 'No com.samsung.shealth.tracker.heart_rate.*.csv found in '.HEALTH_IMPORT_PATH ] ];
} else {
	$result = healthImportPulse( $csvFile, $jsonDir );
}

$gBitSmarty->assign( 'csvFile', $csvFile ?? '(none found)' );
$gBitSmarty->assign( 'created', $result['created'] );
$gBitSmarty->assign( 'skipped', $result['skipped'] );
$gBitSmarty->assign( 'rowsNoBinning', $result['rowsNoBinning'] );
$gBitSmarty->assign( 'errors',  $result['errors'] );

$gBitSystem->display( 'bitpackage:health/import_results.tpl', 'Import PULSE' );
