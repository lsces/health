# Health Package — Reference Manual

How the package actually works today. For the history of *why* — decisions, bugs found, wrong
turns, live-test numbers — see `CLAUDE.md`'s dated session log instead; this file only tracks
current behaviour. Companion package: `food/CLAUDE.md`/`food/MANUAL.md` — same Samsung Health
export, same liberty_xref-based approach, read there for the pattern this package followed.

## Data sources

Two independent import pipelines, covering overlapping but not identical data:

- **HealthForYou** — Samsung's older companion-app export (`healthforyou_name_<date>/`, 4 CSVs:
  weight, blood pressure, pulse-oximeter, temperature). Cuff/probe-sourced readings only. No UTC
  offset in its timestamps at all — resolved against `Europe/London` directly.
- **Samsung Health** — the phone/watch app's own export (`samsunghealth_name_<date>/`, one CSV
  per data type + a `jsons/` tier of per-record detail blobs + a `files/` tier of file
  attachments). Far broader: activity, sleep, HRV, raw heart-rate traces, exercise sessions, ECG
  PDFs. `start_time`/`create_time` columns are already UTC-equivalent — every importer parses
  them directly as UTC rather than against a named zone, to avoid double-subtracting the BST hour.
  Session-shaped data (sleep, exercise) resolves *both* start and end against the user's own IANA
  timezone directly — a session's own single `time_offset` column can't correctly cover a session
  spanning a summer/winter time transition.

Split into per-package copies (`~/Personal/Health/Samsung Health/health_name_<date>/`) via
`split_health.sh` before use — pure device/app config gets dropped at this step, not real data.

