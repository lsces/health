<?php
/**
 * Single-pass upload + import of a raw Samsung Health export (.tar.gz, the
 * file Samsung's own app produces - same combined-export shape every manual
 * `split_health.sh`+`split_by_year.py`+`stage_history_year.sh` pass has been
 * built around this session). Mirrors ImportHealthForYou.php's own shape -
 * one upload button, dispatches straight to the existing per-type
 * Import*.php functions - but Samsung's export is a whole directory tree
 * (flat CSVs + jsons/<type>/<bucket>/<file> blobs), not one file, so this
 * extracts the archive first via liberty_process_archive() (the same shared
 * mechanism fisheye's own zip/tar upload has used for years - see
 * liberty/includes/liberty_lib.php).
 *
 * **Purely additive, by design** - every operation here is "append what's
 * genuinely new, skip what's already there", never "rebuild from scratch"
 * (unlike split_by_year.py, which always rebuilds its whole output tree).
 * This is what lets the same code correctly handle both a brand-new
 * install's first-ever full historical upload (everything is new, so
 * everything gets processed - a full reload arrived at through the same
 * path rather than a separate one) and a routine small monthly incremental
 * (almost everything already present, only a thin new slice actually
 * touches the database) - see health/CLAUDE.md's 2026-08-27 entry for the
 * design discussion. The existing manual scripts are left untouched
 * alongside this as a documented fallback, not replaced.
 *
 * Three additive layers, per CSV type present in the upload:
 *   1. CSV rows - appended onto storage/health/history/<year>/<type>.csv
 *      (bucketed by each row's own start_time/create_time year, same
 *      PHONE_ACQUIRED-cutoff placeholder-row drop split_by_year.py already
 *      does), exact-line dedup same as healthArchiveHealthForYouRows().
 *   2. JSON blobs - copied into storage/health/history/<year>/jsons/<type>/
 *      <bucket>/, skipped if already present (cheap file_exists()).
 *   3. GPS traces specifically (exercise/*.location_data*.json) - same
 *      additive copy, but ALSO into /home/media1/Maps/_tracking/<year>/ (the
 *      mapper package's own storage convention - see bitweaver/mapper.md's
 *      2026-08-26 entry for why that path/name), for mapper's own future use.
 *
 * The genuinely-new rows for each type are collected into a small delta CSV
 * (tempnam(), same header) and fed straight to that type's existing
 * Import*.php function, in-process - the delta CSV *is* the tight scoping
 * mechanism (only ever contains what's actually new), so no separate
 * date-range filter is needed on top: a small incremental upload naturally
 * produces a small delta, a first-ever full upload naturally produces a
 * full one, same code path either way.
 *
 * @package health
 */

require_once __DIR__.'/ImportBPSamsung.php';
require_once __DIR__.'/ImportSleep.php';
require_once __DIR__.'/ImportOxiSamsung.php';
require_once __DIR__.'/ImportEnergy.php';
require_once __DIR__.'/ImportRespiratoryRate.php';
require_once __DIR__.'/ImportSkinTemperature.php';
require_once __DIR__.'/ImportStepTrack.php';
require_once __DIR__.'/ImportSteps.php';
require_once __DIR__.'/ImportHRV.php';
require_once __DIR__.'/ImportHRRaw.php';
require_once __DIR__.'/ImportRaisedHR.php';
require_once __DIR__.'/RebuildHRDerived.php';

use Bitweaver\KernelTools;

const HEALTH_SAMSUNG_EARLIEST = '2024-06-29'; // confirmed phone-acquisition date - see split_by_year.py's own PHONE_ACQUIRED

