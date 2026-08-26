<?php
/**
 * Upload + single-pass import of a raw HealthForYouApp_DataExport CSV.
 *
 * Replaces the manual split_healthforyou.py + per-section copy-into-
 * storage/health/ + load_wt.php/load_bp.php/load_oxi.php/load_temp.php
 * workflow with one upload button — parses the combined export directly
 * (see ImportHealthForYou.php's section scan) and dispatches each section to
 * its existing importer. Works from any of the three machines (srv9/srv10/
 * desktop), unlike the Python split script which only runs on desktop.
 *
 * The raw uploaded file is kept in HEALTH_IMPORT_PATH.'archive/' under its own
 * original name (sanitized, not a fixed generic filename) — so successive
 * dated exports land as their own file rather than clobbering each other,
 * same spirit as the export's own "HealthForYouApp_DataExport (N).csv"
 * naming. Each section's rows are also appended onto the durable per-year
 * archive CSV (history/<year>/healthforyou_<section>.csv) — see
 * ImportHealthForYou.php. An xref-logged upload record is still an open
 * design question (see THOUGHTS.txt's Health notes) — not built here.
 *
 * @package health
 */

require_once '../../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'health' );
$gBitSystem->verifyPermission( 'p_health_admin' );

require_once __DIR__.'/ImportHealthForYou.php';

$rawFile  = null;
$sections = [];
$errors   = [];

if( !empty( $_FILES['export_file']['tmp_name'] ) && $_FILES['export_file']['error'] === UPLOAD_ERR_OK ) {
	$safeName = preg_replace( '/[^A-Za-z0-9 ()._-]/', '_', basename( $_FILES['export_file']['name'] ) );
	if( $safeName === '' ) {
		$safeName = 'HealthForYouApp_DataExport.csv';
	}
	$archiveDir = HEALTH_IMPORT_PATH.'archive/';
	if( !is_dir( $archiveDir ) ) {
		mkdir( $archiveDir, 0777, true );
	}
	$rawFile = $archiveDir.$safeName;
	move_uploaded_file( $_FILES['export_file']['tmp_name'], $rawFile );

	$result   = healthImportHealthForYou( $rawFile );
	$sections = $result['sections'];
	$errors   = $result['errors'];
}

$gBitSmarty->assign( 'uploadForm', true );
$gBitSmarty->assign( 'csvFile',  $rawFile );
$gBitSmarty->assign( 'sections', $sections );
$gBitSmarty->assign( 'errors',   $errors );

$gBitSystem->display( 'bitpackage:health/import_results_healthforyou.tpl', 'Upload HealthForYou Export' );
