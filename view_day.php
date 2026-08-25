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

$pulse = healthDaySummaryPulse( $contentId );

// BP: day-wide range line (same figure the calendar tile shows), plus the
// three fixed time-slot averages (morning/midday/evening) - see
// HealthDaySummary.php's healthDaySummaryBP() docblock for why these are
// averages, not ranges, and why midday is normally sparse (post-physio
// readings are the real case). Slot labels/order fixed here rather than
// looping $bp['slots'] keys, since the template needs a human label per key.
$bp = healthDaySummaryBP( $contentId );
$bpLine  = null;
$bpSlots = [];
if( $bp ) {
	$bpLine = healthFormatBPLine(
		$bp['systolic']['min'], $bp['systolic']['max'],
		$bp['diastolic']['min'], $bp['diastolic']['max'],
		$bp['pulse']['min'] ?? null, $bp['pulse']['max'] ?? null
	);
	$slotLabels = [ 'morning' => KernelTools::tra( 'Morning' ), 'midday' => KernelTools::tra( 'Midday' ), 'evening' => KernelTools::tra( 'Evening' ) ];
	foreach( $slotLabels as $key => $label ) {
		$slot = $bp['slots'][$key];
		if( !$slot ) {
			continue;
		}
		$bpSlots[] = [
			'label' => $label,
			'line'  => healthFormatBPLine( $slot['systolic'], $slot['systolic'], $slot['diastolic'], $slot['diastolic'], $slot['pulse'], $slot['pulse'] ),
		];
	}
}

// Energy/Sleep/HRV/Steps: ENERGY turns out to be the richest single source
// for four of these rows, not just its own line - total_score->Energy,
// shrv_value->HRV, and detail's sleep_score/activity_score feed the
// Sleep/Steps lines instead of SLEEP's own per-session scores or a bare
// Activity row (Lester's own framing, 2026-08-24). See
// healthFormatSleepLine()/healthFormatStepsLine() docblocks for the reasoning.
$energy   = healthDaySummaryEnergy( $contentId );
$steps    = healthDaySummarySteps( $contentId );
$sleepSessions = healthDaySummarySleep( $contentId );

$gBitSmarty->assign( 'gContent',    $gContent );
$gBitSmarty->assign( 'gXrefInfo',   $gContent->mXrefInfo );
$gBitSmarty->assign( 'wtSummary',   healthDaySummaryWT( $contentId ) );
$gBitSmarty->assign( 'bpCount',     $bp['count'] ?? 0 );
$gBitSmarty->assign( 'bpLine',      $bpLine );
$gBitSmarty->assign( 'bpSlots',     $bpSlots );
$gBitSmarty->assign( 'hrMin',       $pulse['min'] ?? null );
$gBitSmarty->assign( 'hrMax',       $pulse['max'] ?? null );
$gBitSmarty->assign( 'hrAvg',       $pulse['avg'] ?? null );
$gBitSmarty->assign( 'energyLine',  isset( $energy['total_score'] ) ? healthFormatNumber( $energy['total_score'] ) : null );
$gBitSmarty->assign( 'hrvLine',     isset( $energy['shrv_value'] )  ? healthFormatNumber( $energy['shrv_value'] )  : null );
$gBitSmarty->assign( 'sleepLine',   healthFormatSleepLine( $energy['detail']['sleep_score'] ?? null, $sleepSessions ) );
$gBitSmarty->assign( 'stepsLine',   healthFormatStepsLine( $steps, $energy['detail']['activity_score'] ?? null ) );

$gBitSystem->display( 'bitpackage:health/view_day.tpl', KernelTools::tra( 'Day' ).': '.$gContent->getTitle() );