/** type name (date-suffix stripped) => [importer function, needs a jsons dir]. Only CSV-plus-optional-jsons single-file importers go here - tracker.heart_rate/exercise (HR_RAW + RAISEDHR) are handled separately, both need two files together. */
const HEALTH_SAMSUNG_TYPE_IMPORTERS = [
	'com.samsung.shealth.blood_pressure'          => [ 'healthImportBPSamsung', false ],
	'com.samsung.shealth.sleep'                   => [ 'healthImportSleep', false ],
	'com.samsung.shealth.tracker.oxygen_saturation' => [ 'healthImportOxiSamsung', false ],
	'com.samsung.shealth.vitality_score'          => [ 'healthImportEnergy', false ],
	'com.samsung.health.respiratory_rate'         => [ 'healthImportRespiratoryRate', true ],
	'com.samsung.health.skin_temperature'         => [ 'healthImportSkinTemperature', true ],
	'com.samsung.shealth.step_daily_trend'        => [ 'healthImportStepTrack', true ],
	'com.samsung.shealth.activity.day_summary'    => [ 'healthImportSteps', false ],
	'com.samsung.health.hrv'                      => [ 'healthImportHRV', true ],
];

/** Pure app/device config and notification noise - same list split_health.sh already drops, kept in sync deliberately (see that script's own MISC_TYPES). */
const HEALTH_SAMSUNG_MISC_TYPES = [
	'com.samsung.health.device_profile', 'com.samsung.health.user_profile',
	'com.samsung.shealth.badge', 'com.samsung.shealth.insight_message',
	'com.samsung.shealth.insight.message_notification', 'com.samsung.shealth.preferences',
	'com.samsung.shealth.service_preferences', 'com.samsung.shealth.shm_device',
	'com.samsung.shealth.social.service_status', 'com.samsung.shealth.report',
];

/** Food-package types - not this importer's concern, left untouched (food has its own importers). */
const HEALTH_SAMSUNG_FOOD_TYPES = [
	'com.samsung.health.food_info', 'com.samsung.health.food_intake', 'com.samsung.health.nutrition',
	'com.samsung.health.water_intake', 'com.samsung.shealth.food_favorite',
	'com.samsung.shealth.food_frequent', 'com.samsung.shealth.food_goal',
];

/** Real per-record data types this importer never dispatches to a DB importer (no design/scope for them yet), but whose raw CSV+blobs are still worth archiving into history/<year>/ against a future need - anything not explicitly misc/food. Left implicit: any type not in HEALTH_SAMSUNG_TYPE_IMPORTERS or the two heart_rate/exercise sources still gets archived, just not imported. */

/** GPS-trace blob filename fragments, exercise type only - see this file's own docblock. */
const HEALTH_SAMSUNG_GPS_MARKERS = [ 'location_data.json', 'location_data_internal.json' ];

const HEALTH_SAMSUNG_TRACKING_PATH = '/home/media1/Maps/_tracking/';

/**
 * Strip a `.<digits>.csv` export-date suffix off a Samsung CSV filename,
 * same convention split_by_year.py's type_name_from_csv() uses.
 */
function samsungTypeName( string $pFilename ): string {
	if( preg_match( '/^(.*)\.\d+\.csv$/', $pFilename, $m ) ) {
		return $m[1];
	}
	return preg_replace( '/\.csv$/', '', $pFilename );
}

/**
 * Find a field in an already-parsed healthParseSamsungCsv() row by suffix
 * match (the row's keys are full dotted column names, e.g.
 * `com.samsung.health.blood_pressure.start_time` - some Samsung CSVs also
 * carry a handful of bare, unprefixed columns like `datauuid` alongside the
 * prefixed ones, so check both forms) - same matching rule as
 * split_by_year.py's own find_col().
 */
function samsungFindField( array $pRow, string $pSuffix ): ?string {
	if( isset( $pRow[$pSuffix] ) && $pRow[$pSuffix] !== '' ) {
		return $pRow[$pSuffix];
	}
	foreach( $pRow as $k => $v ) {
		if( str_ends_with( $k, '.'.$pSuffix ) && $v !== '' ) {
			return $v;
		}
	}
	return null;
}

