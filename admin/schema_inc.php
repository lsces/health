<?php

$tables = [

// no tables yet — content-type schema not designed yet, see this package's own
// MANUAL.md for the architecture sketch (HealthMetric/HealthSession proposal,
// jsons/ shape taxonomy, multiple=-1 read-only convention).

];

global $gBitInstaller;

foreach( array_keys( $tables ) AS $tableName ) {
	$gBitInstaller->registerSchemaTable( HEALTH_PKG_NAME, $tableName, $tables[$tableName] );
}

$gBitInstaller->registerPackageInfo( HEALTH_PKG_NAME, [
	'description' => 'Health tracks vitals, activity, and sleep — imported from Samsung Health, modeled on the Food package.',
	'license'     => '<a href="http://www.gnu.org/licenses/licenses.html#LGPL">LGPL</a>',
] );

$gBitInstaller->registerPreferences( HEALTH_PKG_NAME, [
	[ HEALTH_PKG_NAME, 'health_menu_text', 'Health' ],
] );
