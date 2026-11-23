<?php
/**
 * Printable month-by-month report - the long view alongside report_range.php's
 * day-by-day weekly one. One row per calendar month: how many days the watch's pulse
 * reached its 30 bpm floor, pulse and HRV averages, how hard the exercise peaks went,
 * cuff BP, overnight SpO2 desaturation, weight and steps. Built for a clinical
 * appointment, where the real question is the pattern across many months rather than
 * any single week. See includes/HealthMonthSummary.php for exactly how each figure is
 * reduced.
 *
 * The From/To picker works in whole months: any date in a month includes all of it.
 * Default window is the last 18 months.
 *
 * @package health
 */

namespace Bitweaver\Health;

use Bitweaver\KernelTools;

require_once '../kernel/includes/setup_inc.php';
require_once __DIR__.'/includes/HealthMonthSummary.php';

global $gBitSystem, $gBitSmarty, $gBitUser;

$gBitSystem->verifyPackage( 'health' );
$gBitSystem->verifyPermission( 'p_health_view' );

$tz    = $gBitUser->getUserTimezone();
$today = new \DateTime( 'today', $tz );
$defaultFrom = ( new \DateTime( 'first day of this month', $tz ) )->modify( '-17 months' )->format( 'Y-m-d' );
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

$rows = [];
foreach( healthMonthSummary( $from, $to, $tz ) as $m ) {
	$hasAny = $m['days'] || $m['cuffCount'] || $m['desatNights'] || $m['weight'] !== null || $m['steps'] !== null;
	if( !$hasAny ) {
		continue;
	}
	$rows[] = [
		'month'      => $m['month'],
		'days'       => $m['days'] ?: '',
		'floor_days' => $m['floorDays'] ?? '',
		'floor_pct'  => healthFormatMonthFigure( $m['floorPct'], 0 ),
		'floor_slots'=> $m['days'] ? $m['floorSlots'] : '',
		'pulse'      => healthFormatMonthFigure( $m['pulse'] ),
		'night'      => healthFormatMonthFigure( $m['night'] ),
		'peak'       => healthFormatMonthFigure( $m['peak'], 0 ),
		'peak_max'   => healthFormatMonthFigure( $m['peakMax'], 0 ),
		'days130'    => $m['days130'] ?? '',
		'mins100'    => healthFormatMonthFigure( $m['mins100'] ),
		'sdnn'       => healthFormatMonthFigure( $m['sdnn'], 0 ),
		'cuff_n'     => $m['cuffCount'] ?: '',
		'cuff_bp'    => $m['sys'] !== null ? healthFormatMonthFigure( $m['sys'], 0 ).'/'.healthFormatMonthFigure( $m['dia'], 0 ) : '',
		'cuff_pulse' => healthFormatMonthFigure( $m['cuffPulse'], 0 ),
		'desat_n'    => $m['desatNights'] ?: '',
		'gap_nights' => $m['desatNights'] ? $m['gapNights'] : '',
		'gap_mean'   => healthFormatMonthFigure( $m['gapMean'], 0 ),
		'desat_90'   => healthFormatMonthFigure( $m['desatMins90'], 0 ),
		'desat_low'  => healthFormatMonthFigure( $m['desatLow'], 1 ),
		'desat_cov'  => healthFormatMonthFigure( $m['desatCoverage'], 0 ),
		'desat_pct'  => healthFormatMonthFigure( $m['desatPct'], 1 ),
		'weight'     => healthFormatMonthFigure( $m['weight'] ),
		'steps'      => healthFormatMonthFigure( $m['steps'], 0 ),
	];
}

$gBitSmarty->assign( 'rows',      $rows );
$gBitSmarty->assign( 'from',      $from );
$gBitSmarty->assign( 'to',        $to );
$gBitSmarty->assign( 'floorBpm',  HEALTH_WATCH_FLOOR_BPM );
$gBitSmarty->assign( 'minSlots',  HEALTH_MONTH_MIN_SLOTS );
// Same one-click print flow as report_range.php: index.php's Print button appends ?print=1.
$gBitSmarty->assign( 'autoPrint', !empty( $_REQUEST['print'] ) );

$gBitSystem->display( 'bitpackage:health/report_month.tpl', KernelTools::tra( 'Monthly Health Report' ) );
