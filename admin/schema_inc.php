<?php

// No tables — HealthDay is a pure liberty_content record, content_id only (same
// "no ID-alias table" reasoning as Food/Stock's own content types — see Claude
// memory feedback_content_id_only). WT/PULSE/OXI/BP/TEMP are all liberty_xref rows
// hung off a Day's content_id, not schema columns — see this package's own
// MANUAL.md for the full item-by-item design. All flagged multiple=-1
// (read-only — hides Edit/Delete icons and the "add" dropdown, since these are
// device-sourced readings nobody should hand-edit) — the importer still inserts
// many rows per item per day by computing its own xorder and never setting
// fAddXref, which is the only thing -1 actually blocks (see LibertyXref::verify()).

global $gBitInstaller;

$gBitInstaller->registerPackageInfo( HEALTH_PKG_NAME, [
	'description' => 'Health tracks vitals, activity, and sleep — imported from Samsung Health, modeled on the Food package.',
	'license'     => '<a href="http://www.gnu.org/licenses/licenses.html#LGPL">LGPL</a>',
] );

$gBitInstaller->registerPreferences( HEALTH_PKG_NAME, [
	[ HEALTH_PKG_NAME, 'health_menu_text', 'Health' ],
] );

// ### Register content types
$gBitInstaller->registerContentObjects( HEALTH_PKG_NAME, [
	'HealthDay' => HEALTH_PKG_CLASS_PATH.'HealthDay.php',
] );

// ### Default User Permissions
$gBitInstaller->registerUserPermissions( HEALTH_PKG_NAME, [
	[ 'p_health_view',    'Can view health days and readings', 'registered', HEALTH_PKG_NAME ],
	[ 'p_health_create',  'Can create health days and readings', 'editors',  HEALTH_PKG_NAME ],
	[ 'p_health_update',  'Can update health days and readings', 'editors',  HEALTH_PKG_NAME ],
	[ 'p_health_expunge', 'Can delete health records',           'admin',    HEALTH_PKG_NAME ],
	[ 'p_health_admin',   'Can administer health',               'admin',    HEALTH_PKG_NAME ],
] );

// ### Xref seed data
// liberty_xref_group: x_group, content_type_guid, title, sort_order, role_id, type_href
// liberty_xref_item:  item, content_type_guid, x_group, cross_ref_title, multiple, sort_order, role_id, cross_ref_href, template, data
$X = BIT_DB_PREFIX;

$xrefTypes = [];
$xrefItems = [];

// ── healthday 'vitals' group (sort_order=0) — WT first; PULSE/OXI/BP/TEMP join it
// as they're built, one item each, same shape (see health/MANUAL.md).
$xrefTypes[] = "INSERT INTO `{$X}liberty_xref_group` (`x_group`,`content_type_guid`,`title`,`sort_order`,`role_id`,`type_href`,`template`) VALUES ('vitals','healthday','Vitals',0,3,'','')";

// WT — weight (kg) + BMI, body composition (body_fat/water/muscle/bones) as a
// json-list blob with a registered hint array, same convention as Food's FAT/VIT/MIN.
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`sort_order`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('WT','healthday','vitals','Weight',-1,0,3,'','json-list','[\"body_fat_pct\",\"water_pct\",\"muscle_pct\",\"bone_mass_kg\"]')";

// BP — systolic/diastolic as the two co-equal headline values ('value' template,
// same as liberty's own two-value case), pulse/map/source/comment/calibration_id
// as detail json (calibration_id only present on Samsung watch-PPG rows).
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`sort_order`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('BP','healthday','vitals','Blood Pressure',-1,1,3,'','value','[\"pulse\",\"map\",\"source\",\"comment\",\"calibration_id\"]')";

// PULSE — one row per half-hour clock slot: xkey=slot average, xkey_ext=low/high
// json, data=that slot's own minute-level bins as json. template='text' as a
// placeholder (neither xkey_ext nor data here fit an existing generic template's
// exact rendering — xkey_ext's own JSON isn't the json-list/json-text convention,
// which only covers the data column) — revisit once an actual day view gets built.
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`sort_order`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('PULSE','healthday','vitals','Pulse',-1,2,3,'','text',NULL)";

// OXI — finger-probe pulse oximeter, same 'value' shape as BP: SpO2 average +
// Pulse as the two co-equal headline values, spo2_min/max as detail json.
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`sort_order`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('OXI','healthday','vitals','Pulse Oximeter',-1,3,3,'','value','[\"spo2_min\",\"spo2_max\"]')";

// TEMP — plain scalar + text qualifier (Mode, e.g. "Ear temperature"), no
// co-equal second value and nothing left over for a data json blob.
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`sort_order`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('TEMP','healthday','vitals','Temperature',-1,4,3,'','text',NULL)";

// STEPS — steps + active minutes (derived from milliseconds) as the headline
// pair, active kcal as detail json. No source found for the legacy
// spreadsheet's "Exercise Raised HR" column — left out, see ImportSteps.php.
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`sort_order`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('STEPS','healthday','vitals','Steps',-1,5,3,'','value','[\"active_kcal\"]')";

// ENERGY — Samsung's own vitality/readiness score + shrv_value (HRV), the
// same row both ride along in — see ImportEnergy.php's docblock for why HRV
// doesn't get its own item.
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`sort_order`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('ENERGY','healthday','vitals','Energy',-1,6,3,'','value','[\"shrv_score\",\"activity_score\",\"sleep_score\"]')";

// SLEEP — one row per sleep *session*, not per day (multiple sessions/night
// are real, see ImportSleep.php). sleep_score + duration as headline pair,
// efficiency as detail.
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`sort_order`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('SLEEP','healthday','vitals','Sleep',-1,7,3,'','value','[\"efficiency\"]')";

$gBitInstaller->registerSchemaDefault( HEALTH_PKG_NAME, array_merge( $xrefTypes, $xrefItems ) );