/**
 * Append genuinely-new rows onto storage/health/history/<year>/<type>.csv,
 * exact-line dedup - same idea as healthArchiveHealthForYouRows(). Returns
 * the rows actually appended (caller uses these to build the delta CSV fed
 * to the real DB importer).
 *
 * @param  string   $pType
 * @param  string   $pYear
 * @param  string[] $pHeader
 * @param  array<int,string[]> $pRows  raw CSV rows (not yet dedup-checked)
 * @return array<int,string[]>  the subset that were actually new
 */
function samsungArchiveCsvRows( string $pType, string $pYear, array $pHeader, array $pRows ): array {
	$dir = HEALTH_IMPORT_PATH.'history/'.$pYear.'/';
	if( !is_dir( $dir ) ) {
		mkdir( $dir, 0777, true );
	}
	$file = $dir.$pType.'.csv';

	$existingLines = file_exists( $file ) ? array_flip( file( $file, FILE_IGNORE_NEW_LINES ) ) : [];
	$isNewFile = !file_exists( $file );

	$fh = fopen( $file, 'a' );
	if( $isNewFile ) {
		fputcsv( $fh, $pHeader, ',', '"', '' );
	}

	$newRows = [];
	foreach( $pRows as $row ) {
		$line = implode( ',', array_map( fn( $v ) => str_contains( (string)$v, ',' ) ? '"'.str_replace( '"', '""', $v ).'"' : $v, $row ) );
		if( isset( $existingLines[$line] ) ) {
			continue;
		}
		fputcsv( $fh, $row, ',', '"', '' );
		$newRows[] = $row;
	}
	fclose( $fh );

	return $newRows;
}

/**
 * Copy one blob into storage/health/history/<year>/jsons/<type>/<bucket>/ if
 * not already present there, and - for the exercise type's GPS-trace blobs
 * specifically - also into HEALTH_SAMSUNG_TRACKING_PATH/<year>/ for the
 * mapper package's own future use. Both are plain additive copies, never
 * overwritten if already present (Samsung's own export is byte-identical
 * for a blob that's appeared in a previous export - nothing to reconcile).
 */
function samsungArchiveBlob( string $pType, string $pYear, string $pSrcPath, string $pFilename ): void {
	if( !is_readable( $pSrcPath ) ) {
		return;
	}
	$bucket = $pFilename[0];

	$dest = HEALTH_IMPORT_PATH."history/$pYear/jsons/$pType/$bucket/";
	if( !is_dir( $dest ) ) {
		mkdir( $dest, 0777, true );
	}
	if( !file_exists( $dest.$pFilename ) ) {
		copy( $pSrcPath, $dest.$pFilename );
	}

	if( $pType === 'com.samsung.shealth.exercise' ) {
		foreach( HEALTH_SAMSUNG_GPS_MARKERS as $marker ) {
			if( str_ends_with( $pFilename, $marker ) ) {
				$trackDest = HEALTH_SAMSUNG_TRACKING_PATH."$pYear/$bucket/";
				if( is_dir( HEALTH_SAMSUNG_TRACKING_PATH ) || @mkdir( HEALTH_SAMSUNG_TRACKING_PATH, 0777, true ) ) {
					if( !is_dir( $trackDest ) ) {
						@mkdir( $trackDest, 0777, true );
					}
					if( is_dir( $trackDest ) && !file_exists( $trackDest.$pFilename ) ) {
						@copy( $pSrcPath, $trackDest.$pFilename );
					}
				}
				break;
			}
		}
	}
}

/**
 * Write a small delta CSV (same header + only the given rows) to a temp
 * file, for feeding straight into an existing Import*.php function.
 *
 * @param  string[] $pHeader
 * @param  array<int,string[]> $pRows
 * @return string  temp file path - caller unlinks it
 */
function samsungWriteDeltaCsv( string $pType, array $pHeader, array $pRows ): string {
	$tmp = tempnam( sys_get_temp_dir(), 'sshealth_' );
	$fh  = fopen( $tmp, 'w' );
	fputs( $fh, "$pType,0,0\n" ); // 2-line preamble - healthParseSamsungCsv() only reads/discards this line, content doesn't matter
	fputcsv( $fh, $pHeader, ',', '"', '' );
	foreach( $pRows as $row ) {
		fputcsv( $fh, $row, ',', '"', '' );
	}
	fclose( $fh );
	return $tmp;
}

