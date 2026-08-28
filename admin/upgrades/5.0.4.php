<?php
/**
 * @package health
 */

global $gBitInstaller;

$X = BIT_DB_PREFIX;

$gBitInstaller->registerPackageUpgrade(
	[
		'package'     => HEALTH_PKG_NAME,
		'version'     => '5.0.4',
		'description' => 'Register the new EXERCISE xref item (ImportExercise.php) so it shows up '
			.'in list_item.php\'s picker and view_day.php\'s day view like every other item type. '
			.'Same \'key-json-detail\' template as PULSE/RESP/STEMP/HRV/STEPTRACK - xkey (raw '
			.'Samsung exercise_type code)/xkey_ext (clock-span duration, minutes) shown as their '
			.'own columns, detail json offered as a collapsible block. See admin/schema_inc.php '
			.'for the same INSERT (fresh installs) and ImportExercise.php\'s own docblock for why '
			.'xkey_ext is the raw clock-span rather than Samsung\'s own smoothed duration figure. '
			.'Own dedicated \'exercise\' group/tab, not \'general\' - EXERCISE is multiple sessions '
			.'a day (walk/physio/etc), same inherent shape as PULSE/RESP/STEMP/HRV, which is why '
			.'those already have their own groups too.',
	],
	[
		[ 'QUERY' => [
			'SQL92' => [
				"INSERT INTO `{$X}liberty_xref_group` (`x_group`,`content_type_guid`,`title`,`sort_order`,`role_id`,`type_href`,`template`) VALUES ('exercise','healthday','Exercise',9,3,'','')",
				"INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`sort_order`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('EXERCISE','healthday','exercise','Exercise',-1,13,3,'','key-json-detail','[\"Type\",\"Duration (mins)\",\"Detail\"]')",
			],
		]],
	]
);
