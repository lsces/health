<?php
/**
 * Day view for one HealthDay content_id — two outer tabs:
 *
 *   "Summary" — hand-crafted, same figures as the calendar day-cell
 *   (HealthDay::getDayCellHtml(): WT's headline weight, BP count, RAISEDHR's
 *   cached true HR min/max) but as a real page section rather than a tiny
 *   tile. Not yet pulling each item's own `data` json detail (body
 *   composition, calibration_id, etc.) - todo, per Lester's own framing:
 *   "pad out later with bits hidden in detail."
 *
 *   "Data" — the generic liberty xref-group framework food/stock/contact
 *   already use (loadXrefInfo()/$gContent->mXrefInfo->mGroups, each group
 *   rendered via getXrefListTemplate()/list_xref.tpl, each row dispatched to
 *   the item's own registered template - view_value_item.tpl/view_text_
 *   item.tpl/view_json-list_item.tpl), nested inside its own {jstabs} - one
 *   tab per xref_group. Health previously registered every item under one
 *   'vitals' group at sort_order=0 - LibertyXrefType::loadContent() only
 *   ever loads groups with sort_order > 0, so that group (and everything in
 *   it) was invisible to this framework entirely, not just ungrouped.
 *   Restructured (health/admin/schema_inc.php + admin/upgrades/5.0.3.php,
 *   once this manual test confirms the shape) into 'general' (every item
 *   that isn't an inherently-multi half-hour-slot item: WT/BP/OXI/TEMP/
 *   STEPS/ENERGY/SLEEP/STEPTRACK/RAISEDHR) plus one dedicated group each for
 *   PULSE/RESP/STEMP/HRV - fixed by the item's own inherent shape, not
 *   however many rows a particular day happens to have.
 *
 * @package health
 */

namespace Bitweaver\Health;

use Bitweaver\KernelTools;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty, $gBitDb;

$gBitSystem->verifyPackage( 'health' );
$gBitSystem->verifyPermission( 'p_health_view' );

$contentId = (int)( $_REQUEST['content_id'] ?? 0 );
$gContent = new HealthDay( $contentId );
$gContent->load();
if( !$gContent->isValid() ) {
	$gBitSystem->fatalError( 'No valid day specified.' );
}

$gContent->loadXrefInfo();

require_once __DIR__.'/includes/HealthDaySummary.php';

$bpCount = (int)$gBitDb->getOne(
	"SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'BP'",
	[ $contentId ]
);
$raisedHrData = $gBitDb->getOne(
	"SELECT `data` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'RAISEDHR'",
	[ $contentId ]
);
$raisedHr = $raisedHrData ? json_decode( (string)$raisedHrData, true ) : null;

$gBitSmarty->assign( 'gContent',   $gContent );
$gBitSmarty->assign( 'gXrefInfo',  $gContent->mXrefInfo );
$gBitSmarty->assign( 'wtSummary',  healthDaySummaryWT( $contentId ) );
$gBitSmarty->assign( 'bpCount',    $bpCount );
$gBitSmarty->assign( 'hrMin',      $raisedHr['hr_min'] ?? null );
$gBitSmarty->assign( 'hrMax',      $raisedHr['hr_max'] ?? null );

$gBitSystem->display( 'bitpackage:health/view_day.tpl', KernelTools::tra( 'Day' ).': '.$gContent->getTitle() );