/**
 * Run the full single-pass Samsung import: extract the uploaded .tar.gz,
 * archive every type's genuinely-new rows/blobs into storage/health/
 * history/<year>/ (+ GPS traces into _tracking/<year>/), then dispatch each
 * type's delta straight into its existing DB importer.
 *
 * @param  array $pFileHash  a $_FILES[...] entry - tmp_name/name/type/size
 * @return array{types:array<string,array>,years:string[],errors:string[]}
 */
function healthImportSamsung( array $pFileHash ): array {
	$result = [ 'types' => [], 'years' => [], 'errors' => [] ];

	if( empty( $pFileHash['tmp_name'] ) || !is_uploaded_file( $pFileHash['tmp_name'] ) ) {
		$result['errors'][] = 'No file uploaded';
		return $result;
	}

	$destDir = \Bitweaver\Liberty\liberty_process_archive( $pFileHash );
	if( !$destDir || !is_dir( $destDir ) ) {
		$result['errors'][] = "Couldn't extract archive '{$pFileHash['name']}'";
		return $result;
	}

	// Samsung's own export nests everything one level down, under the
	// archive's own folder name (samsunghealth_lester_<date>/) - find it
	// rather than assume the exact name.
	$root = $destDir;
	$entries = array_values( array_diff( scandir( $destDir ) ?: [], [ '.', '..' ] ) );
	if( count( $entries ) === 1 && is_dir( $destDir.'/'.$entries[0] ) ) {
		$root = $destDir.'/'.$entries[0];
	}

	$csvFiles = glob( $root.'/*.csv' ) ?: [];
	$yearsTouched = [];
	$hrDateMin = null;
	$hrDateMax = null; // tracks tracker.heart_rate/exercise new-row dates only - the actual rebuild scope

	// Pass 1: archive every type's CSV rows + referenced blobs, per year.
	// Collect each type's new rows per year for the dispatch pass below.
	$deltasByTypeYear = []; // type => year => ['header'=>[], 'rows'=>[]]

	foreach( $csvFiles as $csvFile ) {
		$type = samsungTypeName( basename( $csvFile ) );
		if( in_array( $type, HEALTH_SAMSUNG_MISC_TYPES, true ) || in_array( $type, HEALTH_SAMSUNG_FOOD_TYPES, true ) ) {
			continue;
		}

		$fh = fopen( $csvFile, 'r' );
		fgets( $fh ); // preamble
		$header = fgetcsv( $fh, 0, ',', '"', '' );
		$headerCount = count( $header );

		$rowsByYear = []; // year => rows[]
		while( ( $row = fgetcsv( $fh, 0, ',', '"', '' ) ) !== false ) {
			if( count( $row ) > $headerCount ) {
				$row = array_slice( $row, 0, $headerCount );
			} elseif( count( $row ) < $headerCount ) {
				continue;
			}
			$assoc = array_combine( $header, $row );
			$dateStr = samsungFindField( $assoc, 'start_time' ) ?? samsungFindField( $assoc, 'create_time' );
			$date = $dateStr ? substr( $dateStr, 0, 10 ) : null;
			if( $date !== null && $date < HEALTH_SAMSUNG_EARLIEST ) {
				continue; // device-setup placeholder noise, same drop as split_by_year.py
			}
			$year = $date ? substr( $date, 0, 4 ) : 'unknown';
			$rowsByYear[$year][] = $row;
		}
		fclose( $fh );

		$jsonDir = $root.'/jsons/'.$type.'/';
		$hasJsons = is_dir( $jsonDir );
		$fieldIdx = null;
		$startIdx = null;
		foreach( $header as $i => $col ) {
			if( str_ends_with( $col, '.binning_data' ) || str_ends_with( $col, '.live_data' ) ) {
				$fieldIdx = $i;
			}
			if( str_ends_with( $col, '.start_time' ) || $col === 'start_time' ) {
				$startIdx = $i;
			}
		}
		$isHrType = $type === 'com.samsung.shealth.tracker.heart_rate' || $type === 'com.samsung.shealth.exercise';

		foreach( $rowsByYear as $year => $rows ) {
			$newRows = samsungArchiveCsvRows( $type, $year, $header, $rows );

			if( $hasJsons && $fieldIdx !== null ) {
				foreach( $rows as $row ) {
					$filename = trim( $row[$fieldIdx] ?? '' );
					if( $filename !== '' ) {
						samsungArchiveBlob( $type, $year, $jsonDir.$filename[0].'/'.$filename, $filename );
					}
				}
			}

			if( $newRows ) {
				$deltasByTypeYear[$type][$year] = [ 'header' => $header, 'rows' => $newRows ];
				$yearsTouched[$year] = true;

				if( $isHrType && $startIdx !== null ) {
					foreach( $newRows as $row ) {
						$d = substr( (string)( $row[$startIdx] ?? '' ), 0, 10 );
						if( $d === '' ) continue;
						if( $hrDateMin === null || $d < $hrDateMin ) $hrDateMin = $d;
						if( $hrDateMax === null || $d > $hrDateMax ) $hrDateMax = $d;
					}
				}
			}
		}
	}

	// Pass 2: dispatch each type's per-year delta to its real DB importer.
	foreach( $deltasByTypeYear as $type => $byYear ) {
		if( $type === 'com.samsung.shealth.tracker.heart_rate' || $type === 'com.samsung.shealth.exercise' ) {
			continue; // handled together, below
		}
		if( !isset( HEALTH_SAMSUNG_TYPE_IMPORTERS[$type] ) ) {
			continue; // archived above, but no DB importer designed for this type yet
		}
		[ $fn, $needsJsons ] = HEALTH_SAMSUNG_TYPE_IMPORTERS[$type];

		foreach( $byYear as $year => $delta ) {
			$tmp = samsungWriteDeltaCsv( $type, $delta['header'], $delta['rows'] );
			$r = $needsJsons
				? $fn( $tmp, HEALTH_IMPORT_PATH."history/$year/jsons/$type/" )
				: $fn( $tmp );
			unlink( $tmp );
			$result['types']["$type ($year)"] = $r;
		}
	}

	// HR_RAW + RAISEDHR: need both sources together, per year.
	foreach( $yearsTouched as $year => $unused ) {
		$hrDelta = $deltasByTypeYear['com.samsung.shealth.tracker.heart_rate'][$year] ?? null;
		$exDelta = $deltasByTypeYear['com.samsung.shealth.exercise'][$year] ?? null;
		if( !$hrDelta && !$exDelta ) {
			continue;
		}
		$hrTmp = $hrDelta ? samsungWriteDeltaCsv( 'com.samsung.shealth.tracker.heart_rate', $hrDelta['header'], $hrDelta['rows'] ) : null;
		$exTmp = $exDelta ? samsungWriteDeltaCsv( 'com.samsung.shealth.exercise', $exDelta['header'], $exDelta['rows'] ) : null;

		$hrJsonDir = HEALTH_IMPORT_PATH."history/$year/jsons/com.samsung.shealth.tracker.heart_rate/";
		$exJsonDir = HEALTH_IMPORT_PATH."history/$year/jsons/com.samsung.shealth.exercise/";

		$result['types']["HEALTH_HR_RAW ($year)"] = healthImportHRRaw( $hrTmp ?? '', $hrJsonDir, $exTmp ?? '', $exJsonDir );

		if( $hrTmp ) unlink( $hrTmp );
		if( $exTmp ) unlink( $exTmp );
	}

	if( $hrDateMin !== null && $hrDateMax !== null ) {
		$result['types']['PULSE + RAISEDHR rebuild'] = healthRebuildDateRange( $hrDateMin, $hrDateMax );
	}

	$result['years'] = array_keys( $yearsTouched );

	KernelTools::unlink_r( $destDir );

	return $result;
}
