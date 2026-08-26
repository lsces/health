# Health Package — Reference Manual

Architecture sketch, not an as-built reference yet — no code exists for this package (see
`CLAUDE.md` for status). Written now, ahead of the build, because the Samsung Health export's
`jsons/` detail tier raises a generic-handling question worth settling before any schema gets
written, not after. Once real building starts this file should be corrected/extended the normal
way (present tense, current behaviour only) — treat everything below as a proposal, not a
description of working code.

Companion package: `food/CLAUDE.md` — same data source, same Samsung Health export, same
liberty_xref-based approach. Read that first for the pattern being followed (Component/Assembly/
Movement split, xref group/item design, per-package JSON template precedent) — this file only
covers what's different for Health.

## Data source

`~/Personal/Health/Samsung Health/health_lester_<date>/` — the Health half of
`split_health.sh`'s output (see `reference_samsung_health_export` memory), sibling to Food's
`food_lester_<date>`. Two tiers inside each dated export, structurally very different from each
other:

- **CSV tier** — one flat table per data type (`com.samsung.health.*.csv`,
  `com.samsung.shealth.*.csv`), 2-line preamble then real rows, every row carrying a `datauuid` +
  `create_time`/`update_time`. Same shape as Food's `food_info.csv`/`food_intake.csv` — this is
  the tier Food's whole import pattern already solves.
- **`jsons/` tier** — one subdirectory per CSV data type, containing per-record detail blobs named
  `<datauuid>.<suffix>.json`, referenced from the matching CSV row by `datauuid`. Not every CSV
  type has a `jsons/` counterpart (only device/sensor-heavy types do); not every `jsons/` blob is
  the same shape (see next section). A third, smaller **`files/` tier** also exists
  (`files/com.samsung.health.ecg/<datauuid>.data.pdf` — Samsung-generated ECG report PDFs) — a
  real file attachment, not JSON at all, noted here as a known gap (see Open questions).

## `jsons/` shape taxonomy — the actual generic-handling problem

Surveyed the 2026-08-14 export's `jsons/` tree (28 subdirectories, ranging from `exercise` at
626MB/5,348 files down to single-digit-file types like `stress`/`heart_health_score`). Four
distinct shapes turn up, and only one of them is what Food's already-planned JSON xref template
was designed for:

