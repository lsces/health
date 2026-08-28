<?php
/**
 * Import RAISEDHR (minutes with heart rate above 90/100bpm headline, plus a
 * 130bpm detail tier, per day) from two Samsung Health sources combined —
 * see ImportRaisedHR.php's own docblock for the full reasoning: exercise
 * live_data for the window each session covers, PULSE's background
 * tracker.heart_rate bins for everything else that day.
 *
 * Expects, in HEALTH_IMPORT_PATH (storage/health/):
 *   com.samsung.shealth.tracker.heart_rate.<date>.csv + its jsons/ blobs
 *   com.samsung.shealth.exercise.<date>.csv + its jsons/ blobs
 * (copy all four from a health_name_<date> split — the first pair is the
 * same files PULSE uses). Safe to re-run.
 *
 * @package health
 */

require_once '../../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'health' );
$gBitSystem->verifyPermission( 'p_health_admin' );

require_once __DIR__.'/ImportRaisedHR.php';

$pulseCsv    = healthFindLatestPulseCsv( HEALTH_IMPORT_PATH );
$pulseJsons  = HEALTH_IMPORT_PATH.'jsons/com.samsung.shealth.tracker.heart_rate/';
$exerciseCsv = healthFindLatestSamsungCsv( HEALTH_IMPORT_PATH, 'com.samsung.shealth.exercise' );
$exerciseJsons = HEALTH_IMPORT_PATH.'jsons/com.samsung.shealth.exercise/';

if( !$pulseCsv || !$exerciseCsv ) {
	$missing = [];
	if( !$pulseCsv ) {
		$missing[] = 'com.samsung.shealth.tracker.heart_rate.*.csv';
	}
	if( !$exerciseCsv ) {
		$missing[] = 'com.samsung.shealth.exercise.*.csv';
	}
	$result = [ 'created' => 0, 'skipped' => 0, 'rowsNoBinning' => 0,
		'errors' => [ 'Not found in '.HEALTH_IMPORT_PATH.': '.implode( ', ', $missing ) ] ];
} else {
	$result = healthImportRaisedHR( $pulseCsv, $pulseJsons, $exerciseCsv, $exerciseJsons );
}

$gBitSmarty->assign( 'csvFile', ( $pulseCsv ?? '(none found)' ).' + '.( $exerciseCsv ?? '(none found)' ) );
$gBitSmarty->assign( 'created', $result['created'] );
$gBitSmarty->assign( 'skipped', $result['skipped'] );
$gBitSmarty->assign( 'rowsNoBinning', $result['rowsNoBinning'] );
$gBitSmarty->assign( 'errors',  $result['errors'] );

$gBitSystem->display( 'bitpackage:health/import_results.tpl', 'Import RAISEDHR' );
