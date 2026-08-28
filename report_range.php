<?php
/**
 * Printable date-range report — one row per day between two dates, the
 * handful of figures the author actually wants on paper for a doctor's
 * appointment: Weight, Pulse (average + range), Blood Pressure (morning +
 * evening slot averages), HRV. Deliberately not the full Summary tab
 * (Energy/Sleep/Steps omitted) — a focused clinical printout, not a data
 * dump. First pass per the author's own framing ("once I see it on paper I
 * can tweak it") — expect this to be revised after a real look, not a
 * finished spec.
 *
 * @package health
 */

namespace Bitweaver\Health;

use Bitweaver\KernelTools;

require_once '../kernel/includes/setup_inc.php';
require_once __DIR__.'/includes/HealthDaySummary.php';

global $gBitSystem, $gBitSmarty, $gBitDb;

$gBitSystem->verifyPackage( 'health' );
$gBitSystem->verifyPermission( 'p_health_view' );

$today = new \DateTime( 'today', new \DateTimeZone( 'Europe/London' ) );
$defaultFrom = ( clone $today )->modify( '-6 days' )->format( 'Y-m-d' );
$defaultTo   = $today->format( 'Y-m-d' );

$from = trim( $_REQUEST['from'] ?? '' ) ?: $defaultFrom;
$to   = trim( $_REQUEST['to']   ?? '' ) ?: $defaultTo;
if( !preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
	$from = $defaultFrom;
}
if( !preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
	$to = $defaultTo;
}
if( $from > $to ) {
	[ $from, $to ] = [ $to, $from ];
}

$days = $gBitDb->getAll(
	"SELECT `content_id`, `title` FROM `".BIT_DB_PREFIX."liberty_content`
		WHERE `content_type_guid` = 'healthday' AND `title` BETWEEN ? AND ?
		ORDER BY `title`",
	[ $from, $to ]
);

$rows = [];
foreach( $days as $day ) {
	$contentId = (int)$day['content_id'];

	$wt    = healthDaySummaryWT( $contentId );
	$bp    = healthDaySummaryBP( $contentId );
	$pulse = healthDaySummaryPulse( $contentId );
	$energy = healthDaySummaryEnergy( $contentId );

	$rows[] = [
		'date'        => $day['title'],
		'weight'      => $wt ? healthFormatNumber( $wt['weight'] ) : '',
		'pulse_avg'   => $pulse['avg'] !== null ? healthFormatNumber( $pulse['avg'] ) : '',
		'pulse_range' => ( $pulse['min'] !== null ) ? healthFormatRange( $pulse['min'], $pulse['max'] ) : '',
		'bp_morning'  => ( $bp['slots']['morning'] ?? null )
			? healthFormatBPLine( $bp['slots']['morning']['systolic'], $bp['slots']['morning']['systolic'], $bp['slots']['morning']['diastolic'], $bp['slots']['morning']['diastolic'], $bp['slots']['morning']['pulse'], $bp['slots']['morning']['pulse'] )
			: '',
		'bp_evening'  => ( $bp['slots']['evening'] ?? null )
			? healthFormatBPLine( $bp['slots']['evening']['systolic'], $bp['slots']['evening']['systolic'], $bp['slots']['evening']['diastolic'], $bp['slots']['evening']['diastolic'], $bp['slots']['evening']['pulse'], $bp['slots']['evening']['pulse'] )
			: '',
		'hrv'         => isset( $energy['shrv_value'] ) ? healthFormatNumber( $energy['shrv_value'] ) : '',
	];
}

$rangeBP = healthRangeSummaryBP( $from, $to );

$gBitSmarty->assign( 'rows',    $rows );
$gBitSmarty->assign( 'from',    $from );
$gBitSmarty->assign( 'to',      $to );
$gBitSmarty->assign( 'rangeBP', $rangeBP );
// A link can't trigger the browser's print dialog without a page actually loading first (no
// such thing as printing "straight through" a plain URL) — closest practical equivalent is this:
// the index.php Reports list's own Print button appends ?print=1, and the template below fires
// window.print() itself once the page has loaded, so the flow feels like one click.
$gBitSmarty->assign( 'autoPrint', !empty( $_REQUEST['print'] ) );

$gBitSystem->display( 'bitpackage:health/report_range.tpl', KernelTools::tra( 'Health Report' ) );
