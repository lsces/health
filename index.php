<?php
/**
 * Health package front page — a summary dashboard, not a placeholder (see git
 * history for the earlier "no pages built yet" stub). Three things Lester
 * asked for: total record counts + date range per item, split into
 * HealthForYou/Samsung Health/Combined sections since each item's own real
 * source only makes sense grouped that way (see HealthIndexSummary.php's own
 * docblock for the BP/OXI combined-source split); a link into Calendar's
 * existing month grid, pre-filtered to `healthday` tiles; and a date picker
 * that jumps straight to that day's view_day.php, or reports plainly that no
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
// nothing stores "which app this item came from" at the item level.
$healthForYouItems = [ 'WT', 'TEMP' ];
$samsungItems      = [ 'PULSE', 'STEPS', 'ENERGY', 'SLEEP', 'RESP', 'STEMP', 'HRV', 'STEPTRACK', 'RAISEDHR' ];
$combinedItems     = [ 'BP', 'OXI' ]; // both apps genuinely feed these — see healthIndexSourceSplit()

$healthForYouRows = $samsungRows = $combinedRows = [];
foreach( $itemSummary as $item => $row ) {
	$row['item'] = $item;
	if( in_array( $item, $healthForYouItems, true ) ) {
		$healthForYouRows[] = $row;
	} elseif( in_array( $item, $samsungItems, true ) ) {
		$samsungRows[] = $row;
	} elseif( in_array( $item, $combinedItems, true ) ) {
		$split = healthIndexSourceSplit( $item );
		$row['cuff_count']  = $split['cuff'];
		$row['watch_count'] = $split['watch'];
		$combinedRows[] = $row;
	}
}

$gBitSmarty->assign( 'dayCount',        (int)$dayRange['cnt'] );
$gBitSmarty->assign( 'dayMinDate',      $dayRange['min_date'] );
$gBitSmarty->assign( 'dayMaxDate',      $dayRange['max_date'] );
$gBitSmarty->assign( 'healthForYouRows', $healthForYouRows );
$gBitSmarty->assign( 'samsungRows',      $samsungRows );
$gBitSmarty->assign( 'combinedRows',     $combinedRows );
$gBitSmarty->assign( 'dateNotFound',     $dateNotFound );

$gBitSystem->display( 'bitpackage:health/index.tpl', KernelTools::tra( 'Health' ) );
