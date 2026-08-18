<?php
global $gBitSystem;

$pRegisterHash = [
	'package_name' => 'health',
	'package_path' => dirname( dirname( __FILE__ ) ).'/',
	'homeable'     => true,
];
// fix to quieten down VS Code which can't see the dynamic creation of these ...
define( 'HEALTH_PKG_NAME', $pRegisterHash['package_name'] );
define( 'HEALTH_PKG_URL', BIT_ROOT_URL . basename( $pRegisterHash['package_path'] ) . '/' );
define( 'HEALTH_PKG_PATH', BIT_ROOT_PATH . basename( $pRegisterHash['package_path'] ) . '/' );
define( 'HEALTH_PKG_INCLUDE_PATH', BIT_ROOT_PATH . basename( $pRegisterHash['package_path'] ) . '/includes/');
define( 'HEALTH_PKG_CLASS_PATH', BIT_ROOT_PATH . basename( $pRegisterHash['package_path'] ) . '/includes/classes/');
define( 'HEALTH_PKG_ADMIN_PATH', BIT_ROOT_PATH . basename( $pRegisterHash['package_path'] ) . '/admin/');
define( 'HEALTH_IMPORT_PATH', STORAGE_PKG_PATH . 'health/' );

$gBitSystem->registerPackage( $pRegisterHash );

if( $gBitSystem->isPackageActive( 'health' ) ) {

	$menuHash = [
		'package_name'  => HEALTH_PKG_NAME,
		'index_url'     => HEALTH_PKG_URL.'index.php',
		'menu_template' => 'bitpackage:health/menu_health.tpl',
	];
	$gBitSystem->registerAppMenu( $menuHash );

	// content-type registration (HealthMetric/HealthSession — see this package's own
	// MANUAL.md for the architecture sketch) and any service/hook registration (see
	// stock/includes/bit_setup_inc.php for the pattern) belongs here once those
	// classes exist — nothing to register yet.
}
