<?php
/**
 * Import RAISEDHR (minutes with heart rate above 90/100 bpm, per day) from
 * Samsung Health's tracker.heart_rate export — see ImportRaisedHR.php's own
 * docblock for the full reasoning, why per-day rather than per-exercise-
 * session, and why 90/100 rather than Samsung's own hr_zone thresholds.
 *
 * Expects, in HEALTH_IMPORT_PATH (storage/health/):
 *   com.samsung.shealth.tracker.heart_rate.<date>.csv
 *   jsons/com.samsung.shealth.tracker.heart_rate/<first-char>/<uuid>....json
 * (copy both from a health_lester_<date> split — same files PULSE uses).
 * Safe to re-run, and safe to run alongside/after load_pulse.php - both
 * read the same source independently, neither depends on the other having
 * run first.
 *
 * @package health
 */

require_once '../../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'health' );
$gBitSystem->verifyPermission( 'p_health_admin' );

require_once __DIR__.'/ImportRaisedHR.php';

$csvFile = healthFindLatestPulseCsv( HEALTH_IMPORT_PATH );
$jsonDir = HEALTH_IMPORT_PATH.'jsons/com.samsung.shealth.tracker.heart_rate/';

if( !$csvFile ) {
	$result = [ 'created' => 0, 'skipped' => 0, 'rowsNoBinning' => 0,
		'errors' => [ 'No com.samsung.shealth.tracker.heart_rate.*.csv found in '.HEALTH_IMPORT_PATH ] ];
} else {
	$result = healthImportRaisedHR( $csvFile, $jsonDir );
}

$gBitSmarty->assign( 'csvFile', $csvFile ?? '(none found)' );
$gBitSmarty->assign( 'created', $result['created'] );
$gBitSmarty->assign( 'skipped', $result['skipped'] );
$gBitSmarty->assign( 'rowsNoBinning', $result['rowsNoBinning'] );
$gBitSmarty->assign( 'errors',  $result['errors'] );

$gBitSystem->display( 'bitpackage:health/import_results.tpl', 'Import RAISEDHR' );