1. **Flat single object** — e.g. `exercise/<uuid>.sensing_status.json`:
   `{"heart_rate":{"max_hr_auto":186,...},"sampling_rate":5000}`. Same shape as Food's `FAT`/
   `VIT`/`MIN` blobs (a handful of named sub-fields, one row). **Already solved** by Food's planned
   `template='json'` xref item — Health becomes its second real consumer, which is exactly the
   trigger Food's own notes said would justify promoting it from food-package-local to a genuine
   liberty mechanism (see `food/CLAUDE.md`'s nutrition xref section).
2. **Homogeneous array-of-objects — a "2D array"/embedded time series** — the dominant shape by
   file count: `tracker.heart_rate/<uuid>.binning_data.json`, `hrv/<uuid>.binning_data.json`,
   `movement/<uuid>.binning_data.json`, `tracker.pedometer_day_summary/<uuid>.binning_data.json`,
   `exercise/<uuid>.location_data.json` — each is a JSON array of N rows, every row a flat object
   sharing the same keys (typically `start_time`/`end_time` + 1-3 numeric readings). This is the
   shape the current single-object JSON template genuinely cannot represent — it needs a different
   mechanism, not a bigger version of the same one (see "Proposed generic mechanism" below).
3. **Deeply nested / irregular** — `sleep_raw_data/<uuid>.data.json`: an array of objects, each
   containing `acc` (an 8-int raw accelerometer/sensor reading) and `rri_info` (an array of 3-int
   triples). Not meaningfully browsable or summarizable as tabular data — effectively an opaque
   sensor dump. Treat as archival-only, not a candidate for structured display at all.
4. **PDF attachment** (`files/` tier, not `jsons/`) — ECG report documents. A file-attachment
   need, unrelated to the xref mechanism entirely.

**Scale point that matters for design**: shape 2 is not a handful of sub-fields, it's genuinely
tabular — `movement/` alone is 11,850 files, `exercise/` is 5,348 files each with up to 5 JSON
blobs (`live_data`, `sensing_status`, `live_data_internal`, `location_data`,
`location_data_internal`), totalling 626MB. Exploding rows like these into individual
`liberty_xref` rows (the way Food's scalar nutrition items work, one row per field) would mean
tens of millions of database rows for this tier alone. That's not a performance tuning question,
it's a wrong-mechanism signal — shape-2 data needs to stay as a single stored blob per parent
record, not be decomposed row-by-row the way scalar xref items are.

**Blob vs. file-attachment — decided 2026-08-18, blob wins**: bitweaver has a file-attachment
mechanism that could store each `jsons/` record as its own attached file rather than a
`liberty_xref.data` CLOB. Considered and rejected — an attachment is a second content object plus
filesystem I/O plus its own permission/versioning machinery, real overhead against what's actually
needed here (read one blob, render it). A `liberty_xref` row with the JSON in `data` is strictly
lighter for this case. Settles the "file-referenced vs. DB blob" open question below in favour of
the blob.

## Proposed generic mechanism (not built, not confirmed with Lester)

Two JSON xref shapes, not one:

- **`template='json'`** (Food's existing plan) — flat object, one row per parent record, each
  named sub-field individually editable. Reused as-is for Health's shape-1 blobs.
- **New template, name TBD (`'series'`/`'graph'`?)** — array-of-homogeneous-objects, stored as a
  single CLOB in `liberty_xref.data` (see blob-vs-attachment decision above), read-only (device-
  generated fact, not hand-curated — there's no "editing" a heart rate reading).

  **Import-time shape reduction, not just raw pass-through (2026-08-18)**: unlike `FAT`/`VIT`/
  `MIN`, which round-trip their source JSON unparsed, shape-2 data's actual end use is only ever
  "plot this as a graph" — so the importer should normalize Samsung's raw multi-key row objects
  (`{"heart_rate":68.0,"heart_rate_max":72.0,"heart_rate_min":66.0,"start_time":...,
  "end_time":...}`) down to plain `[time, value]` pairs before storing, dropping the fields a
  graph doesn't need (per-bin max/min, redundant end_time) rather than storing Samsung's full
  per-reading object shape. Smaller stored blob, and the display template only ever needs to
  handle one normalized shape regardless of which source field/data type it came from. Which
  single value to keep per data type (e.g. `heart_rate` here, `activity_level` for `movement`,
  `sdnn`/`rmssd` are two values for `hrv` — may need two graph lines, not reducible to one) is a
  per-import-script decision, not automatic.

Shape-3 (nested/irregular) and shape-4 (PDF) aren't good candidates for either template — flagged
as open questions, not designed here.

## Content model — settled 2026-08-22

**Day-as-base-object, not `HealthMetric`/`HealthSession`** — superseded the split floated
2026-08-18 (see `CLAUDE.md`'s session log for the full back-and-forth). `HealthDay`
(`includes/classes/HealthDay.php`, `content_type_guid='healthday'`) is a pure `liberty_content`
record, content_id only, no companion table — same "no ID-alias table" reasoning as Food/Stock's
own content types. Title is always the ISO date (`YYYY-MM-DD`); `HealthDay::findOrCreate($date)`
is the normal way an importer gets a content_id to attach xref rows to, since a date is all it
ever knows ahead of time — `lookupByDate()`/`lookup()` (by content_id) also exist but aren't the
usual entry point.

Reconciled the two open questions that had blocked this decision:
- **Diverges from Food's "day is a report, not a record" on purpose, not by accident** — Food's
  days hold several genuinely separate real-world events (meals), Health's metrics are naturally
  one-or-a-few-per-day already, making Day-as-record the better fit here even though it wasn't for
  Food.
- **Scale question resolved per metric, not with one blanket rule**: medium-cardinality CSV-tier
  data (a handful of readings/day at most — weight, BP, pulse-oximeter, temperature) gets one
  `liberty_xref` row *per reading*, `multiple=1`, nothing pre-reduced at import time. The
  high-frequency `jsons/` shape-2 tier (thousands of raw sensor points/day) is a different order of
  magnitude and still isn't decomposed to one row per reading — `PULSE`'s half-hour-slot bucketing
  (below) is the compromise: coarser than raw-per-minute, finer than a single daily figure.

**Items designed so far, all `multiple=1`, read-only (`-1`), day-summary values computed at query
time rather than stored** (full reasoning per item, plus the Samsung-vs-HealthForYou source
investigation behind them, is in `project_health_package_scoping` memory — this is the settled
shape, not the reasoning trail):
- **`WT`** — weight+BMI+body-composition. `xkey`=weight (kg), `xkey_ext`=BMI (cached, not source
  of truth — a future height change would need a recalc pass). `data`=json body-comp
  (`body_fat_pct`/`water_pct`/`muscle_pct`/`bone_mass_kg`), `json-list` template with a registered
  hint array, same convention as Food's `FAT`/`VIT`/`MIN`. Sourced from HealthForYou's Weight
  section, every reading imported (not just an AM-only reduction) — day-summary "lowest AM
  weight, preferring a reading with a successful body-comp scan" is a query-time filter, not an
  ETL step.
- **`PULSE`** — one row per half-hour clock slot (00:00–00:30, …, max 48/day), built by
  re-bucketing the watch's variable-length recording sessions against fixed clock-aligned windows.
  `xkey`=slot average, `xkey_ext`=low/high json, `data`=that slot's own minute-level bins as json.
  Daily low/high/average are a query-time rollup over the day's slots. A missing slot isn't
  automatically meaningful — the watch charges off-wrist 1hr+/day, and no battery/wear-status data
  exists anywhere to distinguish that from a genuine erratic-rhythm dropout.
- **`OXI`** — finger-probe pulse-oximeter reading, `value` template (same shape as `BP`):
  `xkey`=SpO2 average, `xkey_ext`=Pulse, `data`=json `{spo2_min, spo2_max}`. Deliberately separate
  from `PULSE` rather than folded into its rollup — different device, different cadence, and
  "correcting" a live daily-low computation from an external reading doesn't fit the model.
- **`BP`** — `value` template: `xkey`=systolic, `xkey_ext`=diastolic, `data`=json
  `{pulse, map, source, comment}`, `source` derived from Samsung's `pkg_name` (cuff vs watch-PPG) /
  HealthForYou (cuff only). Every raw reading imported, no daily reduction — even cuff readings
  show real short-interval variability given Lester's arrhythmia, not measurement noise.
- **`TEMP`** — plain `text` template, genuinely one scalar: `xkey`=temperature (°C),
  `xkey_ext`=Mode (e.g. "Ear temperature"), no `data`. Same-day duplicates are normal (retake after
  cleaning the probe tip), not a data-quality issue.

**Session/exercise data (the original `HealthSession` sketch) not reconsidered yet** under the
Day-as-base-object model — the start/end-date storage notes below still apply to whatever ends up
representing a bounded time-span event (exercise, sleep), just not yet re-examined against
"everything hangs off Day" now that Day is a real content object rather than a proposal.

- **Start/end time storage**: a session's own summary xref row uses `liberty_xref.start_date`/
  `end_date` directly for the real session start/end instants (not a scalar field). Every imported
  session already happened, so every row lands in that content record's own synthetic "History" tab
  by default (`end_date IS NOT NULL` sweep, see `liberty/MANUAL.md`'s Expunge and history section)
  — fine here specifically because health data is never edited via the UI, so the History tab's
  Edit→Restore icon swap never applies. Don't assume this same choice is safe for an *editable*
  xref item elsewhere without re-checking.
- **Import wrinkle, not yet built**: Samsung's CSV rows carry one `time_offset` per row for
  *both* `start_time` and `end_time` — wrong for a session spanning a BST↔GMT transition (each
  end needs its own offset). Confirmed against a real transition-night sleep record, not assumed.
  Resolve each timestamp against the `Europe/London` IANA zone directly instead of trusting the
  row's single offset for both ends — see `CLAUDE.md`'s 2026-08-22 entry for the verified example.

## v1 scope — cherry-picked CSV tier, `jsons/` tier explicitly deferred

**Cherry-picked by actual use, not by richness (revised 2026-08-18)** — the earlier framing
("import the CSV types already flagged rich in `reference_samsung_health_export`") is superseded
by a narrower rule: import only what Lester actually looks at. Row count alone isn't the filter —
`advanced_glycation_endproduct` has real rows but is being dropped outright, not deferred, because
it's not something he uses. Expect the real CSV-type list to be shorter than the full "rich" set
in that memory once actually reviewed against usage, not longer. This needs an explicit pass
against the CSV list before the importer gets built — not done yet, just the policy is decided.

Whatever CSV types make the cut need **zero new generic mechanism** either way — straightforward
scalar-xref import, directly following Food's `food_info`/`ImportFoodInfo.php` pattern. That's the
buildable first slice, once the actual list is confirmed.

The `jsons/` raw time-series tier is a second, heavier slice, and the same cherry-picking logic
applies even harder there — most shape-2 types probably don't need the full per-reading detail
imported at all, only whichever ones Lester actually wants to see graphed. Needs the `series`
template actually built (including the time/value reduction above) before this slice can start.
Not scoped further — a decision for whenever this slice gets picked up.

## Read-only xref items — decided 2026-08-18, on liberty_xref_item now

**Resolved, not just designed** — see `liberty/MANUAL.md`'s Data model section (the authoritative
spec) and `liberty/CLAUDE.md`'s same-dated session log entry for the full reasoning. Settled
straight onto `liberty_xref_item` rather than built Health-local first, because this is a generic
*dispatch*-path property (`list_xref.tpl`'s Edit/Delete icons, `edit_xref.php`'s write path), not
a new template shape — the JSON-template precedent of "build package-local, promote once a second
consumer wants it" doesn't fit a gap that lives in liberty's own dispatch code regardless of which
package triggers it.

**Encoding**: `multiple` on `liberty_xref_item` — reuses the existing column rather than adding one
(it's a plain `I2`, no `CHECK` constraint, confirmed zero other code branches on it by truthiness).
`-1` = read-only (not a cardinality variant — flat, distinct flag). `-2` ended up meaning something
unrelated (mutually exclusive within a group, added same day for Food's SGL/WT/VOL gap — see
`liberty/MANUAL.md`), not "read-only, multiple-cardinality" as first sketched here — Health's
`series`/`graph` item type (one blob per parent record) only ever needs `-1`.

**Not yet enforced** — the column contract exists, but `add_xref.php`'s item picker, `edit_xref.php`'s
write path, and the per-row item template's Edit/Delete rendering don't check it yet. Real
enforcement work lands whenever the `series` template itself gets built (there's no read-only item
type to exercise it against yet), or gets picked up standalone before then.

## Domain / publishing

Unlike Food (whose recipes are expected to eventually publish to `myhomecloud`), Health data has
no public-facing component at all — 100% private to **rdmcloud**, same as Food's own consumption
diary and pantry ledger. No publish/copy mechanism needed for this package.

## Open questions (not decided)

- Exact name of the `series` template (`'series'`/`'graph'`?) and its exact stored JSON shape
  (single `[time,value]` pairs vs. `[time,value1,value2]` for two-value types like `hrv`'s
  sdnn/rmssd) — direction sketched above, not confirmed with Lester.
- Shape-3 (`sleep_raw_data`'s nested sensor dump) — likely just archival, not worth a display
  mechanism, but not decided.
- PDF attachment handling (`files/com.samsung.health.ecg/*.data.pdf`) — needs whatever
  file-attachment mechanism the stack already has elsewhere (not checked yet).
- Whether HealthSession needs its own map/child table (the way Food deliberately avoided one for
  FoodAssembly) or stays content_id-only, following the [[feedback_content_id_only]] precedent.
- The actual cherry-picked CSV/`jsons/` type list — policy decided (usage-driven, not
  richness-driven), the list itself still needs a real pass against
  `reference_samsung_health_export`'s inventory.
- `exercise/*.location_data.json`'s per-second lat/long/altitude (shape-2, tied to logged Exercise
  sessions, not passive step-counting) — **not health's job to solve** (Lester's call, 2026-08-26):
  importing/converting these traces for display is a `mapper`-package task (see its own
  `CLAUDE.md`'s "Known follow-ups"), not something health should build. Health's only stake is
  having the raw JSON available for mapper to read.
