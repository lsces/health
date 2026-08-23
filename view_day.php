<?php
/**
 * Day view — every xref item logged against one HealthDay content_id, split
 * across tabs: a "Summary" tab for every item with exactly one row that day
 * (title + xkey/xkey_ext inline, one line each — the same shape as the
 * calendar tile, just every single-value item instead of just WT/BP/PULSE),
 * then one tab per item with more than one row that day (PULSE/RESP/STEMP/
 * HRV half-hour slots, SLEEP sessions, etc.), each a plain read-only
 * When/xkey/xkey_ext/data table — same column-title convention as
 * list_item.php. "Single" vs "multi" is decided per day from the actual row
 * count, not a fixed per-item list, since some items genuinely vary (BP/OXI/
 * TEMP/WT can be one or several readings depending on the day).
 *
 * Summary tab deliberately doesn't surface each item's own `data` json yet
 * (body composition, calibration_id, etc.) — todo, per Lester's own framing:
 * "pad out later with bits hidden in detail."
 *
 * Deliberately generic, not the curated single-headline-figure-per-item
 * rollup HealthDaySummary.php is meant for eventually — this just needs to
 * be a real link target for the calendar day-cell (HealthDay::
 * getDisplayUrl()) instead of falling through to the bare kernel content_id
 * router with nowhere to land.
 *
 * @package health
 */

namespace Bitweaver\Health;

use Bitweaver\KernelTools;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty, $gBitDb;

$gBitSystem->verifyPackage( 'health' );
$gBitSystem->verifyPermission( 'p_health_view' );

$contentId = (int)( $_REQUEST['content_id'] ?? 0 );
$gContent = new HealthDay( $contentId );
$gContent->load();
if( !$gContent->isValid() ) {
	$gBitSystem->fatalError( 'No valid day specified.' );
}

$X = BIT_DB_PREFIX;
$columnTitles = HealthDay::getItemColumnTitles();

$rows = $gBitDb->getAll(
	"SELECT x.`item`, xi.`cross_ref_title`, xi.`sort_order`,
		x.`xkey`, x.`xkey_ext`, x.`data`, x.`start_date`, x.`xorder`
		FROM `{$X}liberty_xref` x
		JOIN `{$X}liberty_xref_item` xi ON ( xi.`item` = x.`item` AND xi.`content_type_guid` = 'healthday' )
		WHERE x.`content_id` = ?
		ORDER BY xi.`sort_order`, x.`item`, x.`xorder`, x.`start_date`",
	[ $contentId ]
);

$groups = []; // item => [ 'title', 'xkeyTitle', 'xkeyExtTitle', 'dataTitle', 'rows' ]
foreach( $rows as $row ) {
	$item = $row['item'];
	if( !isset( $groups[$item] ) ) {
		[ $xkeyTitle, $xkeyExtTitle, $dataTitle ] = $columnTitles[$item] ?? [ 'xkey', 'xkey_ext', 'data' ];
		$groups[$item] = [
			'title'        => $row['cross_ref_title'],
			'xkeyTitle'    => $xkeyTitle,
			'xkeyExtTitle' => $xkeyExtTitle,
			'dataTitle'    => $dataTitle,
			'rows'         => [],
		];
	}
	// Same collapse-behind-<details> treatment as list_item.php for dense json
	// (PULSE's per-slot bin arrays and similar) - see that file's own comment.
	$decoded = json_decode( (string)$row['data'], true );
	$row['data_summary'] = ( is_array( $decoded ) && count( $decoded ) > 3 ) ? count( $decoded ).' items' : null;
	$groups[$item]['rows'][] = $row;
}

$singleItems = $multiItems = [];
foreach( $groups as $item => $g ) {
	if( count( $g['rows'] ) === 1 ) {
		$singleItems[$item] = $g;
	} else {
		$multiItems[$item] = $g;
	}
}

$gBitSmarty->assign( 'gContent',     $gContent );
$gBitSmarty->assign( 'singleItems',  $singleItems );
$gBitSmarty->assign( 'multiItems',   $multiItems );

$gBitSystem->display( 'bitpackage:health/view_day.tpl', KernelTools::tra( 'Day' ).': '.$gContent->getTitle() );
