<?php
/**
 * Index of the individual per-type import scripts (WT/BP/PULSE/etc) - superseded for
 * day-to-day use by the two combined imports (HealthForYou, Samsung) but kept reachable for
 * one-off/historical reprocessing. Moved off the main admin menu to keep that down to the
 * combined imports + this one link - see THOUGHTS.txt's Health item 3 / health.md's dated
 * entry. Direction is retiring these behind a proper install process eventually, not adding
 * to them.
 *
 * @package health
 */

use Bitweaver\KernelTools;

require_once '../../kernel/includes/setup_inc.php';

$gBitSystem->verifyPackage( 'health' );
$gBitSystem->verifyPermission( 'p_health_admin' );

$gBitSystem->display( 'bitpackage:health/admin_legacy_imports.tpl', KernelTools::tra( 'Legacy Individual Imports' ), [ 'display_mode' => 'admin' ] );