`storage/health/` (the package's own import staging area) has two roles: the flat top level holds
whatever's the newest manually-staged export for the standalone `load_*.php` importers; `history/
<year>/<type>.csv` (+ matching `jsons/<type>/`) is the year-bucketed **archive** the single-pass
uploaders (below) build and read from — additive, never rebuilt from scratch, since Samsung's own
export is always a full history dump, not incremental.

## Content model

**`HealthDay`** (`includes/classes/HealthDay.php`, `content_type_guid='healthday'`) is the base
object — a pure `liberty_content` record, content_id only, no companion table. Title is always the
ISO date (`YYYY-MM-DD`). `HealthDay::findOrCreate($date)` is the normal entry point for an
importer (a date is all it ever knows ahead of time); `lookupByDate()`/`lookup()` (by content_id)
also exist. Every real reading/session attaches to its day's `content_id` via `liberty_xref` —
there is no separate metric/session content type, everything hangs off the day.

`event_time` (plain BIGINT unix timestamp on `liberty_content`, not a real TIMESTAMP column) must
be set on every `HealthDay` row — Calendar's own listing query sorts/filters by it, so a row
without one is invisible to any date-ranged view, calendar or otherwise. `findOrCreate()` sets it
to midday UTC of that date.

## Item roster

Every item below is `multiple=-1` (read-only — device-reported data, never hand-edited) except
where noted. "Source" is which import pipeline populates it.

| Item | Source | x_group | Template | Shape |
|---|---|---|---|---|
| `WT` | HealthForYou | `wt` | `key-json-text` | xkey=weight(kg), xkey_ext=BMI, data=body comp json |
| `BP` | HealthForYou (cuff) + Samsung (cuff+watch-PPG) | `bp` | `key-json-text` | xkey=systolic, xkey_ext=diastolic, data=json `{pulse,map,source,comment,calibration_id}` — `source` is `cuff`/`watch` |
| `OXI` | HealthForYou | `oxi` | `key-json-text` | xkey=SpO2 avg, xkey_ext=Pulse, data=json `{spo2_min,spo2_max}` |
| `TEMP` | HealthForYou | `general` | `value` | xkey=temperature(°C), xkey_ext=Mode, no data |
| `PULSE` | Samsung (`tracker.heart_rate`) | `pulse` | `key-json-detail` | one row per half-hour clock slot (Europe/London-aligned); xkey=slot average, xkey_ext=low/high json, data=that slot's own minute bins |
| `RESP` | Samsung | `resp` | `key-json-detail` | same half-hour-slot shape as PULSE |
| `STEMP` | Samsung | `stemp` | `key-json-detail` | same half-hour-slot shape as PULSE |
| `HRV` | Samsung | `hrv` | `key-json-detail` | same half-hour-slot shape as PULSE (sdnn/rmssd) |
| `STEPS` | Samsung (`activity.day_summary`) | `general` | `key-json-text` | xkey=steps, xkey_ext=active mins, data=json `{active_kcal}` |
| `ENERGY` | Samsung (`vitality_score`) | `general` | `key-json-text` | xkey=total_score, xkey_ext=shrv_value (sleep HRV), data=json `{shrv_score,activity_score,sleep_score}` |
| `SLEEP` | Samsung | `general` | `key-json-text` | one row per sleep *session*, not per day; xkey=sleep_score, xkey_ext=duration(mins), data=json `{efficiency}` |
| `STEPTRACK` | Samsung (`step_daily_trend`) | `general` | `key-json-detail` | one row/day; xkey=total steps, xkey_ext=peak-10-min, data=day track json |
| `RAISEDHR` | derived, from `HEALTH_HR_RAW` (below) | `general` | `key-json-text` | one row/day; xkey/xkey_ext=mins≥90/100bpm, data=json split by exercise/background source + hr_min/hr_max |
| `EXERCISE` | Samsung (`exercise`) | `exercise` | `key-json-detail` | one row per session; xkey=`healthExerciseTypeLabel()` text (Walk/Physio/Untagged — resolved at *import* time, the generic view template has no per-item value-lookup hook); xkey_ext=clock-span duration in minutes (`end_time-start_time`, deliberately not Samsung's own smoothed duration — see below); data=json `{type_code,duration_min,source_type,calorie,distance,mean_heart_rate,max_heart_rate,min_heart_rate,count,title}` |
| `OXIDESAT` | derived, from `tracker.oxygen_saturation`'s own `binning` detail (same rows OXI reads) | `general` | `key-json-text` | one row per sleep *session* (own start_date, like SLEEP/EXERCISE); xkey/xkey_ext=mins spent below 90%/85% SpO2, data=json `{mins_80,low_value,low_time,sample_count,coverage_mins,session_mins,spo2_avg,spo2_min,spo2_max}` |

**`EXERCISE.xkey_ext` is deliberately the raw, sometimes-wrong clock-span, not Samsung's own
`duration` field.** Confirmed against real data: ~32% of sessions have a clock-span disagreeing
with Samsung's own duration by 10+ minutes (sessions left running after forgetting to stop them),
while Samsung's own value stays plausible right through those outliers. The raw value is stored
anyway, on purpose — exactly the kind of bad value the archive mechanism below should surface, not
something to silently correct at import time. Samsung's own `duration` is kept in the detail json
as a cross-check. `EXERCISE.xkey`'s type codes: only 5 ever appear in real data — `1001`=Walk
(includes Samsung's own auto-walk-detection, `source_type=4` in the detail json), `12001`=an
account-specific custom-exercise id (currently "Knee Physio" — will only keep meaning that as long
as no second custom exercise is ever defined), everything else merges to "Untagged".

**`HEALTH_HR_RAW`** is the one real table in this package (not a `liberty_xref` item) — unifies
both raw Samsung heart-rate sources (`tracker.heart_rate`'s background binning data, `exercise`'s
`live_data`) into one per-second row set, source-tagged. `RAISEDHR` is a derived daily rollup
computed from it, not imported directly.

## xref groups

One dedicated group per item that can genuinely carry more than one row on a given day (`WT`/`BP`/
`OXI` — multiple real readings; `PULSE`/`RESP`/`STEMP`/`HRV`/`EXERCISE` — always multiple slots/
sessions by design), plus one `general` group for the items that are always exactly one row per
day (`TEMP`/`STEPS`/`ENERGY`/`SLEEP`/`STEPTRACK`/`RAISEDHR`/`OXIDESAT`). Getting this wrong (registering a
multi-row-per-day item under `general`) is a real, easy-to-hit mistake — happened to `EXERCISE`
itself on first registration, only visible via `view_day.php` actually rendering wrong.

`liberty_xref_item.data`'s hint array means something different depending on `template`:
`key-json-text` items list the detail json's own field names (used to flatten the whole blob into
one titled line); `key-json-detail` items use only the first 3 entries, as labels for xkey/
xkey_ext/detail-summary respectively — registering all of a `key-json-detail` item's real field
names there (as if it were `key-json-text`) silently mislabels the display rather than erroring.

## Import architecture

Three ways data lands in the database, all ultimately calling the same per-type importer
functions (`healthImportWT()`, `healthImportExercise()`, etc.) so dedup logic only exists once
per type:

1. **Standalone `import/load_<type>.php` scripts** — the original mechanism, one per item, each a
   thin web page (`verifyPermission('p_health_admin')`, needs a real login) that finds the newest
   matching CSV in the flat `storage/health/` top level (`healthFindLatestSamsungCsv()`) and runs
   that one type's importer. Still the fallback/manual path — used directly when staging a file by
   hand (e.g. deploying to a fresh server before the upload flow has been proven there).
2. **`import/ImportHealthForYou.php` + `load_healthforyou.php`** — one upload button, splits the
   raw combined HealthForYou export in memory, dispatches each section to `WT`/`BP`/`OXI`/`TEMP`'s
   importers, appends new rows onto `storage/health/history/<year>/`.
3. **`import/ImportSamsung.php` + `load_samsung.php`** — one upload button for a raw Samsung
   `.tar.gz`. Extracts via `liberty_process_archive()` (the same shared mechanism fisheye's own
   archive uploads use), then per CSV type present: archives every genuinely-new row into
   `storage/health/history/<year>/<type>.csv` (+ referenced json blobs), collects just the new
   rows into an in-memory delta, and feeds that delta straight to the type's real DB importer.
   **Purely additive by design** — the delta *is* the scoping mechanism, so the same code path
   correctly handles both a brand-new install's full historical upload and a routine small
   incremental one, no separate date-range filter needed. Covers all Samsung-sourced items;
   `WT`/`TEMP` stay HealthForYou-only.

   **`tracker.heart_rate` and `exercise` are handled together, separately from the simple
   per-type dispatch table** (`HEALTH_SAMSUNG_TYPE_IMPORTERS`) — both `HEALTH_HR_RAW` and
   `EXERCISE`'s own session rows need the *same* exercise-CSV delta, so it's built once per year
   and fed to `healthImportHRRaw()` and `healthImportExercise()` in turn, rather than three
   independent delta-CSV builds for the same source rows. Any new importer that also needs
   `exercise`'s CSV should plug in at this same point, not register in the simple table.

   GPS traces from exercise sessions are also copied to `/home/media1/Maps/_tracking/<year>/` as
   part of this same pass, for `mapper`'s own future use (see `mapper.md`) — health's side of that
   is complete, display is mapper's job.

   **Fast bypass**: since Samsung's export is a full history dump every time, the per-year
   exact-line dedup (`samsungArchiveCsvRows()`, reads the whole existing `history/<year>/<type>.csv`
   into memory) gets more expensive every year that accumulates. `storage/health/
   samsung_last_import_date.txt` holds the newest row-date seen across every type in the last
   successful run; any row more than `HEALTH_SAMSUNG_BYPASS_BUFFER_DAYS` (2) days behind that date
   is treated as already-imported and dropped before the dedup even runs for that year — a whole
   year is skipped entirely once nothing in it survives the cutoff. No marker yet (first-ever run)
   means nothing is bypassed. Relies on Samsung data never being backfilled/edited once old enough
   to clear the buffer.

**Dedup**: session-shaped items (`SLEEP`/`EXERCISE`) key on exact `content_id`+`start_date`;
reading-shaped items key on `entry_date` (to-the-minute, since HealthForYou's own timestamps carry
no seconds); `HEALTH_HR_RAW` keys on its own `START_TIME` primary key (checked via a plain
indexed `SELECT` before insert, not exception-driven — catching a PK-violation exception on every
duplicate reset the whole transaction, which hung on a re-run against mostly-already-imported
data).

## xref history/archive — `list_item.php`

A raw-data browser: pick one item type via a row of radio buttons, see every row of that type
across every day, paginated. Deliberately shows `xkey`/`xkey_ext`/`data` exactly as stored — a
verification tool, not the polished day view (`view_day.php`).

**Intentionally not built on liberty's generic `list_xref.tpl`/`add_xref.php` framework** — that
framework's shape is "every item type for *one* content object, in tabs"; this page's shape is
"every day's rows for *one* item type at once" — the inverse axis, doesn't fit the generic
group-tab structure at all. It does reuse the generic **visual** conventions where they apply:
`liberty/templates/xref/action_icons.tpl`'s icon choices (`archive-insert` for archive, not
`user-trash` — that's reserved for hard delete) and confirm-dialog pattern for the per-row Archive
action.

`edit=y` (gated on `p_health_admin`) shows a per-row Archive action and an Edit-mode toggle on the
nav bar; `history=y` shows already-archived rows too (light-red background), rather than hiding
them by default. Archive calls `LibertyContent::stepXref(['xref_id'=>...,'expunge'=>1])` directly
— not routed through `liberty/edit_xref.php`, since that controller redirects to the content
item's own edit page on success, which would bounce the user off this cross-day browser. See
`liberty/MANUAL.md`'s "Expunge and history" section for the underlying mechanism — every health
item is `multiple=-1`, so this page relies on liberty's narrow read-only exemption for pure
archive/restore/step operations.

## Calendar integration

`HealthDay::getDayCellHtml()` implements the generic per-content-type calendar hook
(`LibertyContent::getContentList()` — see `liberty/MANUAL.md`) — a multi-line day-tile summary
(Weight/BP/Pulse range, always all three lines even on a day with no BP so the grid stays
aligned). Still a first-cut rollup (plain min/max/avg across the day's raw rows), not the
considered "pick the real headline reading" logic — see Known gaps.

## Known gaps

- **Day-summary rollup is still a placeholder** — `getDayCellHtml()`'s min/max/avg approach, not
  the considered "lowest-AM-weight-preferring-a-valid-scan" style logic originally intended.
- **ECG PDF exports (`files/com.samsung.health.ecg/*.data.pdf`) have no handling at all** — no
  xref item, no importer. Direction settled: a `health` gallery in `fisheye`, reusing its existing
  gallery tooling rather than bespoke file-handling here — not scoped, no validated real ECG
  reading yet to build/test against.
- **`admin_packages_inc.php`'s installer shortcut mis-handles this package's upgrades** — health
  owns no tables of its own, which trips a kernel-level "quiet auto-upgrade" bug that silently
  bumps the tracked version without actually applying the upgrade's SQL. Not a health bug — see
  `kernel.md`. Workaround: always run upgrades via `install.php` directly, never rely on the
  packages admin page alone.
- **`list_item.php` could potentially reuse more of liberty's generic xref plumbing** than it
  currently does (icons only, not the templates themselves) — worth a proper look once
  `liberty/MANUAL.md`'s own reference material is complete enough to design against.
