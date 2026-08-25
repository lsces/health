<?php
/**
 * Per-item record counts + date ranges for index.php's summary page — one
 * query across every registered `healthday` xref item, not a function per
 * item like HealthDaySummary.php's day-view helpers (those pick a single
 * headline reading per day; this just counts/bounds the raw data).
 *
 * @package health
 */

namespace Bitweaver\Health;

/**
 * @return array<string, array{title:string, count:int, min_date:?string, max_date:?string}>
 *         Keyed by item code, in liberty_xref_item's own sort_order.
 */
function healthIndexItemSummary(): array {
	global $gBitDb;
	$X = BIT_DB_PREFIX;

	$items = $gBitDb->getAll(
		"SELECT `item`, `cross_ref_title` FROM `{$X}liberty_xref_item`
			WHERE `content_type_guid` = 'healthday' ORDER BY `sort_order`"
	);

	$counts = $gBitDb->getAll(
		"SELECT x.`item`, COUNT(*) AS `cnt`, MIN(x.`start_date`) AS `min_date`, MAX(x.`start_date`) AS `max_date`
			FROM `{$X}liberty_xref` x
			JOIN `{$X}liberty_content` lc ON ( lc.`content_id` = x.`content_id` )
			WHERE lc.`content_type_guid` = 'healthday'
			GROUP BY x.`item`"
	);
	$countsByItem = array_column( $counts, null, 'item' );

	$ret = [];
	foreach( $items as $item ) {
		$row = $countsByItem[$item['item']] ?? null;
		$ret[$item['item']] = [
			'title'    => $item['cross_ref_title'],
			'count'    => (int)( $row['cnt'] ?? 0 ),
			'min_date' => $row['min_date'] ?? null,
			'max_date' => $row['max_date'] ?? null,
		];
	}
	return $ret;
}

/**
 * BP and OXI each blend two real device sources into one xref item (see
 * ImportBP.php/ImportBPSamsung.php and ImportOxi.php/ImportOxiSamsung.php's
 * own docblocks) — a plain per-item count would hide that split. Every
 * Samsung-sourced row tags its `data` json with `"source":"watch"`
 * (BP's watch-PPG rows) or `"source":"cuff"` (BP's Samsung-synced cuff
 * rows); HealthForYou's own plain cuff import also tags `"source":"cuff"`
 * — the same physical device, so folding both cuff-tagged origins together
 * as one "cuff" bucket is correct, not a source-tracking gap. OXI's
 * HealthForYou rows carry no `source` key at all (empty detail json),
 * distinguishing them from OXI's Samsung `"source":"watch"` rows.
 *
 * A plain `data LIKE '%"source":"watch"%'` substring match is used rather
 * than decoding every row's json in PHP — cheap (still only hundreds/low
 * thousands of rows per item, not HEALTH_HR_RAW's scale) and the exact
 * literal these importers always write.
 *
 * @param  string $pItem  'BP' or 'OXI'.
 * @return array{cuff:int, watch:int}
 */
function healthIndexSourceSplit( string $pItem ): array {
	global $gBitDb;
	$X = BIT_DB_PREFIX;
	$watch = (int)$gBitDb->getOne(
		"SELECT COUNT(*) FROM `{$X}liberty_xref` x
			JOIN `{$X}liberty_content` lc ON ( lc.`content_id` = x.`content_id` )
			WHERE lc.`content_type_guid` = 'healthday' AND x.`item` = ? AND x.`data` LIKE '%\"source\":\"watch\"%'",
		[ $pItem ]
	);
	$total = (int)$gBitDb->getOne(
		"SELECT COUNT(*) FROM `{$X}liberty_xref` x
			JOIN `{$X}liberty_content` lc ON ( lc.`content_id` = x.`content_id` )
			WHERE lc.`content_type_guid` = 'healthday' AND x.`item` = ?",
		[ $pItem ]
	);
	return [ 'cuff' => $total - $watch, 'watch' => $watch ];
}
