<?php
/**
 * Day view — every xref item logged against one HealthDay content_id,
 * generic per-item rendering (title/xkey/xkey_ext/data grouped by item, same
 * column-title convention as list_item.php). Deliberately generic, not the
 * curated single-headline-figure-per-item rollup HealthDaySummary.php is
 * meant for eventually — this just needs to be a real link target for the
 * calendar day-cell (HealthDay::getDisplayUrl()) instead of falling through
 * to the bare kernel content_id router with nowhere to land.
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

$gBitSmarty->assign( 'gContent', $gContent );
$gBitSmarty->assign( 'groups',   $groups );

$gBitSystem->display( 'bitpackage:health/view_day.tpl', KernelTools::tra( 'Day' ).': '.$gContent->getTitle() );
