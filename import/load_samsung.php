<?php
/**
 * Upload + single-pass import of a raw Samsung Health export (.tar.gz).
 * See ImportSamsung.php's own docblock for the full design - this page is
 * just the upload form + dispatch, same shape as load_healthforyou.php.
 *
 * The raw uploaded archive is kept in HEALTH_IMPORT_PATH.'archive/' under
 * its own original name (sanitized), same convention load_healthforyou.php
 * already uses - successive dated exports land as their own file.
 *
 * @package health
 */

require_once '../../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'health' );
$gBitSystem->verifyPermission( 'p_health_admin' );

require_once __DIR__.'/ImportSamsung.php';

set_time_limit( 0 );

$rawFile = null;
$types   = [];
$years   = [];
$errors  = [];

if( !empty( $_FILES['export_file']['tmp_name'] ) && $_FILES['export_file']['error'] === UPLOAD_ERR_OK ) {
	// liberty_process_archive() (called inside healthImportSamsung()) needs
	// is_uploaded_file() to still be true, so extraction has to happen
	// before this file gets moved anywhere - tar itself only reads the
	// source, doesn't consume it, so the archival copy below still works
	// afterward.
	$result = healthImportSamsung( $_FILES['export_file'] );
	$years  = $result['years'];
	$errors = $result['errors'];

	// Result shapes differ per importer (created/skipped, inserted/duplicate,
	// daysProcessed/totalPulseSlots) - summarise each as a plain string
	// rather than forcing the template to handle heterogeneous keys.
	foreach( $result['types'] as $label => $r ) {
		$parts = [];
		foreach( $r as $k => $v ) {
			if( $k === 'errors' || is_array( $v ) ) {
				continue;
			}
			$parts[] = "$k: $v";
		}
		$types[$label] = [ 'summary' => implode( ', ', $parts ), 'errors' => $r['errors'] ?? [] ];
	}

	$safeName = preg_replace( '/[^A-Za-z0-9 ()._-]/', '_', basename( $_FILES['export_file']['name'] ) );
	if( $safeName === '' ) {
		$safeName = 'samsunghealth.tar.gz';
	}
	$archiveDir = HEALTH_IMPORT_PATH.'archive/';
	if( !is_dir( $archiveDir ) ) {
		mkdir( $archiveDir, 0777, true );
	}
	$rawFile = $archiveDir.$safeName;
	if( is_uploaded_file( $_FILES['export_file']['tmp_name'] ) ) {
		move_uploaded_file( $_FILES['export_file']['tmp_name'], $rawFile );
	}
}

$gBitSmarty->assign( 'uploadForm', true );
$gBitSmarty->assign( 'csvFile', $rawFile );
$gBitSmarty->assign( 'types', $types );
$gBitSmarty->assign( 'years', $years );
$gBitSmarty->assign( 'errors', $errors );

$gBitSystem->display( 'bitpackage:health/import_results_samsung.tpl', 'Upload Samsung Health Export' );
