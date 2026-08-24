<?php

// HealthDay itself is a pure liberty_content record, content_id only (same
// "no ID-alias table" reasoning as Food/Stock's own content types — see Claude
// memory feedback_content_id_only). WT/PULSE/OXI/BP/TEMP are all liberty_xref rows
// hung off a Day's content_id, not schema columns — see this package's own
// MANUAL.md for the full item-by-item design. All flagged multiple=-1
// (read-only — hides Edit/Delete icons and the "add" dropdown, since these are
// device-sourced readings nobody should hand-edit) — the importer still inserts
// many rows per item per day by computing its own xorder and never setting
// fAddXref, which is the only thing -1 actually blocks (see LibertyXref::verify()).
//
// health_hr_raw is the one real exception — a genuine table, not a liberty_xref
// item, added in package version 5.0.2 (see admin/upgrades/5.0.2.php for the full
// design rationale). Deliberately NOT registered here yet — a fresh install needs
// it too, but adding it before every live site has actually run the 5.0.2 upgrade
// breaks the installer's own upgrade detection for this package; see kernel/
// CLAUDE.md's 2026-08-23 entry for the framework-level why. Add it back here once
// every site is confirmed at 5.0.2.

global $gBitInstaller;

$gBitInstaller->registerPackageInfo( HEALTH_PKG_NAME, [
	'description' => 'Health tracks vitals, activity, and sleep — imported from Samsung Health, modeled on the Food package.',
	'license'     => '<a href="http://www.gnu.org/licenses/licenses.html#LGPL">LGPL</a>',
] );

$gBitInstaller->registerPreferences( HEALTH_PKG_NAME, [
	[ HEALTH_PKG_NAME, 'health_menu_text', 'Health' ],
] );

// ### Default User Permissions
$gBitInstaller->registerUserPermissions( HEALTH_PKG_NAME, [
	[ 'p_health_view',    'Can view health days and readings', 'registered', HEALTH_PKG_NAME ],
	[ 'p_health_create',  'Can create health days and readings', 'editors',  HEALTH_PKG_NAME ],
	[ 'p_health_update',  'Can update health days and readings', 'editors',  HEALTH_PKG_NAME ],
	[ 'p_health_expunge', 'Can delete health records',           'admin',    HEALTH_PKG_NAME ],
	[ 'p_health_admin',   'Can administer health',               'admin',    HEALTH_PKG_NAME ],
] );

// ### Register content types
$gBitInstaller->registerContentObjects( HEALTH_PKG_NAME, [
	'HealthDay' => HEALTH_PKG_CLASS_PATH.'HealthDay.php',
] );

// Never declared - a real gap (see project_installer_requirements memory), not just
// cosmetic: liberty 5.0.2 specifically added the sort_order column liberty_xref_item
// rows below rely on (see liberty/admin/upgrades/5.0.2.php) - every item registration
// in this file has depended on that column since it was written, undeclared until now.
$gBitInstaller->registerRequirements( HEALTH_PKG_NAME, [
	'kernel'  => [ 'min' => '5.0.0' ],
	'liberty' => [ 'min' => '5.0.2' ],
	'users'   => [ 'min' => '5.0.0' ],
] );

// ### Xref seed data
// liberty_xref_group: x_group, content_type_guid, title, sort_order, role_id, type_href
// liberty_xref_item:  item, content_type_guid, x_group, cross_ref_title, multiple, sort_order, role_id, cross_ref_href, template, data
$X = BIT_DB_PREFIX;

$xrefTypes = [];
$xrefItems = [];

