<?php
/**
 * Raw xref data browser — one xref item at a time (WT/BP/PULSE/OXI/TEMP/
 * STEPS/ENERGY/SLEEP/...), selected via a row of radio buttons across the
 * top. Deliberately shows raw xkey/xkey_ext/data as stored, no per-item
 * formatting beyond a friendlier column title — this is a verification/
 * debug tool, not the eventual day view.
 *
 * @package health
 */

namespace Bitweaver\Health;

use Bitweaver\BitBase;
use Bitweaver\Liberty\LibertyContent;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty, $gBitDb, $gBitUser;

$gBitSystem->verifyPackage( 'health' );
$gBitSystem->verifyPermission( 'p_health_view' );

// edit=y + admin permission unlocks the per-row Archive button; history=y shows
// already-archived (end_date set) rows alongside live ones. Neither is fatal if
// requested without permission - edit mode just silently degrades to view-only
// rather than error, since this is a URL switch a viewer could stumble onto.
$editRequested    = !empty( $_REQUEST['edit'] );
$historyRequested = !empty( $_REQUEST['history'] );
$editMode = $editRequested && $gBitUser->hasPermission( 'p_health_admin' );

// Archive action - handled before any output so the redirect back to this same
// filtered view can still happen. Deliberately not routed through liberty's own
// edit_xref.php controller: that redirects to the content item's own edit page on
// success, which would bounce the user off this raw-data browser entirely.
if( !empty( $_REQUEST['farchive'] ) && !empty( $_REQUEST['xref_id'] ) && !empty( $_REQUEST['content_id'] ) ) {
	if( $gBitUser->hasPermission( 'p_health_admin' ) ) {
		$content = LibertyContent::getLibertyObject( (int)$_REQUEST['content_id'] );
		if( $content && $content->isValid() ) {
			$content->verifyUpdatePermission();
			$stepHash = [ 'xref_id' => (int)$_REQUEST['xref_id'], 'expunge' => 1 ];
			$content->stepXref( $stepHash );
		}
	}
	$qs = array_filter( [
		'item'        => $_REQUEST['item']    ?? '',
		'from'        => $_REQUEST['from']    ?? '',
		'to'          => $_REQUEST['to']      ?? '',
		'edit'        => $editRequested    ? 'y' : '',
		'history'     => $historyRequested ? 'y' : '',
		'offset'      => $_REQUEST['offset']      ?? '',
		'max_records' => $_REQUEST['max_records'] ?? '',
	], fn( $v ) => $v !== '' );
	header( 'Location: list_item.php?'.http_build_query( $qs ) );
	die;
}

$X = BIT_DB_PREFIX;

// Per-item column titles for xkey/xkey_ext/data — shared with view_day.php,
// see HealthDay::getItemColumnTitles()'s own docblock.
$columnTitles = HealthDay::getItemColumnTitles();

$items = $gBitDb->getAll(
	"SELECT `item`, `cross_ref_title` FROM `{$X}liberty_xref_item`
		WHERE `content_type_guid` = 'healthday' ORDER BY `sort_order`, `item`"
);

$selectedItem = $_REQUEST['item'] ?? '';
$validItems   = array_column( $items, 'item' );
if( !in_array( $selectedItem, $validItems, true ) ) {
	$selectedItem = $validItems[0] ?? '';
}

[ $xkeyTitle, $xkeyExtTitle, $dataTitle ] = $columnTitles[$selectedItem] ?? [ 'xkey', 'xkey_ext', 'data' ];

// Optional date-range narrowing (the same From/To bar every other Health page now uses) -
// blank by default, so this still browses everything unless a range is actually picked.
$from = trim( $_REQUEST['from'] ?? '' );
$to   = trim( $_REQUEST['to']   ?? '' );
if( $from !== '' && !preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
	$from = '';
}
if( $to !== '' && !preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
	$to = '';
}
if( $from !== '' && $to !== '' && $from > $to ) {
	[ $from, $to ] = [ $to, $from ];
}
$dateWhere  = '';
$dateParams = [];
if( $from !== '' ) {
	$dateWhere   .= " AND lc.`title` >= ?";
	$dateParams[] = $from;
}
if( $to !== '' ) {
	$dateWhere   .= " AND lc.`title` <= ?";
	$dateParams[] = $to;
}

