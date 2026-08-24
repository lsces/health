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
			.'(TEMP/STEPS/ENERGY/SLEEP/STEPTRACK/RAISEDHR - always exactly one row per day) plus '
			.'one dedicated group each for WT/BP/OXI (can carry multiple real readings a day) and '
			.'PULSE/RESP/STEMP/HRV (always half-hour slots by design). '
			.'LibertyXrefType::loadContent() only ever loads groups with sort_order > 0, so the old '
			.'\'vitals\' group at sort_order=0 - and every item in it - was invisible to the generic '
			.'xref-group display framework (loadXrefInfo()/getXrefListTemplate(), the same one '
			.'food/stock/contact already use) the whole time, not merely ungrouped. '
			.'Also gives every item a real display template instead of the placeholder '
			.'text/value-with-no-hint state: \'key-json-text\' (WT, BP, OXI, STEPS, ENERGY, SLEEP, '
			.'RAISEDHR) merges xkey/xkey_ext and the item\'s detail-json blob into one titled '
			.'"Key: val, ..." list; \'key-json-detail\' (PULSE, RESP, STEMP, HRV, STEPTRACK) keeps '
			.'xkey/xkey_ext separate from the detail json, which is offered as a collapsible '
			.'<details> block rather than flattened, since it\'s a bulk array (per-minute/per-'
			.'reading detail, or PULSE/RESP/STEMP\'s own low/high-json xkey_ext) rather than a '
			.'flat key-to-scalar map; TEMP moves from \'text\' to \'value\' (no detail json to add, '
			.'just wanted xkey/xkey_ext split into separate columns instead of concatenated). '
			.'liberty_xref_item.data now doubles as the title-key hint for xkey/xkey_ext (its own '
			.'first array entries) as well as the flat data json\'s field names. See '
			.'health/view_day.php\'s own docblock and admin/schema_inc.php for the full reasoning.',
	],
	[
		[ 'QUERY' => [
			'SQL92' => [
				"INSERT INTO `{$X}liberty_xref_group` (`x_group`,`content_type_guid`,`title`,`sort_order`,`role_id`,`type_href`,`template`) VALUES ('general','healthday','General',1,3,'','')",
				"INSERT INTO `{$X}liberty_xref_group` (`x_group`,`content_type_guid`,`title`,`sort_order`,`role_id`,`type_href`,`template`) VALUES ('wt','healthday','Weight',2,3,'','')",
				"INSERT INTO `{$X}liberty_xref_group` (`x_group`,`content_type_guid`,`title`,`sort_order`,`role_id`,`type_href`,`template`) VALUES ('bp','healthday','Blood Pressure',3,3,'','')",
				"INSERT INTO `{$X}liberty_xref_group` (`x_group`,`content_type_guid`,`title`,`sort_order`,`role_id`,`type_href`,`template`) VALUES ('oxi','healthday','Pulse Oximeter',4,3,'','')",
				"INSERT INTO `{$X}liberty_xref_group` (`x_group`,`content_type_guid`,`title`,`sort_order`,`role_id`,`type_href`,`template`) VALUES ('pulse','healthday','Pulse',5,3,'','')",
				"INSERT INTO `{$X}liberty_xref_group` (`x_group`,`content_type_guid`,`title`,`sort_order`,`role_id`,`type_href`,`template`) VALUES ('resp','healthday','Respiratory Rate',6,3,'','')",
				"INSERT INTO `{$X}liberty_xref_group` (`x_group`,`content_type_guid`,`title`,`sort_order`,`role_id`,`type_href`,`template`) VALUES ('stemp','healthday','Skin Temperature',7,3,'','')",
				"INSERT INTO `{$X}liberty_xref_group` (`x_group`,`content_type_guid`,`title`,`sort_order`,`role_id`,`type_href`,`template`) VALUES ('hrv','healthday','Heart Rate Variability',8,3,'','')",

				// Reassign each item to its new group and give it a real display template +
				// item_data hint array (xkey/xkey_ext titles first, then the flat data-json
				// field names where the item has one) — see admin/schema_inc.php for the
				// per-item rationale, kept in sync here.
				"UPDATE `{$X}liberty_xref_item` SET `x_group` = 'wt', `template` = 'key-json-text', `data` = '[\"weight_kg\",\"bmi\",\"body_fat_pct\",\"water_pct\",\"muscle_pct\",\"bone_mass_kg\"]' WHERE `content_type_guid` = 'healthday' AND `item` = 'WT'",
				"UPDATE `{$X}liberty_xref_item` SET `x_group` = 'bp', `template` = 'key-json-text', `data` = '[\"systolic\",\"diastolic\",\"pulse\",\"map\",\"source\",\"comment\",\"calibration_id\"]' WHERE `content_type_guid` = 'healthday' AND `item` = 'BP'",
				"UPDATE `{$X}liberty_xref_item` SET `x_group` = 'oxi', `template` = 'key-json-text', `data` = '[\"spo2_avg\",\"pulse\",\"spo2_min\",\"spo2_max\"]' WHERE `content_type_guid` = 'healthday' AND `item` = 'OXI'",
				"UPDATE `{$X}liberty_xref_item` SET `x_group` = 'pulse', `template` = 'key-json-detail', `data` = '[\"average\",\"low_high\",\"minute_detail\"]' WHERE `content_type_guid` = 'healthday' AND `item` = 'PULSE'",
				"UPDATE `{$X}liberty_xref_item` SET `x_group` = 'resp', `template` = 'key-json-detail', `data` = '[\"average\",\"low_high\",\"minute_detail\"]' WHERE `content_type_guid` = 'healthday' AND `item` = 'RESP'",
				"UPDATE `{$X}liberty_xref_item` SET `x_group` = 'stemp', `template` = 'key-json-detail', `data` = '[\"average\",\"low_high\",\"minute_detail\"]' WHERE `content_type_guid` = 'healthday' AND `item` = 'STEMP'",
				"UPDATE `{$X}liberty_xref_item` SET `x_group` = 'hrv', `template` = 'key-json-detail', `data` = '[\"sdnn\",\"rmssd\",\"slot_detail\"]' WHERE `content_type_guid` = 'healthday' AND `item` = 'HRV'",
				"UPDATE `{$X}liberty_xref_item` SET `x_group` = 'general', `template` = 'value' WHERE `content_type_guid` = 'healthday' AND `item` = 'TEMP'",
				"UPDATE `{$X}liberty_xref_item` SET `x_group` = 'general', `template` = 'key-json-text', `data` = '[\"step_count\",\"active_mins\",\"active_kcal\"]' WHERE `content_type_guid` = 'healthday' AND `item` = 'STEPS'",
				"UPDATE `{$X}liberty_xref_item` SET `x_group` = 'general', `template` = 'key-json-text', `data` = '[\"total_score\",\"shrv_value\",\"shrv_score\",\"activity_score\",\"sleep_score\"]' WHERE `content_type_guid` = 'healthday' AND `item` = 'ENERGY'",
				"UPDATE `{$X}liberty_xref_item` SET `x_group` = 'general', `template` = 'key-json-text', `data` = '[\"sleep_score\",\"sleep_duration\",\"efficiency\"]' WHERE `content_type_guid` = 'healthday' AND `item` = 'SLEEP'",
				"UPDATE `{$X}liberty_xref_item` SET `x_group` = 'general', `template` = 'key-json-detail', `data` = '[\"total_steps\",\"peak_10min\",\"day_track\"]' WHERE `content_type_guid` = 'healthday' AND `item` = 'STEPTRACK'",
				"UPDATE `{$X}liberty_xref_item` SET `x_group` = 'general', `template` = 'key-json-text', `data` = '[\"mins_90\",\"mins_100\",\"mins_130\",\"exercise_mins_90\",\"exercise_mins_100\",\"exercise_mins_130\",\"exercise_sample_count\",\"background_mins_90\",\"background_mins_100\",\"background_mins_130\",\"background_sample_count\",\"hr_min\",\"hr_max\"]' WHERE `content_type_guid` = 'healthday' AND `item` = 'RAISEDHR'",

				"DELETE FROM `{$X}liberty_xref_group` WHERE `content_type_guid` = 'healthday' AND `x_group` = 'vitals'",
			],
		]],
	]
);
