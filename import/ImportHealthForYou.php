<?php
/**
 * Single-pass import of a raw HealthForYouApp_DataExport CSV — splits the
 * combined export into its per-section blocks in memory (same section-scan
 * logic as split_healthforyou.py) and feeds each straight into the existing
 * per-section importers (ImportWT.php/ImportBP.php/ImportOxi.php/ImportTemp.php),
 * without a human-run split step. Lets the "Upload HealthForYou export" admin
 * page work from any of the three machines (srv9/srv10/desktop), unlike
 * split_healthforyou.py which only runs on desktop.
 *
 * Also appends each section's rows onto the durable per-year archive CSV
 * under HEALTH_IMPORT_PATH.'history/<year>/healthforyou_<section>.csv' — the
 * same file shape/location split_healthforyou_by_year.py's output gets
 * manually copied to on each live site — creating a new year's file the
 * first time a row lands in it. This is a separate concern from the DB
 * import's own (content_id, item, start_date) dedup: it keeps the raw
 * historical CSV trail complete on whichever machine the upload happened on,
 * so a machine that never sees the desktop-side Python split still ends up
 * with the same archive.
 *
 * Samsung Health's own combined export is NOT handled here — its section
 * shapes/CSV format differ enough (and dedup against Samsung's cuff-BP history
 * is still an open design question, see ImportBP.php's docblock) that it needs
 * its own single-pass importer later, not bolted onto this one.
 *
 * @package health
 */

require_once __DIR__.'/ImportWT.php';
require_once __DIR__.'/ImportBP.php';
require_once __DIR__.'/ImportOxi.php';
require_once __DIR__.'/ImportTemp.php';

/** Section title (as it appears in the raw export) => importer function name. Mirrors split_healthforyou.py's SECTION_FILES keys exactly. */
const HEALTH_HFY_SECTION_IMPORTERS = [
	'WEIGHT'         => 'healthImportWT',
	'BLOOD PRESSURE' => 'healthImportBP',
	'PULSE OXIMETER' => 'healthImportOxi',
	'TEMPERATURE'    => 'healthImportTemp',
];

/** Section title => short key used in the history archive's healthforyou_<key>.csv filename. */
const HEALTH_HFY_SECTION_KEYS = [
	'WEIGHT'         => 'weight',
	'BLOOD PRESSURE' => 'blood_pressure',
	'PULSE OXIMETER' => 'pulse_oximeter',
	'TEMPERATURE'    => 'temperature',
];

/**
 * Scan a raw HealthForYouApp_DataExport CSV and split it into its recognised
 * section blocks (same section-block shape split_healthforyou.py parses: an
 * all-caps title line, a semicolon header line, data rows, then a blank
 * line).
 *
 * @param  string $pRawFile
 * @return array<string,array{header:string[],rows:array<int,string[]>}>  section title => header + raw row arrays.
 */
function healthSplitHealthForYouExport( string $pRawFile ): array {
	$lines = file( $pRawFile, FILE_IGNORE_NEW_LINES );
	if( $lines === false ) {
		return [];
	}
	// Strip a UTF-8 BOM off the very first line, if present.
	if( isset( $lines[0] ) ) {
		$lines[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $lines[0] );
	}

	$n = count( $lines );
	$sections = [];
	$i = 0;
	while( $i < $n ) {
		$title = trim( $lines[$i] );
		if( isset( HEALTH_HFY_SECTION_IMPORTERS[$title] ) && $i + 1 < $n && str_contains( $lines[$i + 1], ';' ) ) {
			$header = explode( ';', $lines[$i + 1] );
			$rows = [];
			$j = $i + 2;
			while( $j < $n && trim( $lines[$j] ) !== '' ) {
				$rows[] = explode( ';', $lines[$j] );
				$j++;
			}
			if( $rows ) {
				$sections[$title] = [ 'header' => $header, 'rows' => $rows ];
			}
			$i = $j;
		} else {
			$i++;
		}
	}
	return $sections;
}

/**
 * Append a section's raw rows onto its durable per-year archive CSV
 * (HEALTH_IMPORT_PATH.'history/<year>/healthforyou_<section>.csv'), grouping
 * rows by year (column 0 is always the dd/mm/yyyy Date column) — creates the
 * year's directory/file (with header) the first time it's touched. Skips any
 * row already present verbatim, so re-uploading an export whose window
 * overlaps an earlier upload is safe.
 *
 * @param  string          $pSectionKey  one of weight|blood_pressure|pulse_oximeter|temperature
 * @param  string[]        $pHeader
 * @param  array<int,string[]> $pRows
 */
function healthArchiveHealthForYouRows( string $pSectionKey, array $pHeader, array $pRows ): void {
	$byYear = [];
	foreach( $pRows as $row ) {
		$parts = explode( '/', $row[0] ?? '' );
		$year  = count( $parts ) === 3 ? $parts[2] : 'unknown';
		$byYear[$year][] = $row;
	}

	foreach( $byYear as $year => $rows ) {
		$dir = HEALTH_IMPORT_PATH.'history/'.$year.'/';
		if( !is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		$file = $dir.'healthforyou_'.$pSectionKey.'.csv';

		$existingLines = file_exists( $file ) ? array_flip( file( $file, FILE_IGNORE_NEW_LINES ) ) : [];
		if( !file_exists( $file ) ) {
			$fh = fopen( $file, 'w' );
			fputcsv( $fh, $pHeader, ';', '"', '' );
			fclose( $fh );
		}

		$fh = fopen( $file, 'a' );
		foreach( $rows as $row ) {
			if( isset( $existingLines[implode( ';', $row )] ) ) {
				continue;
			}
			fputcsv( $fh, $row, ';', '"', '' );
		}
		fclose( $fh );
	}
}

/**
 * Run the single-pass HealthForYou import: split the raw export into its
 * section blocks, archive each section's rows into the year-history CSVs,
 * then run each section through its existing per-row importer (via a temp
 * CSV, same shape the importer already expects), aggregating results. Temp
 * files are removed afterward regardless of outcome.
 *
 * @param  string $pRawFile
 * @return array{sections:array<string,array{created:int,skipped:int,errors:string[]}>,errors:string[]}
 */
function healthImportHealthForYou( string $pRawFile ): array {
	$result = [ 'sections' => [], 'errors' => [] ];

	if( !is_readable( $pRawFile ) ) {
		$result['errors'][] = "Can't read $pRawFile";
		return $result;
	}

	$sections = healthSplitHealthForYouExport( $pRawFile );
	if( !$sections ) {
		$result['errors'][] = 'No recognised sections found in the uploaded export.';
		return $result;
	}

	foreach( $sections as $title => $section ) {
		healthArchiveHealthForYouRows( HEALTH_HFY_SECTION_KEYS[$title], $section['header'], $section['rows'] );

		$tmp = tempnam( sys_get_temp_dir(), 'hfy_' );
		$fh  = fopen( $tmp, 'w' );
		fputcsv( $fh, $section['header'], ';', '"', '' );
		foreach( $section['rows'] as $row ) {
			fputcsv( $fh, $row, ';', '"', '' );
		}
		fclose( $fh );

		$fn = HEALTH_HFY_SECTION_IMPORTERS[$title];
		$result['sections'][$title] = $fn( $tmp );
		unlink( $tmp );
	}

	return $result;
}
