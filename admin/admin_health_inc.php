<?php
/**
 * Health package settings (Administration > Health): one kernel_config row per setting, written with
 * storeConfig() so the package's own active flag/version rows are left alone. A blank value, or one
 * equal to the default, is not kept - clearing a field puts it back to the default.
 *
 * @package health
 */

$trackingDefault = '/media3/Maps/_tracking';

if( !empty( $_REQUEST['save'] ) && $gBitSystem->isPackageActive( 'health' ) ) {
	$posted = is_string( $_REQUEST['health_tracking_path'] ?? null ) ? rtrim( trim( $_REQUEST['health_tracking_path'] ), '/' ) : '';
	$gBitSystem->storeConfig( 'health_tracking_path', ( $posted !== '' && $posted !== $trackingDefault ) ? $posted : '', HEALTH_PKG_NAME );
}

$gBitSmarty->assign( 'healthSettings', [
	'health_tracking_path' => $gBitSystem->getConfig( 'health_tracking_path', $trackingDefault ) ?: $trackingDefault,
] );