// 20 per page here rather than the site's own max_records default (10) —
// still overridable via ?max_records= same as any other paginated list.
$_REQUEST['max_records'] = $_REQUEST['max_records'] ?? 20;
BitBase::prepGetList( $_REQUEST );

// history=y shows archived rows too; default view hides anything with end_date set.
$historyWhere = $historyRequested ? '' : " AND x.`end_date` IS NULL";

$rows = [];
if( $selectedItem !== '' ) {
	$_REQUEST['cant'] = (int)$gBitDb->getOne(
		"SELECT COUNT(*) FROM `{$X}liberty_xref` x
			JOIN `{$X}liberty_content` lc ON ( lc.`content_id` = x.`content_id` )
			WHERE x.`item` = ? AND lc.`content_type_guid` = 'healthday'{$dateWhere}{$historyWhere}",
		[ $selectedItem, ...$dateParams ]
	);

	$rows = $gBitDb->getAll(
		"SELECT FIRST ".(int)$_REQUEST['max_records']." SKIP ".(int)$_REQUEST['offset']."
			lc.`title` AS `day_title`, x.`xref_id`, x.`content_id`, x.`start_date`,
			x.`xkey`, x.`xkey_ext`, x.`data`, x.`end_date`
			FROM `{$X}liberty_xref` x
			JOIN `{$X}liberty_content` lc ON ( lc.`content_id` = x.`content_id` )
			WHERE x.`item` = ? AND lc.`content_type_guid` = 'healthday'{$dateWhere}{$historyWhere}
			ORDER BY lc.`title` DESC, x.`start_date` DESC",
		[ $selectedItem, ...$dateParams ]
	);

		// PULSE's per-slot bin arrays (and anything else similarly dense) make
		// the raw JSON column unreadable - collapse anything past a handful of
		// array items behind a <details> disclosure in the template, summary
		// text only, rather than truncating the string (which would just cut
		// valid JSON mid-object).
		$tz = $gBitUser->getUserTimezone();
		foreach( $rows as &$row ) {
			$decoded = json_decode( (string)$row['data'], true );
			$row['data_summary'] = ( is_array( $decoded ) && count( $decoded ) > 3 )
				? count( $decoded ).' items' : null;
			// start_date is stored UTC (see ImportWT.php/ImportBP.php's own docblocks) -
			// converted to local time here so morning/evening readings are distinguishable
			// at a glance, same as the day-summary reports already do.
			$row['time'] = ( new \DateTime( $row['start_date'], new \DateTimeZone( 'UTC' ) ) )
				->setTimezone( $tz )->format( 'H:i' );
			$row['is_history'] = !empty( $row['end_date'] );
		}
		unset( $row );
}

BitBase::postGetList( $_REQUEST );
$_REQUEST['listInfo']['parameters'] = [
	'item'    => $selectedItem, 'from' => $from, 'to' => $to,
	'edit'    => $editRequested    ? 'y' : '',
	'history' => $historyRequested ? 'y' : '',
];

$gBitSmarty->assign( 'items',            $items );
$gBitSmarty->assign( 'selectedItem',     $selectedItem );
$gBitSmarty->assign( 'from',             $from );
$gBitSmarty->assign( 'to',               $to );
$gBitSmarty->assign( 'rows',             $rows );
$gBitSmarty->assign( 'total',            $_REQUEST['cant'] ?? 0 );
$gBitSmarty->assign( 'xkeyTitle',        $xkeyTitle );
$gBitSmarty->assign( 'xkeyExtTitle',     $xkeyExtTitle );
$gBitSmarty->assign( 'dataTitle',        $dataTitle );
$gBitSmarty->assign( 'listInfo',         $_REQUEST['listInfo'] );
$gBitSmarty->assign( 'editMode',         $editMode );
$gBitSmarty->assign( 'canEditMode',      $gBitUser->hasPermission( 'p_health_admin' ) );
$gBitSmarty->assign( 'editRequested',    $editRequested );
$gBitSmarty->assign( 'historyRequested', $historyRequested );

$gBitSystem->display( 'bitpackage:health/list_item.tpl', 'Health Raw Data' );
