<?php
/**
 * @package health
 */

global $gBitInstaller;

$X = BIT_DB_PREFIX;

$gBitInstaller->registerPackageUpgrade(
	[
		'package'     => HEALTH_PKG_NAME,
		'version'     => '5.0.5',
		'description' => 'Register the new OXIDESAT xref item (ImportOxiDesat.php) so it shows up '
			.'in list_item.php\'s picker like every other item type. Derived from the same '
			.'tracker.oxygen_saturation sleep sessions OXI already imports, but reducing each '
			.'session\'s own binning detail (per-~1-minute SpO2 slices, never opened by '
			.'ImportOxiSamsung.php) to minutes spent below 90/85/80% - the RAISEDHR-style "how '
			.'long", not just a session average. Same \'key-json-text\' template and \'general\' '
			.'group as RAISEDHR (a derived rollup, not a raw multi-reading type). See '
			.'admin/schema_inc.php for the same INSERT (fresh installs) and ImportOxiDesat.php\'s '
			.'own docblock for the reduction design.',
	],
	[
		[ 'QUERY' => [
			'SQL92' => [
				"INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`sort_order`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('OXIDESAT','healthday','general','SpO2 Desaturation',-1,14,3,'','key-json-text','[\"mins_below_90\",\"mins_below_85\",\"mins_80\",\"low_value\",\"low_time\",\"sample_count\",\"coverage_mins\",\"session_mins\",\"spo2_avg\",\"spo2_min\",\"spo2_max\"]')",
			],
		]],
	]
);
