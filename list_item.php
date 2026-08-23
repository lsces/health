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

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty, $gBitDb;

$gBitSystem->verifyPackage( 'health' );
$gBitSystem->verifyPermission( 'p_health_view' );

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

// 20 per page here rather than the site's own max_records default (10) —
// still overridable via ?max_records= same as any other paginated list.
$_REQUEST['max_records'] = $_REQUEST['max_records'] ?? 20;
BitBase::prepGetList( $_REQUEST );

$rows = [];
if( $selectedItem !== '' ) {
	$_REQUEST['cant'] = (int)$gBitDb->getOne(
		"SELECT COUNT(*) FROM `{$X}liberty_xref` x
			JOIN `{$X}liberty_content` lc ON ( lc.`content_id` = x.`content_id` )
			WHERE x.`item` = ? AND lc.`content_type_guid` = 'healthday'",
		[ $selectedItem ]
	);

	$rows = $gBitDb->getAll(
		"SELECT FIRST ".(int)$_REQUEST['max_records']." SKIP ".(int)$_REQUEST['offset']."
			lc.`title` AS `day_title`, x.`xkey`, x.`xkey_ext`, x.`data`
			FROM `{$X}liberty_xref` x
			JOIN `{$X}liberty_content` lc ON ( lc.`content_id` = x.`content_id` )
			WHERE x.`item` = ? AND lc.`content_type_guid` = 'healthday'
			ORDER BY x.`entry_date` DESC",
		[ $selectedItem ]
	);

		// PULSE's per-slot bin arrays (and anything else similarly dense) make
		// the raw JSON column unreadable - collapse anything past a handful of
		// array items behind a <details> disclosure in the template, summary
		// text only, rather than truncating the string (which would just cut
		// valid JSON mid-object).
		foreach( $rows as &$row ) {
			$decoded = json_decode( (string)$row['data'], true );
			$row['data_summary'] = ( is_array( $decoded ) && count( $decoded ) > 3 )
				? count( $decoded ).' items' : null;
		}
		unset( $row );
}

BitBase::postGetList( $_REQUEST );
$_REQUEST['listInfo']['parameters'] = [ 'item' => $selectedItem ];

$gBitSmarty->assign( 'items',         $items );
$gBitSmarty->assign( 'selectedItem',  $selectedItem );
$gBitSmarty->assign( 'rows',          $rows );
$gBitSmarty->assign( 'total',         $_REQUEST['cant'] ?? 0 );
$gBitSmarty->assign( 'xkeyTitle',     $xkeyTitle );
$gBitSmarty->assign( 'xkeyExtTitle',  $xkeyExtTitle );
$gBitSmarty->assign( 'dataTitle',     $dataTitle );
$gBitSmarty->assign( 'listInfo',      $_REQUEST['listInfo'] );

$gBitSystem->display( 'bitpackage:health/list_item.tpl', 'Health Raw Data' );
