<?php
/**
 * Variant of report_range.php with the same one-row-per-day layout, but the
 * BP Morning/Evening cells list every individual reading in that slot
 * instead of collapsing to a single average — built specifically to make
 * short-interval variability visible: with Lester's arrhythmia, readings
 * only minutes apart can genuinely disagree, which the slot average hides.
 * Weight/Pulse/HRV columns are identical to report_range.php, reusing the
 * same day-summary helpers.
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

// One line per reading, e.g. "06:32 142/88 (72)" - same healthFormatBPLine() single-value
// convention report_range.php uses (min=max=that one reading), just not averaged first.
$formatSlotReadings = function( ?array $pSlot ): array {
	if( !$pSlot ) {
		return [];
	}
	return array_map(
		fn( $pReading ) => $pReading['time'].' '.healthFormatBPLine( $pReading['sys'], $pReading['sys'], $pReading['dia'], $pReading['dia'], $pReading['pulse'], $pReading['pulse'] ),
		$pSlot['readings']
	);
};

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
		'bp_morning'  => $formatSlotReadings( $bp['slots']['morning'] ?? null ),
		'bp_evening'  => $formatSlotReadings( $bp['slots']['evening'] ?? null ),
		'hrv'         => isset( $energy['shrv_value'] ) ? healthFormatNumber( $energy['shrv_value'] ) : '',
	];
}

$rangeBP = healthRangeSummaryBP( $from, $to );

$gBitSmarty->assign( 'rows',    $rows );
$gBitSmarty->assign( 'from',    $from );
$gBitSmarty->assign( 'to',      $to );
$gBitSmarty->assign( 'rangeBP', $rangeBP );
// Same ?print=1 auto-print convention as report_range.php - see its own docblock.
$gBitSmarty->assign( 'autoPrint', !empty( $_REQUEST['print'] ) );

$gBitSystem->display( 'bitpackage:health/report_bp_detail.tpl', KernelTools::tra( 'BP Detail Report' ) );
