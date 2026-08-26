<?php
/**
 * Health package front page — a summary dashboard, not a placeholder (see git
 * history for the earlier "no pages built yet" stub). Total record counts +
 * date range per item, split into HealthForYou/Samsung Health sections —
 * BP/OXI (which genuinely blend both device sources, see
 * HealthIndexSummary.php's own docblock) contribute one row to *each*
 * section, showing that bucket's own count/period covered rather than a
 * separate third "combined" table. Each section heading also shows its own
 * "Last Download" (the latest reading actually imported from that app) so
 * staleness is visible at a glance. Plus a link into Calendar's existing
 * month grid, pre-filtered to `healthday` tiles, and a date picker that
 * jumps straight to that day's view_day.php, or reports plainly that no
 * data was imported for that date rather than erroring.
 *
 * @package health
 */

namespace Bitweaver\Health;

use Bitweaver\KernelTools;

require_once '../kernel/includes/setup_inc.php';
require_once __DIR__.'/includes/HealthIndexSummary.php';

global $gBitSystem, $gBitSmarty, $gBitDb;

$gBitSystem->verifyPackage( 'health' );
$gBitSystem->verifyPermission( 'p_health_view' );

// Date picker — resolves to an existing HealthDay's content_id and redirects
// straight into view_day.php, same "no create-for-date here" contract as
// every other Health page (unlike Food's view_day.php, which always
// find-or-creates: Health's data is imported/read-only, so a date with
// nothing imported is a real, expected state to report, not to paper over).
$dateNotFound = null;
if( !empty( $_REQUEST['date'] ) ) {
	$contentId = HealthDay::lookupByDate( $_REQUEST['date'] );
	if( $contentId ) {
		header( 'Location: '.HEALTH_PKG_URL.'view_day.php?content_id='.$contentId );
		exit;
	}
	$dateNotFound = $_REQUEST['date'];
}

$X = BIT_DB_PREFIX;
$dayRange = $gBitDb->getRow(
	"SELECT COUNT(*) AS `cnt`, MIN(`title`) AS `min_date`, MAX(`title`) AS `max_date`
		FROM `{$X}liberty_content` WHERE `content_type_guid` = 'healthday'"
);

$itemSummary = healthIndexItemSummary();

// Item→section classification, per each importer's own docblock (see
// health/CLAUDE.md's build history) — not derived from the DB, since
// nothing stores "which app this item came from" at the item level. BP/OXI
// split into their own cuff/watch rows via healthIndexSourceSplit() and
// land directly in the section matching each bucket's real source, rather
// than a separate third "combined" table — each row's own count/period
// covered reflects just that bucket, not the item's combined range.
$healthForYouItems = [ 'WT', 'TEMP' ];
$samsungItems      = [ 'PULSE', 'STEPS', 'ENERGY', 'SLEEP', 'RESP', 'STEMP', 'HRV', 'STEPTRACK', 'RAISEDHR' ];
$splitItems        = [ 'BP' => 'Blood Pressure', 'OXI' => 'Pulse Oximeter' ];

$healthForYouRows = $samsungRows = [];
foreach( $itemSummary as $item => $row ) {
	$row['item'] = $item;
	if( in_array( $item, $healthForYouItems, true ) ) {
		$healthForYouRows[] = $row;
	} elseif( in_array( $item, $samsungItems, true ) ) {
		$samsungRows[] = $row;
	} elseif( isset( $splitItems[$item] ) ) {
		// No device-qualifier suffix needed on the title — which section a
		// row lands in already says which source it's from.
		$split = healthIndexSourceSplit( $item );
		$healthForYouRows[] = [ 'item' => $item, 'title' => $splitItems[$item] ] + $split['cuff'];
		$samsungRows[]      = [ 'item' => $item, 'title' => $splitItems[$item] ] + $split['watch'];
	}
}

// "Last Download" per section — the most recent reading actually imported
// from that app, shown next to the section heading so staleness is
// visible at a glance (prompted by Lester's own upcoming HFY-only
// re-download, watch BP out of calibration).
$healthForYouMax = array_filter( array_column( $healthForYouRows, 'max_date' ) );
$samsungMax      = array_filter( array_column( $samsungRows, 'max_date' ) );

$gBitSmarty->assign( 'dayCount',        (int)$dayRange['cnt'] );
$gBitSmarty->assign( 'dayMinDate',      $dayRange['min_date'] );
$gBitSmarty->assign( 'dayMaxDate',      $dayRange['max_date'] );
$gBitSmarty->assign( 'healthForYouRows', $healthForYouRows );
$gBitSmarty->assign( 'samsungRows',      $samsungRows );
$gBitSmarty->assign( 'healthForYouLast', $healthForYouMax ? max( $healthForYouMax ) : null );
$gBitSmarty->assign( 'samsungLast',      $samsungMax ? max( $samsungMax ) : null );
$gBitSmarty->assign( 'dateNotFound',     $dateNotFound );

// Raw HealthForYou exports already uploaded (storage/health/archive/), newest first — shown on
// the HealthForYou tab so earlier uploads stay visible, not just the latest one's
// import_results.tpl. Samsung has no equivalent yet (its own single-pass upload isn't built).
$healthForYouUploads = [];
$archiveDir = HEALTH_IMPORT_PATH.'archive/';
if( is_dir( $archiveDir ) ) {
	foreach( glob( $archiveDir.'*.csv' ) as $path ) {
		$healthForYouUploads[] = [
			'name'  => basename( $path ),
			'size'  => filesize( $path ),
			'mtime' => filemtime( $path ),
		];
	}
	usort( $healthForYouUploads, fn( $a, $b ) => $b['mtime'] <=> $a['mtime'] );
}
$gBitSmarty->assign( 'healthForYouUploads', $healthForYouUploads );

// Reports section (General tab) — a shared From/To period selector feeding a list of report
// pages, each with its own View/Print button. Only one report exists today (report_range.php)
// but the list shape is built for more without changing this section again — a future report
// just adds another row here, each targeting its own URL via the row button's own formaction.
$reportToday      = new \DateTime( 'today', new \DateTimeZone( 'Europe/London' ) );
$gBitSmarty->assign( 'reportFrom', ( clone $reportToday )->modify( '-6 days' )->format( 'Y-m-d' ) );
$gBitSmarty->assign( 'reportTo',   $reportToday->format( 'Y-m-d' ) );
$gBitSmarty->assign( 'healthReports', [
	[ 'title' => KernelTools::tra( 'Weekly Range Report' ), 'url' => HEALTH_PKG_URL.'report_range.php' ],
] );

$gBitSystem->display( 'bitpackage:health/index.tpl', KernelTools::tra( 'Health' ) );