// ── healthday xref groups — one dedicated group per item that can genuinely
// carry more than one row on a given day (WT/BP/OXI - multiple real readings
// - and PULSE/RESP/STEMP/HRV - always half-hour slots by design), plus one
// 'general' group for the always-exactly-one-row-per-day items. Was a single
// 'vitals' group at sort_order=0 until 5.0.3 — LibertyXrefType::loadContent()
// only ever loads groups with sort_order > 0, so that group (and every item
// in it) was invisible to the generic xref-group display framework
// (loadXrefInfo()/getXrefListTemplate(), the same one food/stock/contact
// use) the whole time, not merely ungrouped. See health/view_day.php's own
// docblock and admin/upgrades/5.0.3.php.
$xrefTypes[] = "INSERT INTO `{$X}liberty_xref_group` (`x_group`,`content_type_guid`,`title`,`sort_order`,`role_id`,`type_href`,`template`) VALUES ('general','healthday','General',1,3,'','')";
$xrefTypes[] = "INSERT INTO `{$X}liberty_xref_group` (`x_group`,`content_type_guid`,`title`,`sort_order`,`role_id`,`type_href`,`template`) VALUES ('wt','healthday','Weight',2,3,'','')";
$xrefTypes[] = "INSERT INTO `{$X}liberty_xref_group` (`x_group`,`content_type_guid`,`title`,`sort_order`,`role_id`,`type_href`,`template`) VALUES ('bp','healthday','Blood Pressure',3,3,'','')";
$xrefTypes[] = "INSERT INTO `{$X}liberty_xref_group` (`x_group`,`content_type_guid`,`title`,`sort_order`,`role_id`,`type_href`,`template`) VALUES ('oxi','healthday','Pulse Oximeter',4,3,'','')";
$xrefTypes[] = "INSERT INTO `{$X}liberty_xref_group` (`x_group`,`content_type_guid`,`title`,`sort_order`,`role_id`,`type_href`,`template`) VALUES ('pulse','healthday','Pulse',5,3,'','')";
$xrefTypes[] = "INSERT INTO `{$X}liberty_xref_group` (`x_group`,`content_type_guid`,`title`,`sort_order`,`role_id`,`type_href`,`template`) VALUES ('resp','healthday','Respiratory Rate',6,3,'','')";
$xrefTypes[] = "INSERT INTO `{$X}liberty_xref_group` (`x_group`,`content_type_guid`,`title`,`sort_order`,`role_id`,`type_href`,`template`) VALUES ('stemp','healthday','Skin Temperature',7,3,'','')";
$xrefTypes[] = "INSERT INTO `{$X}liberty_xref_group` (`x_group`,`content_type_guid`,`title`,`sort_order`,`role_id`,`type_href`,`template`) VALUES ('hrv','healthday','Heart Rate Variability',8,3,'','')";

// WT — weight (kg) + BMI as xkey/xkey_ext, body composition (body_fat/water/
// muscle/bones) as detail json. 'key-json-text' folds xkey/xkey_ext and the
// decoded json into one titled "Key: val, Key: val..." list — plain 'json-list'
// was dropping xkey/xkey_ext entirely, only 'value' was ever showing. `data`'s
// first 2 array entries are the title-keys for xkey/xkey_ext (same auto-title
// capitalize/underscore-strip as the real json field names that follow) — not
// real data fields themselves, just borrowing the same hint-array column so the
// view template can label xkey/xkey_ext to match. Convention applies to every
// item below using 'key-json-text'.
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`sort_order`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('WT','healthday','wt','Weight',-1,0,3,'','key-json-text','[\"weight_kg\",\"bmi\",\"body_fat_pct\",\"water_pct\",\"muscle_pct\",\"bone_mass_kg\"]')";

// BP — systolic/diastolic as the two co-equal headline values, pulse/map/source/
// comment/calibration_id as detail json (calibration_id only present on Samsung
// watch-PPG rows). 'key-json-text', see WT above.
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`sort_order`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('BP','healthday','bp','Blood Pressure',-1,1,3,'','key-json-text','[\"systolic\",\"diastolic\",\"pulse\",\"map\",\"source\",\"comment\",\"calibration_id\"]')";

// PULSE — one row per half-hour clock slot: xkey=slot average, xkey_ext=low/high
// json, data=that slot's own minute-level bins as json. 'key-json-detail':
// xkey_ext is itself a json object ('{"low":..,"high":..}') — the template
// auto-detects this (leading '{') and flattens it inline as titled Low/High
// instead of treating it as a plain scalar; `data`'s per-minute detail is too
// large to flatten, offered instead as a collapsible <details> block rather
// than dumped inline. See WT above for the general item_data title convention
// (here: [0]=xkey title, [1]=unused fallback since xkey_ext is always json,
// [2]=label for the collapsible detail block).
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`sort_order`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('PULSE','healthday','pulse','Pulse',-1,2,3,'','key-json-detail','[\"average\",\"low_high\",\"minute_detail\"]')";

// OXI — finger-probe pulse oximeter: SpO2 average + Pulse as the two co-equal
// headline values, spo2_min/max as detail json. 'key-json-text', see WT above.
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`sort_order`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('OXI','healthday','oxi','Pulse Oximeter',-1,3,3,'','key-json-text','[\"spo2_avg\",\"pulse\",\"spo2_min\",\"spo2_max\"]')";

