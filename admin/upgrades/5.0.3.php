<?php
/**
 * @package health
 */

global $gBitInstaller;

$X = BIT_DB_PREFIX;

$gBitInstaller->registerPackageUpgrade(
	[
		'package'     => HEALTH_PKG_NAME,
		'version'     => '5.0.3',
		'description' => 'Split the single \'vitals\' xref group (sort_order=0) into \'general\' '
			.'(every item that is not an inherently-multi half-hour-slot item: WT/BP/OXI/TEMP/'
			.'STEPS/ENERGY/SLEEP/STEPTRACK/RAISEDHR) plus one dedicated group each for PULSE/RESP/'
			.'STEMP/HRV. LibertyXrefType::loadContent() only ever loads groups with sort_order > 0, '
			.'so the old \'vitals\' group at sort_order=0 - and every item in it - was invisible to '
			.'the generic xref-group display framework (loadXrefInfo()/getXrefListTemplate(), the '
			.'same one food/stock/contact already use) the whole time, not merely ungrouped. See '
			.'health/view_day.php\'s own docblock for the full reasoning.',
	],
	[
		[ 'QUERY' => [
			'SQL92' => [
				"INSERT INTO `{$X}liberty_xref_group` (`x_group`,`content_type_guid`,`title`,`sort_order`,`role_id`,`type_href`,`template`) VALUES ('general','healthday','General',1,3,'','')",
				"INSERT INTO `{$X}liberty_xref_group` (`x_group`,`content_type_guid`,`title`,`sort_order`,`role_id`,`type_href`,`template`) VALUES ('pulse','healthday','Pulse',2,3,'','')",
				"INSERT INTO `{$X}liberty_xref_group` (`x_group`,`content_type_guid`,`title`,`sort_order`,`role_id`,`type_href`,`template`) VALUES ('resp','healthday','Respiratory Rate',3,3,'','')",
				"INSERT INTO `{$X}liberty_xref_group` (`x_group`,`content_type_guid`,`title`,`sort_order`,`role_id`,`type_href`,`template`) VALUES ('stemp','healthday','Skin Temperature',4,3,'','')",
				"INSERT INTO `{$X}liberty_xref_group` (`x_group`,`content_type_guid`,`title`,`sort_order`,`role_id`,`type_href`,`template`) VALUES ('hrv','healthday','Heart Rate Variability',5,3,'','')",
				"UPDATE `{$X}liberty_xref_item` SET `x_group` = 'general' WHERE `content_type_guid` = 'healthday' AND `item` IN ('WT','BP','OXI','TEMP','STEPS','ENERGY','SLEEP','STEPTRACK','RAISEDHR')",
				"UPDATE `{$X}liberty_xref_item` SET `x_group` = 'pulse' WHERE `content_type_guid` = 'healthday' AND `item` = 'PULSE'",
				"UPDATE `{$X}liberty_xref_item` SET `x_group` = 'resp' WHERE `content_type_guid` = 'healthday' AND `item` = 'RESP'",
				"UPDATE `{$X}liberty_xref_item` SET `x_group` = 'stemp' WHERE `content_type_guid` = 'healthday' AND `item` = 'STEMP'",
				"UPDATE `{$X}liberty_xref_item` SET `x_group` = 'hrv' WHERE `content_type_guid` = 'healthday' AND `item` = 'HRV'",
				"DELETE FROM `{$X}liberty_xref_group` WHERE `content_type_guid` = 'healthday' AND `x_group` = 'vitals'",
			],
		]],
	]
);
