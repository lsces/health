<?php
/**
 * Rebuild PULSE and RAISEDHR for every day with HEALTH_HR_RAW data - see
 * RebuildHRDerived.php's own docblock for the full reasoning (both items
 * now read the same unified two-source timeline instead of each re-parsing
 * the raw files independently). Safe to re-run any time HEALTH_HR_RAW gets
 * new data - each day's existing PULSE/RAISEDHR rows are replaced, not
 * accumulated alongside.
 *
 * @package health
 */

require_once '../../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'health' );
$gBitSystem->verifyPermission( 'p_health_admin' );

require_once __DIR__.'/RebuildHRDerived.php';

set_time_limit( 0 );

$result = healthRebuildAllDays();

$gBitSmarty->assign( 'result', $result );

$gBitSystem->display( 'bitpackage:health/rebuild_hr_derived.tpl', 'Rebuild PULSE + RAISEDHR' );