// TEMP — plain scalar + text qualifier (Mode, e.g. "Ear temperature"), no
// data json. 'value' template (Value=xkey, Notes=xkey_ext) keeps the two
// separated instead of 'text''s unlabelled concatenation.
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`sort_order`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('TEMP','healthday','general','Temperature',-1,4,3,'','value',NULL)";

// STEPS — steps + active minutes (derived from milliseconds) as the headline
// pair, active kcal as detail json. No source found for the legacy
// spreadsheet's "Exercise Raised HR" column — left out, see ImportSteps.php.
// 'key-json-text', see WT above.
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`sort_order`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('STEPS','healthday','general','Steps',-1,5,3,'','key-json-text','[\"step_count\",\"active_mins\",\"active_kcal\"]')";

// ENERGY — Samsung's own vitality/readiness score + shrv_value (HRV), the
// same row both ride along in — see ImportEnergy.php's docblock for why HRV
// doesn't get its own item. 'key-json-text', see WT above.
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`sort_order`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('ENERGY','healthday','general','Energy',-1,6,3,'','key-json-text','[\"total_score\",\"shrv_value\",\"shrv_score\",\"activity_score\",\"sleep_score\"]')";

// SLEEP — one row per sleep *session*, not per day (multiple sessions/night
// are real, see ImportSleep.php). sleep_score + duration as headline pair,
// efficiency as detail. 'key-json-text', see WT above.
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`sort_order`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('SLEEP','healthday','general','Sleep',-1,7,3,'','key-json-text','[\"sleep_score\",\"sleep_duration\",\"efficiency\"]')";

// RESP — half-hour respiratory-rate slots, same shape as PULSE (xkey_ext
// low/high json, data=per-reading detail). 'key-json-detail', see PULSE above.
// See ImportRespiratoryRate.php.
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`sort_order`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('RESP','healthday','resp','Respiratory Rate',-1,8,3,'','key-json-detail','[\"average\",\"low_high\",\"minute_detail\"]')";

// STEMP — half-hour skin-temperature slots, same shape as RESP/PULSE.
// 'key-json-detail', see PULSE above. See ImportSkinTemperature.php.
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`sort_order`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('STEMP','healthday','stemp','Skin Temperature',-1,9,3,'','key-json-detail','[\"average\",\"low_high\",\"minute_detail\"]')";

// HRV — half-hour slots, sdnn+rmssd as the co-equal headline pair (plain
// scalars, unlike RESP/STEMP/PULSE's low/high-json xkey_ext), the richer
// per-reading tier (sdnn/rmssd/start_time per beat window) as data — live,
// not deferred as this comment previously said. 'key-json-detail': titled
// xkey/xkey_ext, data offered as a collapsible block rather than flattened
// (would be one line per reading otherwise). See ImportHRV.php.
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`sort_order`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('HRV','healthday','hrv','Heart Rate Variability',-1,10,3,'','key-json-detail','[\"sdnn\",\"rmssd\",\"slot_detail\"]')";

// STEPTRACK — one row per day (not per slot), full 144-bin intraday step
// track as data (array-of-objects, not a flat map — can't flatten inline).
// Genuinely richer companion to STEPS, which only has the coarse daily
// total. 'key-json-detail', see PULSE above: titled xkey/xkey_ext, data
// offered as a collapsible block. See ImportStepTrack.php.
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`sort_order`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('STEPTRACK','healthday','general','Step Track',-1,11,3,'','key-json-detail','[\"total_steps\",\"peak_10min\",\"day_track\"]')";

// RAISEDHR — one row per day (not per session - built against PULSE's
// continuous background source deliberately, so a day with no logged
// "exercise" still gets a real figure), minutes >=90/>=100bpm as the
// co-equal headline pair, exercise/background split + hr_min/hr_max as
// detail json. 'key-json-text', see WT above.
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`sort_order`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('RAISEDHR','healthday','general','Raised HR',-1,12,3,'','key-json-text','[\"mins_90\",\"mins_100\",\"mins_130\",\"exercise_mins_90\",\"exercise_mins_100\",\"exercise_mins_130\",\"exercise_sample_count\",\"background_mins_90\",\"background_mins_100\",\"background_mins_130\",\"background_sample_count\",\"hr_min\",\"hr_max\"]')";

$gBitInstaller->registerSchemaDefault( HEALTH_PKG_NAME, array_merge( $xrefTypes, $xrefItems ) );
