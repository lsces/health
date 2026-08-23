# Processing a Samsung Health / HealthForYou export

Reference for turning a raw Samsung Health or HealthForYou export into the files the `health`/
`food` package importers (`ImportBP.php`, `ImportWT.php`, `ImportFoodInfo.php`, etc.) actually
read. Neither source has an API or an incremental export — every download is a **full** dump of
your entire history.

## Samsung Health

### 1. Export from the Samsung Health app

Produces one dated folder, `samsunghealth_<user>_<YYYYMMDDHHMMSS>/` — a flat set of
`com.samsung.health.*`/`com.samsung.shealth.*` CSVs (each with a 2-line preamble before the real
header row) plus `files/`/`jsons/` subfolders keyed by type name.

### 2. `split_health.sh` — separate food from health, drop app/device noise

```
./split_health.sh
```

For every `samsunghealth_<user>_<date>/` folder present, produces a sibling pair:
- `food_<user>_<date>/` — `food_info`, `food_intake`, `nutrition`, `water_intake`,
  `food_favorite`, `food_frequent`, `food_goal`
- `health_<user>_<date>/` — everything else measurement/goal/activity-related, plus the matching
  `files/`/`jsons/` blobs

A fixed list of pure app/device config and notification types (`device_profile`, `user_profile`,
`badge`, `insight_message`, `preferences`, etc.) is deliberately dropped from both — not real data
either package needs. Idempotent — safe to re-run any time, including against old export folders
already processed; it only ever (re-)creates that date's pair, never touches the raw
`samsunghealth_*` archive.

**Edit `BASE` at the top of the script first** — it's a hardcoded path to wherever you keep your
exports, not autodetected.

### 3. `split_by_year.py` — re-split by event year (optional, recommended for multi-year history)

```
./split_by_year.py           # every food_*/health_* folder present
./split_by_year.py <date>    # just the one matching that date suffix
```

Every full Samsung Health download re-contains your *entire* history, so without this step every
future export keeps growing by however much new data you've added since the last one, forever.
This step splits each `food_<user>_<date>/`/`health_<user>_<date>/` folder by the actual event
date (`start_time`, not `create_time` — the latter is just when the record was saved to the app,
which can lag the real event for backfilled entries) into a sibling `..._by_year/<year>/` tree —
CSVs split by row, `files`/`jsons` blobs re-bucketed by year using each type's own
datauuid→year mapping. Read-only on its inputs; only ever writes the new `_by_year` folder, which
is fully rebuilt (not merged) on every run.

Once a year is confirmed unchanged across two consecutive exports (nothing was backfilled into it
since), it's genuinely closed — safe to archive once and stop re-processing/re-storing it out of
every future full download.

**Edit `BASE` at the top of the script first**, same as above.

### 4. Get the CSVs where the importers expect them

The PHP importers (`load_bp_samsung.php`, `load_food_info.php`, etc.) read directly from
`HEALTH_IMPORT_PATH`/`FOOD_IMPORT_PATH` (both resolve to `storage/health/` and `storage/food/`
under the site root — see `includes/bit_setup_inc.php` in each package), picking up the
newest-dated CSV of each type present. Copy (or symlink) the CSVs you want imported — from either
the plain `food_<user>_<date>/`/`health_<user>_<date>/` split, or a specific year's slice out of
`..._by_year/<year>/` if you're working from an older export — into `storage/food/` /
`storage/health/` directly. The importers only look at loose files in that directory root, so
don't nest a year subfolder there for data you actually want picked up next run.

`files`/`jsons` blobs are never read by any importer — GPS tracks, ECG waveform PDFs, and other
raw per-record device telemetry aren't part of what gets imported. No need to copy them anywhere
near `storage/`.

## HealthForYou

Samsung's separate BP-cuff-companion app (weight/BP/pulse-oximeter/temperature readings), exported
independently of Samsung Health proper — some of the same physical readings end up in both (see
`ImportBPSamsung.php`'s docblock for how the BP importer dedupes across sources), but HealthForYou
doesn't retain everything Samsung Health ends up syncing, so both are worth importing.

### 1. Export from the HealthForYou app

Drop the downloaded `HealthForYouApp_DataExport (N).csv` into
`~/Personal/Health/HealthForYouApp/` (or wherever `BASE` in the two scripts below points). Unlike
Samsung Health, you choose the date range when exporting — the app has your full history, the
range is just an export-time choice, not a retention limit. One CSV, semicolon-delimited, a
preamble followed by one block per section (Weight/Blood pressure/Pulse Oximeter/Temperature),
newest rows first.

### 2. `split_healthforyou.py` — one CSV per section

```
./split_healthforyou.py
```

Produces `healthforyou_<user>_<YYYYMMDD>/` (named after the export's own "To" date) with 4 files:
`weight.csv`, `blood_pressure.csv`, `pulse_oximeter.csv`, `temperature.csv`. A new section title
showing up in a future export needs adding to `SECTION_FILES` in the script — unrecognised
sections are skipped with a warning rather than silently dropped.

### 3. `split_healthforyou_by_year.py` — re-split by year (optional)

```
./split_healthforyou_by_year.py
```

Same idea as Samsung's `split_by_year.py`, much simpler — no per-record blobs to worry about,
just the 4 section CSVs split by their `Date` column (always column 0, `DD/MM/YYYY`) into a
sibling `..._by_year/<year>/` tree.

### 4. Get the CSVs where the importers expect them

Same `HEALTH_IMPORT_PATH` (`storage/health/`) as Samsung, but bare filenames — `weight.csv`,
`blood_pressure.csv`, `pulse_oximeter.csv`, `temperature.csv` — with no Samsung-style prefix, so
both sources' current-year files coexist in `storage/health/` without collision.
