# Health Package — Developer Notes

## Status (2026-08-18): skeleton scaffolded, ready to start filling

`~/Development/bitweaver/health/`, own git repo pushed to `github.com/lsces/health` (private,
matches food/liberty/stock convention). Registered (`includes/bit_setup_inc.php`, empty
`admin/schema_inc.php`), symlinked into the **rdmcloud** site
(`/srv/website/rdmcloud/health -> ../_bw5/health`, same pattern as every other package there) —
not yet installed via the packages admin page, no content classes/schema/pages exist yet. Built by
directly copying food's own initial-skeleton commit (`food` repo, commit `ac17ee0`) and adapting
FOOD→HEALTH — same shape: `bit_setup_inc.php`, `admin/schema_inc.php` (empty `$tables`),
`index.php` placeholder, `templates/menu_health.tpl`. Top-level
`~/Development/bitweaver/.gitignore` updated with `/health/` (nested repo, nothing to track at
the wrapper level, same as `/food/`/`/stock/`/`/liberty/`).

Companion package: `food/CLAUDE.md`. Same Samsung Health export, same liberty_xref-based approach
— read Food's notes for the established pattern (Component/Assembly/Movement split, per-package
JSON template precedent, `datauuid`-keyed upsert import strategy) before designing Health's
equivalents from scratch.

## 2026-08-18 — JSON survey, generic-handling architecture sketch

Prompted by the Health export's `jsons/` detail tier turning out to be structurally different from
what Food's planned JSON xref template (`FAT`/`VIT`/`MIN`, a flat object of named sub-fields) can
represent — most of it is array-shaped ("two-dimensional"), not object-shaped. Surveyed the full
`jsons/` tree in `health_lester_20260814090949` (28 subdirectories) before sketching anything, full
findings written into `MANUAL.md`'s "jsons/ shape taxonomy" section rather than duplicated here.

**Headline conclusions** (detail/reasoning in `MANUAL.md`):
- Four distinct `jsons/` blob shapes exist, not one: flat single object (already solved by Food's
  planned mechanism), homogeneous array-of-objects/time-series (the actual new problem — needs a
  different, read-only, summary-oriented template rather than a bigger version of the object one),
  deeply-nested/irregular sensor dumps (probably archival-only, not worth a display mechanism),
  and PDF report attachments (`files/` tier, not JSON at all — a separate file-attachment need).
- Scale rules out decomposing the array-shaped data into individual `liberty_xref` rows the way
  Food's scalar nutrition items work — `exercise/` alone is 626MB across 5,348 files, `movement/`
  is 11,850 files; row-per-reading would mean tens of millions of DB rows for this tier alone.
- The CSV tier (the same data already triaged as "rich" in the `reference_samsung_health_export`
  memory — weight, blood_pressure, sleep, exercise, heart_rate, hrv, step_daily_trend, etc.) needs
  none of this — it's a plain scalar-xref importer, directly following Food's `food_info` import
  pattern, and is the obvious buildable v1 slice. The `jsons/` raw time-series tier is explicitly
  a second, heavier, not-yet-scoped slice.
- Proposed content model: **HealthMetric** (point-in-time scalar reading) + **HealthSession**
  (bounded event with summary scalars, optionally a raw time-series detail attached later) —
  closer to Stock's append-only movement-ledger shape than to Food's Component/Assembly pair,
  since Health data is device-generated fact, not something the user composes.
- Domain: 100% private to rdmcloud, no publish step — unlike Food's recipes, nothing in Health has
  a public-facing reason to exist on myhomecloud.

Nothing here is confirmed with Lester beyond the shape survey itself — the proposed template
name, content model, and v1/v2 slice split are a sketch for review, not a design decision yet.

## 2026-08-18 (same day, follow-up round) — storage/scope refinements, read-only gap identified

Four decisions/findings out of Lester reacting to the sketch above, all folded into `MANUAL.md`:

- **Blob beats file-attachment, decided.** Considered storing each `jsons/` record via bitweaver's
  existing file-attachment mechanism instead of a `liberty_xref.data` CLOB — rejected, an
  attachment is a second content object plus filesystem I/O plus its own permission/versioning
  machinery, more overhead than this needs. Settles the "DB blob vs. file-referenced" open
  question from the first round in favour of the blob.
- **Scope is usage-driven cherry-picking, not richness-driven.** Supersedes the earlier "import
  everything `reference_samsung_health_export` flagged rich" framing — e.g.
  `advanced_glycation_endproduct` has real row counts but is being dropped outright, not deferred,
  because Lester doesn't use it. Applies to both the CSV tier and, even more so, the `jsons/`
  tier. The actual cherry-picked list still needs a real pass, not done yet.
- **`series` template's stored shape reduced to time+value pairs, not raw pass-through.** Unlike
  `FAT`/`VIT`/`MIN` (round-tripped unparsed), shape-2 data's only real use is being graphed — so
  the importer should normalize Samsung's multi-key per-reading objects
  (`heart_rate`/`heart_rate_max`/`heart_rate_min`/`start_time`/`end_time`) down to plain
  `[time, value]` pairs before storing, dropping fields a graph doesn't need.
- **Real liberty framework gap found: no read-only concept on `liberty_xref_item`.** Every
  existing xref consumer treats data as user-editable; Health's device-generated `series`/`json`
  data genuinely shouldn't offer edit affordances at all — this is the "hook" Lester was looking
  for and it doesn't exist yet. Different in kind from the JSON-template precedent (that was a new
  template shape; this is about the generic `list_xref.tpl`/`edit_xref.php` dispatch path itself
  refusing to offer/accept edits) — open question on whether it should go straight into
  `liberty_xref_item` rather than waiting for a third package to need it, since a package-local
  workaround would mean duplicating that dispatch code. Not designed, needs a direct decision with
  Lester before building — see `MANUAL.md`'s dedicated section.

## 2026-08-18 (post-walk) — Day-as-base-object floated, planning stage only

Lester's own framing: "the liberty object base in health has to be a day and everything hangs off
that via xref?" — a real alternative to the `HealthMetric`/`HealthSession` content-model sketch,
captured in `MANUAL.md`'s "Proposed content model" section rather than resolved here. Two open
questions before it's actionable: whether this genuinely diverges from Food's "day is a report,
not a record" precedent for a good reason (Health's metrics being naturally one-per-day already,
unlike Food's several-real-meals-per-day) or whether that divergence needs more thought first; and
how it interacts with the already-settled "don't decompose `jsons/` shape-2 data into individual
xref rows" scale constraint — read as one xref row per metric-*type* per day, not one per raw
reading, but not confirmed. Explicitly not scoped or built — "just another nail for the planning
stage," Lester's own words.

## 2026-08-22 — session start/end time storage settled; real DST wrinkle found

**Decided: `HealthSession`'s own summary xref row uses `liberty_xref.start_date`/`end_date`
directly for the real session start/end instants** — not a `series`/scalar field. Checked the
actual liberty dispatch code before deciding rather than assuming: `end_date IS NOT NULL` only
routes a row into that content record's own synthetic "History" tab
(`LibertyXrefType.php:269`'s `end_date < now() THEN 'history'` sweep) — it doesn't affect
discoverability anywhere else (package-level list/report pages query `liberty_content` directly,
untouched by this). The one real cost — the "History" tab's action icons swap Edit for Restore
(`!$isHistory` gates Edit in `action_icons.tpl`) — doesn't apply here at all: health data is
never edited via the UI, it's device-sourced and read-only by design. So every session landing in
its own History tab by default (since every imported session already happened) is fine, not a
bug to route around. See `liberty/MANUAL.md`'s Expunge and history section for the mechanism.

**Real, verified gap found along the way, relevant to any session-shaped import (sleep,
exercise)**: Samsung's CSV rows carry exactly one `time_offset` column per row, applied to *both*
`start_time` and `end_time` — but a session spanning a BST↔GMT transition needs two different
offsets, one per end. Confirmed against real data, not assumed: a real sleep record spanning
2025-10-25→26 (the night BST ended) has `start_time: 2025-10-25 22:47` (should be BST, `+0100`)
and `end_time: 2025-10-26 06:30` (should be GMT, `+0000`), but the row's own `time_offset` is a
single `UTC+0000` — applying it to `start_time` would put that end an hour out. Food's proven
`foodParseSamsungTime()` pattern (build an explicit `DateTimeZone` from the row's own offset, no
ambient-timezone dependency at all) generalises cleanly to every instant-only health metric, but
session start/end pairs need a different approach: resolve each timestamp against the
`Europe/London` IANA zone directly (which knows the real historical transition instant) rather
than trusting the row's single offset for both ends. Not yet built — noted here so the eventual
sleep/exercise importer doesn't rediscover this the hard way.

**Still not done**: the v1 CSV-type cherry-pick pass (see the 2026-08-18 entry above) — floated
as the next concrete step, not yet started.

## 2026-08-22 (later) — Day-as-base-object decided, five xref items designed, HealthDay scaffolded

Long design conversation, worked out against real HealthForYou/Samsung export data rather than
assumed — full detail in `project_health_package_scoping` memory, this is the settled outcome.
Day-as-base-object (floated post-walk earlier the same day) is now the actual model, not an
alternative under consideration — `HealthMetric`/`HealthSession` as the base objects is
superseded. `WT` (weight/BMI/body-comp), `PULSE` (half-hour HR slots), `OXI` (finger-probe
pulse+SpO2), `BP` (systolic/diastolic/pulse/MAP/source), and `TEMP` (ear temperature) all designed
to the same `multiple=1`, read-only, query-time-rollup shape — see `MANUAL.md`'s "Content model —
settled 2026-08-22" section for the actual field-by-field spec, not duplicated here.

Real findings behind the design, worth remembering even though the reasoning trail itself lives in
memory: Samsung's own `blood_pressure.csv` is genuinely useful (not just harder-to-read than
HealthForYou) via `pkg_name` distinguishing cuff vs watch-PPG readings plus `calibration_id`
linkage; a scary-looking historical timestamp collision in that same file turned out to be the
cuff monitor's 150-reading onboard buffer getting fully re-downloaded on every sync before a fix
landed, not a data-corruption bug — real duplicates of one reading, not several distinct ones.

**`HealthDay` scaffolded** (`includes/classes/HealthDay.php`, `content_type_guid='healthday'`) —
pure `liberty_content` record, content_id only, title is always the ISO date. Built directly off
`FoodComponent.php`'s pattern (constructor/registerContentType/lookup/load/store/isValid shape) —
`findOrCreate($date)` is the new piece, since Food/Stock's content types are always looked up by
content_id, never by "find-or-create the row for this natural key." Registered in
`admin/schema_inc.php` (`registerContentObjects` + `p_health_*` permissions, mirroring Food's
block) and `bit_setup_inc.php`'s stale HealthMetric/HealthSession comment corrected.

**Installed and verified live on desktop's rdmcloud, same day.** Lester ran the installer himself
(`install.php?step=3` requires an admin login the assistant doesn't have — no browser/Chrome
automation on this machine either, so this step needs a human). Confirmed via isql:
`liberty_content_types` has the `healthday` row (`handler_class='HealthDay'`,
`handler_package='health'`), `kernel_config` has `package_health`/`y` and
`package_health_version`/`5.0.1`. Exercised the class itself via a throwaway
`health/test_healthday.php` script (bootstrapped like `index.php`, called `findOrCreate()`/
`lookupByDate()`/`isValid()`, deleted once confirmed working — not a permanent file).

**Real bug found and fixed this way**: `findOrCreate()`'s newly-created path didn't call `load()`
after `store()`, so a freshly-created Day's `getTitle()` came back empty even though the DB row
itself was correct (`liberty_content.title` genuinely held the right date) — `mInfo` just wasn't
repopulated in memory. Fixed with a `$day->load()` after `store()`; re-verified with a second date,
title now comes through correctly on both the create and find paths. `findOrCreate()` is
idempotent (repeat calls return the same `content_id`) and `lookupByDate()` agrees. Two real
`HealthDay` rows now live in rdmcloud's DB (2026-08-21, 2026-08-22) from this test — left in place,
not test junk to clean up, since they're exactly what a real importer would have created anyway.

**Not yet built**: the actual WT/PULSE/OXI/BP/TEMP importers — the natural next step now that
there's a real, tested `HealthDay` content type to attach them to.

## 2026-08-22 (later still) — WT importer built, run against real data, verified

`vitals` xref group + `WT` item registered (`admin/schema_inc.php`'s `registerSchemaDefault` for
future clean installs, **and** hand-pushed directly via isql into rdmcloud's live DB — a schema/
type-*definition* write, not user data, so within the sanctioned exception in
`feedback_no_raw_sql_hacks` memory, not a live-data hack). `WT` is `multiple=-1` (read-only).

**Real gotcha resolved before writing any importer code**: `multiple=-1` isn't just a UI-hiding
flag, `LibertyXref::verify()` actively *rejects* `fAddXref`-style row addition for `-1` items —
checked the actual code (`verify()`'s `$itemMultiple === -1` branch), not assumed. But that
rejection only fires when the caller sets `fAddXref` or pre-loads an existing row for in-place
edit — a plain `store()` call with its own explicit `xorder` (computed the same way `fAddXref`
would have, just done by hand) sails straight through unaffected, since `store()` itself only
branches on whether `xref_id` is set (update) vs not (insert). So `-1` genuinely gets both
"read-only, no manual edit/add via the UI" *and* "importer can insert as many rows per day as it
wants" — not a contradiction, just two different call paths through the same code.

**Built**: `import/ImportWT.php` (parsing + `healthStoreWT()`/`healthImportWT()`) and
`import/load_wt.php` (thin wrapper, mirrors `food/import/load_food_info.php`'s shape) +
`templates/import_results.tpl`. Reads `storage/health/weight.csv` (a `healthforyou_lester_<date>/
weight.csv` split copied there by hand, same manual-copy workflow as Food's own importers).
Every reading imported as its own row — no AM-only/lowest-weight reduction at import time, per the
day-as-base-object pivot; `entry_date` (UTC, resolved via `Europe/London` since HealthForYou's CSV
carries no UTC offset at all unlike Samsung's) is the dedupe key for safe re-runs.

**Live-tested against the real 1023-row export** (auth via a throwaway `users_cnxn` faked-session
cookie for `user_id=3`, cleaned up by exact cookie value afterward per
`feedback_test_cookie_cleanup_scope` — `install.php`/admin pages need a real login the assistant
doesn't have, curl can't drive that, but a direct page hit only needs an authenticated cookie).
First run: 1021 created, 2 skipped (2 genuine same-minute duplicate rows *within the source file
itself* — confirmed via `awk`, not assumed — both real). Second run against the same file: 0
created, all 1023 skipped — dedupe/idempotency confirmed. Spot-checked stored rows directly: a day
with an evening (failed body-comp scan, all-zero JSON) and a morning (real body-comp) reading both
present as separate `xorder`-sequenced rows, BST-aware `entry_date` conversion correct (08:03 BST
source time stored as `07:03` UTC). 1021 real `WT` rows now live in rdmcloud's DB — genuine
imported data, not test junk.

**One thing to know, not a bug**: `liberty_xref.item` has no `content_type_guid` of its own (only
`liberty_xref_item` does) — Food's `foodcomponent` also has a `WT` item (a different meaning,
quantity-unit "Weight" not a reading) at the same short code. A bare `WHERE item='WT'` query
matches both packages' rows; scope by joining `liberty_content` on `content_type_guid` when
checking counts directly via isql, same gotcha the assistant hit and resolved while verifying this.

**Not yet built**: PULSE/OXI/BP/TEMP importers, and the day-summary query layer that picks a
day's headline weight from its (possibly several) `WT` rows.

## 2026-08-22 (later still) — BP importer built, run against real data, verified

`BP` item registered same way as `WT` (schema_inc.php + direct isql push) — `value` template
(the two co-equal systolic/diastolic values, not `WT`'s single-primary shape), `multiple=-1`,
sort_order=1 in the same `vitals` group.

**Built**: `import/ImportBP.php` + `load_bp.php`, reusing `ImportWT.php`'s
`healthParseHealthForYouCsv()`/`healthParseHealthForYouTimestamp()` via a plain `require_once`
rather than duplicating them — same convention Food's `ImportFoodIntake.php` already uses against
`ImportFoodInfo.php`'s own helpers (checked that precedent before assuming it was the right move,
not just copy-pasted). `data` json is deliberately sparse — HealthForYou never has a `comment`,
so that key's just absent, not stored empty.

**Deliberately scoped to HealthForYou (cuff) only, Samsung not touched this pass** — every
imported row is tagged `source: 'cuff'`. Samsung's own `blood_pressure.csv` also has real BP data
(cuff-synced *and* watch-PPG, via `pkg_name`) but needs its own dedup work first (the 150-reading-
buffer full-resync duplicates) plus cross-source dedup against this same HealthForYou data
(confirmed to be the same physical readings) — scoped out rather than half-built, flagged clearly
so it isn't mistaken for "done."

**Live-tested against the real 656-row export**: 656 created, 0 skipped on the first run — no
same-minute collisions this time (unlike `WT`'s 2). Re-run: 0 created, all 656 skipped — idempotent.
Spot-checked stored rows against the raw CSV values inspected earlier in this design thread,
matched exactly (e.g. `22/08/2026 07:56 am` → `136/75`, pulse 60, MAP 95 → stored `entry_date`
`2026-08-22 06:56:00` UTC, correct BST conversion). 656 real `BP` rows now live in rdmcloud.

**Not yet built**: PULSE/OXI/TEMP importers, Samsung-source BP (cuff-dedup + cross-source dedup +
watch-PPG with `source:'watch'`), and the day-summary query layer for any of these.

## 2026-08-22 (later still) — PULSE importer built, run against real data, verified

`PULSE` item registered same way as `WT`/`BP` (schema_inc.php + direct isql push), sort_order=2 in
`vitals`. `template='text'` as a placeholder — neither `xkey_ext`'s low/high JSON nor `data`'s bin
array fit `json-list`/`json-text`'s exact convention (those only cover the `data` column), revisit
once an actual day view exists to render against.

**Built**: `import/ImportPulse.php` + `load_pulse.php`. Source is Samsung's own
`tracker.heart_rate` CSV + its `jsons/` per-session `binning_data` files (not HealthForYou —
different device, different data shape entirely from WT/BP). **Deliberately scoped to only the
CSV rows that carry a `binning_data` filename** — checked against the real export: 4,687 of 33,943
rows (14%) have one, the rest are summary-only single-row entries with no minute detail. Importing
those too would need inventing an assignment rule for "which half-hour slot does a summary-only,
possibly slot-straddling session belong to," never part of the original design — deferred rather
than guessed at, same spirit as BP's Samsung-source deferral. The 4,687 rich rows still span 517
distinct days back to 2025-03-16, real usable coverage.

Every minute-bin (`heart_rate`/`heart_rate_max`/`heart_rate_min`/`start_time`, epoch
milliseconds — already unambiguous UTC, no BST/GMT wrinkle unlike the CSV's own `start_time`
column) across every session gets re-bucketed into a fixed half-hour clock slot aligned to
**Europe/London local time**, regardless of which session it originally came from. One `PULSE` row
per populated slot: `xkey`=slot average, `xkey_ext`=low/high json, `data`=the slot's own bins.

**Real bug found and fixed before the first successful run**: `array_combine()` fataled — every
single data row in this CSV carries one trailing blank field the header doesn't declare (confirmed
via Python's csv module against the raw file, not assumed corruption — a genuine, consistent
export-format quirk, not a one-off bad row). Fixed by slicing the row down to the header's own
column count before combining, rather than the stricter `count($row) < count($header)` skip-guard
`ImportWT.php`/`ImportBP.php` use (those source files don't have this trailing-field quirk, so
their simpler guard was never wrong for them).

**Live-tested against the real, full historical export** (~7.9MB CSV, 34MB of json files, same
`users_cnxn` faked-session-cookie technique as `WT`/`BP`, cleaned up after): first run took ~79s,
created 8,570 half-hour slots, 0 skipped, 29,256 source rows correctly counted separately as
"no minute detail" (33,943 − 4,687 = 29,256, exact). Re-run: 0 created, all 8,570 skipped —
idempotent (faster the second time, ~22s, filesystem cache warm). Spot-checked a stored slot's
`data` directly against its raw source bins — low/high in the summary row matched the actual
min/max across the 23 real bins stored. 8,570 real `PULSE` rows now live in rdmcloud.

**Not yet built**: OXI/TEMP importers, Samsung-source BP, and the day-summary query layer.

## 2026-08-22 (later still) — calendar day-cell hook wired up, live-tested against real data

Came out of a design conversation about a calendar-grid front page for Food/Health — settled on
reusing `calendar`'s existing generic month-grid (genuine shared infrastructure — content-type
filtering, date-range math already built) rather than duplicating it per package, by adding one
small optional per-content-type hook rather than a new architecture.

**Built, in `liberty` (shared, not health-specific)**: `LibertyContent::getContentList()` — right
next to where `title`/`display_link`/`display_url` already get set per content type (the same
dispatch every content-listing consumer, not just Calendar, already goes through) — now also
checks `method_exists($type['handler_class'], 'getDayCellHtml')` and, if present, stashes the
result as `$aux['cell_html']`. Purely additive: nothing changes for any content type that doesn't
implement it. `calendar/templates/calendar.tpl`'s three "Cell Content" blocks (day/weeklist/month
views) each wrapped in `{if $item.cell_html}{$item.cell_html}{else}<existing title/link>{/if}`.

**`HealthDay::getDayCellHtml()` built as the first real implementation**, deliberately a
first-cut placeholder — plain min/max/count across the day's raw `WT`/`BP`/`PULSE` rows, not the
considered "pick the real headline reading" day-summary logic still owed (lowest-AM-weight-
preferring-valid-scan for `WT`, etc.). Good enough to prove the mechanism against real imported
data; the actual rollup algorithm is still a separate task.

**Two real bugs found live-testing this, neither anticipated**:
- **`p_health_view` (and the other `p_health_*` perms) were registered as permission *types* via
  `registerUserPermissions()` but never actually granted to any role** — `users_role_permissions`
  had zero rows for any `p_health_*` permission, while `p_food_*`'s identical setup did have real
  role grants. Nothing gated on `p_health_view` (which turns out to include Calendar's own
  content-type listing) could ever have worked, for any user, until this was found. Fixed by
  inserting the same role mapping Food already has (view→Registered, create/update→Editors,
  expunge/admin→Administrators) directly via isql — a role/permission *grant*, judged the same
  category as the sanctioned xref-type-definition writes, not user data.
- **`HealthDay` records had no `event_time` set at all** — `findOrCreate()` never passed one to
  `store()`, so every row's `liberty_content.event_time` was `NULL`/`0`. Calendar's own listing
  query (`LibertyContent::getContentList()`, shared by every content-list consumer, not just
  Calendar) sorts/filters by `event_time` — so no `HealthDay` could ever appear in any date-ranged
  view, calendar or otherwise, until this was fixed. Fixed going forward (`findOrCreate()` now
  passes `event_time` = midday UTC of that date, `liberty_content.event_time` being a plain
  BIGINT unix timestamp, not a real TIMESTAMP column) and backfilled the 599 already-existing
  `HealthDay` rows via a direct `UPDATE ... DATEDIFF(SECOND, TIMESTAMP '1970-01-01 00:00:00',
  CAST(title||' 12:00:00' AS TIMESTAMP))` (derived purely from each row's own title, a one-time
  metadata correction, not a live-data edit with any audit-trail expectation).

**Live-verified end-to-end** (same `users_cnxn` faked-session-cookie technique, cleaned up after):
selecting only `healthday` in Calendar's content-type filter renders 31 real day tiles for the
current month, each showing genuine weight range + BPM range (and a BP count where present) pulled
live from the actual imported `WT`/`BP`/`PULSE` rows — e.g. `71.9–73.2kg / 54–63 bpm`. Confirmed
`foodassembly` (which doesn't implement the hook) still renders via the original per-meal title/
link fallback, unaffected by any of this.

**Not yet built**: `FoodDay` (the equivalent object for Food's calendar tile, per the same design
conversation — kcal/5AD/fibre totals computed via the existing `sumNutrition`/
`NUTRITION_SUMMARY_FIELDS` logic `view_day.php` already has); the real day-summary rollup
algorithm for `HealthDay::getDayCellHtml()` to replace today's placeholder; the calendar page's
own layout/CSS tidy-up (too much in the top third — separate, cosmetic, deliberately left until
there's real tile content to look at).

## 2026-08-22 (later still) — OXI and TEMP importers built: all five CSV-tier items now live

Same shape as `WT`/`BP`, both registered in `schema_inc.php` + direct isql push into the `vitals`
group: `OXI` (sort_order=3, `value` template — SpO2 average + Pulse as the two co-equal headline
values, `data`=json `{spo2_min,spo2_max}`) and `TEMP` (sort_order=4, plain `text` template —
temperature + Mode, no `data` json, nothing left over to store).

**Built**: `import/ImportOxi.php`+`load_oxi.php` (from HealthForYou's `pulse_oximeter.csv`) and
`import/ImportTemp.php`+`load_temp.php` (from `temperature.csv`) — both reuse `ImportWT.php`'s
CSV/timestamp parsing helpers via `require_once`, same convention as `BP`. Every reading imported
as its own row, no reduction.

**Live-tested against the real exports**: OXI — 626 created, 0 skipped, matching the file's own
declared count exactly; re-run 0 created/626 skipped, idempotent. TEMP — 624 created, 31 skipped
(same-minute duplicates, mostly the expected "clean the probe tip and retake" case — 624+31=655
matches the declared count exactly); re-run 0 created/655 skipped, idempotent. Spot-checked stored
rows against the raw CSV values inspected earlier in this whole design thread — matched exactly.
626 real `OXI` rows and 624 real `TEMP` rows now live in rdmcloud. Confirmed no new `HealthDay`
rows were created missing `event_time` — the fix already baked into `findOrCreate()` covered these
imports automatically, still 599 total Day rows, zero with a null/zero `event_time`.

**All five originally-designed CSV-tier items (`WT`/`BP`/`PULSE`/`OXI`/`TEMP`) are now built,
registered, and live-verified with real data.** Remaining work: `FoodDay`, the real day-summary
rollup (to replace `getDayCellHtml()`'s placeholder), Samsung-source BP (deferred dedup work), and
Calendar's layout tidy-up.

## 2026-08-22 (later still) — STEPS/ENERGY/SLEEP built: rest of the legacy spreadsheet's data lands

Lester's request was framed against the original legacy "Daily Exercise" ODS columns B–E (Steps,
Active Mins, Active Kcal, Exercise Raised HR) plus Sleep and Energy, floating that Energy "may or
may not include HRV" as an interesting variation to check.

**Refactored first**: all three new sources are Samsung CSVs sharing `PULSE`'s already-found
trailing-blank-field quirk (confirmed via the same field-count check — 100% of rows in all three
files, not a one-off). Extracted the inline fix from `ImportPulse.php` into two reusable
functions — `healthParseSamsungCsv()` (a generator, doesn't buffer the whole file) and
`healthFindLatestSamsungCsv()` — now the fourth+ importers reuse them via `require_once
ImportPulse.php`, same "first consumer owns the helper" convention as `ImportWT.php`'s
HealthForYou-CSV helpers. Re-verified `PULSE` itself still behaves identically post-refactor
(0 created/8,570 skipped) before building on top of it.

**`STEPS`** (from `activity.day_summary`) — `xkey`=steps, `xkey_ext`=active minutes, `data`=json
`{active_kcal}`. **`active_time` is in milliseconds**, not minutes or seconds — confirmed by
cross-referencing the exact 28/06/2026 reference row already known from the original spreadsheet
inspection: `6480095 / 60000 = 108.0016` minutes, matching the spreadsheet's own "108" exactly, and
`step_count`/`calorie` matched too (10897, 992.9). **"Exercise Raised HR" (spreadsheet column E)
has no matching source field** — `exercise_time` exists but is a wildly different scale (~85
minutes vs the spreadsheet's single-digit values for the same date) — left out, not force-fitted.

**`ENERGY`** (from `vitality_score`) — confirms the "interesting variation": **HRV doesn't need its
own item or the deferred shape-2 `jsons/` processing at all**, for a daily-summary purpose.
`vitality_score`'s own `shrv_value`/`shrv_score` ("sleep HRV") ride along in the *same row* as
`total_score` (Energy) — checked against the same 28/06/2026 reference row: `total_score`=88.2
matches the spreadsheet's Energy=88, and `shrv_value`=67.7 is a close match to the spreadsheet's
own separate "HRV"=67 column (different reference-night boundary likely accounts for the small
gap, not a mismatch). `xkey`=total_score, `xkey_ext`=shrv_value, `data`=json
`{shrv_score,activity_score,sleep_score}` (this `sleep_score` is vitality's own composite figure,
kept as reference detail — not the same thing as the real per-session `SLEEP` item below).

**`SLEEP`** (from `sleep`) — **one row per sleep session, not per day**, decided after checking
real data rather than guessing: the night of 27→28/06/2026 alone had three sleep rows (a short
evening nap, the main overnight sleep, another the next evening), and none matched the
spreadsheet's single Sleep Score figure for that date cleanly. Rather than invent an unverified
session-selection rule, every session imports as its own row (same "don't reduce at import time"
principle as `WT`/`BP`/`PULSE`/`OXI`) — picking/aggregating a day's headline sleep figure is a
query-time concern for the still-pending day-summary rollup. Assigned to the local
(Europe/London) calendar date its own `start_time` falls on. Same BST/GMT handling already
established for session-shaped data: both ends resolved directly against `Europe/London`, the
row's own `time_offset` column ignored entirely (real risk otherwise — a session spanning a
transition needs two different offsets, one single column can't give both).

**Live-tested against the real exports**: STEPS 711 created/0 skipped (27 historical rows with no
step count at all — before the watch tracked steps, a real gap not a bug); ENERGY 482 created/0
skipped (exact match to file's row count); SLEEP 621 created/0 skipped (102 rows with no
`sleep_score` — genuinely too-short/interrupted sessions Samsung doesn't score). All three
idempotent on re-run. Spot-checked the 28/06/2026 reference date directly against stored rows —
STEPS and ENERGY matched the values above exactly; SLEEP correctly split into two sessions under
`2026-06-27` (both started that day) and one under `2026-06-28` (the 21:25 BST start, correctly
stored as `20:25` UTC). 719 total `HealthDay` rows now exist (up from 599 — Samsung's activity/
sleep/energy history reaches further back than HealthForYou's), all with a valid `event_time` —
the earlier fix covered these automatically, no new backfill needed.

**Eight items now built and live**: `WT`/`BP`/`PULSE`/`OXI`/`TEMP`/`STEPS`/`ENERGY`/`SLEEP`.
Still open: Exercise Raised HR (no source found) and Sleep BPM (still needs deriving from HR-
during-sleep-window) remain unresolved gaps from the original spreadsheet mapping; `FoodDay`; the
real day-summary rollup; Samsung-source BP; Calendar's layout tidy-up.

## 2026-08-22 (later still) — list_item.php: raw xref data browser built and verified

First real UI page for the package (everything until now was import-only). `list_item.php` +
`templates/list_item.tpl` — a row of radio buttons across the top (one per registered `healthday`
xref item, `liberty_xref_item` queried directly, ordered by `sort_order` — new items appear
automatically, nothing hardcoded), selecting which item's raw rows show below. Deliberately shows
`xkey`/`xkey_ext`/`data` exactly as stored, no per-item formatting — a verification/debug tool for
eyeballing imported data without reaching for isql every time, not the eventual day view. Capped
at the 200 most recent rows (`FIRST 200`, `entry_date DESC`) with a "showing X of Y" count, since
`PULSE` alone has 8,570 rows — an unbounded list wasn't reasonable. Added to `menu_health.tpl` as
"Raw Data".

**Real bug hit and fixed**: `day` is a reserved word in Firebird — `lc.title AS day` fataled with
"Token unknown - day" even backtick-quoted (the PDO Firebird driver's backtick→identifier
translation doesn't appear to save a reserved-word alias the way it does table/column names).
Fixed by renaming the alias to `day_title`.

**Live-tested**: defaults to `WT` (1,021 rows, real values shown correctly); switching to `SLEEP`
via the radio button correctly re-filters to 621 real sleep rows. Both radio-button selection and
the reserved-word fix confirmed working end-to-end.

## 2026-08-22 (later still) — list_item.php: pagination + per-item column titles

Three refinements to the raw data browser, all live-tested:

- **Real pagination**, reusing the framework's own mechanism rather than hand-rolling one —
  `BitBase::prepGetList()`/`postGetList()` (static, no content object needed) build the `listInfo`
  hash the shared `{pagination}` Smarty plugin/`kernel/templates/pagination.tpl` already expects,
  same widget every other list page in this codebase uses. Firebird pagination is `FIRST n SKIP m`
  in the query itself. **20 per page here, not the site's own `max_records` default of 10**
  (confirmed that 10 default lives in `BitBase::prepGetList()`, `$gBitSystem->getConfig(
  "max_records", 10)`) — pre-seeded via `$_REQUEST['max_records'] ??= 20` before calling
  `prepGetList()`, still overridable via `?max_records=`. Pagination links correctly carry the
  selected `item` through via `listInfo.parameters` (same convention `food/list_components.php`
  uses for its own shop filter).
- **Per-item column titles** for the generic `xkey`/`xkey_ext`/`data` columns — a small PHP lookup
  array (`$columnTitles`) keyed by item code, e.g. `WT` → "Weight (kg)"/"BMI"/"Body Composition",
  `SLEEP` → "Sleep Score"/"Duration (mins)"/"Efficiency". Falls back to the raw column name for
  any item not yet listed there.
- **Dropped `xorder` and `entry_date` columns** — Lester's call, both redundant for this view
  (`xorder` is internal bookkeeping, `entry_date`'s date portion always matches the Day column
  already shown).

**Live-tested**: `WT` page 1 shows the friendly headers and real values with exactly 20 rows/page
(`ceil(1021/20)=52` total pages, matches). Switching to `SLEEP` + `page=2` correctly re-labels
columns, returns 20 different rows, and the pagination links (`page=3`, etc.) correctly carry
`item=SLEEP&max_records=20` through.

## 2026-08-22 (later still) — Samsung watch-PPG BP readings imported (391 rows)

Closed out the deferred half of BP: `import/ImportBPSamsung.php` + `load_bp_samsung.php`, importing
**only** the `pkg_name=com.samsung.android.shealthmonitor` (watch-PPG) rows from Samsung's own
`blood_pressure.csv`, tagged `source:'watch'`. Reuses `healthStoreBP()` from `ImportBP.php`
directly (storage/dedupe logic is source-agnostic) and `healthParseSamsungCsv()`/
`healthFindLatestSamsungCsv()` from `ImportPulse.php` — no new storage logic needed, only new
parsing.

**Deliberately still excludes the `com.sec.android.app.shealth`-tagged (cuff-synced, ~2,674) rows**
— confirmed these are the same physical readings already imported from HealthForYou's own
`blood_pressure.csv` (`source:'cuff'`), and additionally carry the historical 150-reading-buffer
duplicate problem that would need its own dedup pass. Both stay deferred together, not
half-imported.

`BP`'s registered JSON hint (`liberty_xref_item.data`) extended from `["pulse","map","source",
"comment"]` to add `"calibration_id"` — every watch-PPG row carries one, a real signal for later
judging how stale calibration was for a given reading, per the original design notes.

**Live-tested**: 389 created, 2 skipped (sub-second duplicate readings collapsing at the
second-level `entry_date` grain — real, not a bug) out of 391 total. Re-run: 0 created/391
skipped, idempotent. Spot-checked 2025-03-15 directly: 9 cuff readings (already imported) and 2
watch readings coexist cleanly under the same day, no collisions, `calibration_id` correctly
present only on the watch rows. Total `BP` rows now 1,045 (656 cuff + 391 watch − 2 skipped dupes,
exact).

**Nine items now built and live**: `WT`/`BP`/`PULSE`/`OXI`/`TEMP`/`STEPS`/`ENERGY`/`SLEEP` (BP now
combining both HealthForYou/cuff and Samsung/watch sources). Still open: Exercise Raised HR, Sleep
BPM, `FoodDay`, the real day-summary rollup, Samsung's cuff-tagged BP history (deferred, see
above), Calendar's layout tidy-up.

## 2026-08-22 (later still) — 2024 HealthForYou backfill (phone acquisition through end of 2024)

Lester pulled a second HealthForYou export, `HealthForYouApp_DataExport (3).csv` (`~/Downloads`,
moved into `~/Personal/Health/HealthForYouApp/` alongside the first), covering `01/05/2024` to
`31/12/2024` — confirming the earlier finding that HealthForYou's own date range is just an export
choice, not a retention limit (see the 2026-08-22 BP design entry above). Split via the existing
`split_healthforyou.py` into `healthforyou_lester_20241231/` — same 4-file shape, no format
differences from the 2026-08-22 export.

**No new code needed** — `WT`/`BP` (HealthForYou)/`OXI`/`TEMP` importers already existed and are
source-file-agnostic (they just read whatever's in `storage/health/`). Ran all four against this
older export's split files (temporarily swapped into `storage/health/`, then the current
2026-08-22 files copied back afterward — confirmed via a second re-run showing 0 created/full
counts skipped, nothing lost in the swap).

**Live-tested**: WT 196/2, BP 685/3, OXI 343/0, TEMP 186/2 (created/skipped) — all plausible
same-minute-duplicate counts, same pattern as the original imports. Combined totals now: WT 1,217,
BP 1,730, OXI 969, TEMP 810. **Earliest `HealthDay` is now `2024-06-29`** — matches "phone acquired
end of June" almost exactly. 785 total `HealthDay` rows (up from 719).

## 2026-08-22 (later still) — Samsung's cuff-tagged BP data: checked, genuinely additive, imported

Lester's question before building anything: does Samsung's cuff-tagged BP data (the half of BP
deferred back when `BP` was first built) actually add anything over HealthForYou's own export, or
is it fully redundant? Checked properly rather than assumed.

**Real finding, reverses the earlier assumption**: after collapsing Samsung's own internal
duplicate-buffer inflation (confirmed root cause from earlier: the cuff monitor's 150-reading
onboard buffer got fully re-synced on every connection before a fix landed) — 2,670 raw cuff-
tagged rows collapse to 806 truly unique readings via `(start_time,systolic,diastolic,pulse)`.
Compared those 806 against what's already in the DB from HealthForYou: **441 (over half) aren't
there at all.** Spot-checked several (e.g. 04–05/02/2025 at 18:19/06:55/11:12) — genuinely absent
from *both* HealthForYou exports, present in Samsung. HealthForYou's own export doesn't retain
everything Samsung Health ends up syncing — the earlier "fully redundant" assumption was wrong.

**`ImportBPSamsung.php` rewritten** to import both `pkg_name` sources in one pass (previously
watch-only): watch-PPG unchanged from before; cuff-synced rows now deduped via the key above,
rows before `2024-06-29` (confirmed phone-acquisition date) dropped as the known 4 device-setup
placeholder rows, then **minute-truncated before computing `entry_date`** — HealthForYou's own
timestamps have no seconds, Samsung's do, so truncating is what lets a genuinely-overlapping
reading correctly skip as a duplicate instead of re-inserting under different seconds. `load_bp_
samsung.php`'s docblock/title updated to match the new dual-source scope.

**Live-tested**: 441 created, 756 skipped (391 watch already-imported + 365 cuff readings that
did overlap with HealthForYou) — both numbers exact matches to the pre-build analysis. Re-run: 0
created, 1,197 skipped (391+806), idempotent. Spot-checked the 2025-02-04/05 readings identified
as missing during analysis — present now, correctly tagged `source:'cuff'`, and their pulse values
(43/31/35) are notably more erratic than nearby HealthForYou readings at similar BP values — real
physiological variability given Lester's arrhythmia, not a data error. Total `BP` rows now 2,171
(1,730 + 441, exact).

**All of BP is now imported from both available sources.** Still open: Exercise Raised HR, Sleep
BPM, `FoodDay`, the real day-summary rollup, Calendar's layout tidy-up.

## 2026-08-23 — `health_hr_raw` (5.0.2) surfaced a real installer gap, fixed properly via schema_inc.php/upgrades/5.0.2.php

Built `HEALTH_HR_RAW` (unifies both raw Samsung HR sources — see `ImportHRRaw.php`'s own docblock)
as a genuine table, not a `liberty_xref` item, per Lester's explicit "no manual tweaks" directive —
everything went through `admin/schema_inc.php` (fresh installs) + `admin/upgrades/5.0.2.php`
(existing installs via the real installer), not raw isql.

**Hit a real installer bug getting there**: registering `health_hr_raw` in `schema_inc.php`
immediately made the installer stop offering health's own pending 5.0.2 upgrade on srv9, and
dropped health from the requirements table — despite identical code to desktop. Desktop only
looked fine because the table already existed there from an earlier manual isql create (a sunk-
cost exception made before this was built properly), which happened to mask the exact bug now
found on srv9's clean state. Root cause is a framework-level `BitSystem::verifyInstalledPackages()`
quirk, not anything health-specific — full mechanism documented in `kernel/CLAUDE.md`'s matching
2026-08-23 entry.

**Fix applied here**: `health_hr_raw` pulled back out of `schema_inc.php` for now — it lives only
in `admin/upgrades/5.0.2.php` until every live site (srv9, then srv10) is confirmed upgraded to
5.0.2, at which point it goes back into `schema_inc.php` for future fresh installs. Lester caught
my first attempt at documenting this — the fix landed with a comment explaining the framework
mechanism inline in health's own `schema_inc.php`, which isn't the right home for a bug that isn't
health's; trimmed to a one-line pointer at kernel's entry instead.

## 2026-08-23 (later) — staged per-year `HEALTH_HR_RAW` import, and two real bugs it surfaced

A full-history `load_hr_raw.php` run in one pass proved too slow (the earlier session's attempt
paused at 722,633 rows). Moved to a staged, one-year-at-a-time approach instead — desktop first,
srv9 to follow once desktop's numbers look right.

**`?year=YYYY` option added to `load_hr_raw.php`**: points at `storage/health/history/YYYY/`
instead of the flat `storage/health/`. Either HR source being entirely absent for a year (2024 has
no background `tracker.heart_rate` data at all) is expected, not an error — only aborts if both are
missing.

**Two real bugs found testing the 2024 chunk, both in `split_by_year.py`, not the importer**:
1. `split_blobs()` copied every blob flat into `jsons/<type>/`, discarding the single-char bucket
   (by the blob filename's own first character) that `healthLoadBinningData()` — and everything
   else reading these blobs — actually expects. Silently produced zero HR samples for all 158 of
   2024's exercise sessions before this was caught. Fixed by re-bucketing on the way out.
2. The Samsung export's own device-setup placeholder rows (4 `blood_pressure` rows dated before
   the real 2024-06-29 phone-acquisition date) were landing in a spurious `2023` year folder —
   harmless at import time (`ImportBPSamsung.php` already excludes them) but noise in the archive.
   Dropped at split time now, matching HealthForYou's own split output, which never had this
   problem (its own export range only ever started from the real acquisition date).

**Also dropped the export-date suffix** from `split_by_year.py`'s output filenames (`com.samsung.
shealth.exercise.csv`, not `...20260814090949.csv`) — each year folder holds exactly one file per
type, nothing to disambiguate, matching HealthForYou's split which never had a suffix either.
`healthFindLatestPulseCsv()`/`healthFindLatestSamsungCsv()` now check for the plain name first,
falling back to the suffixed-glob-latest logic unchanged for the flat `storage/health/` case, where
multiple export batches can genuinely coexist.

**2024 confirmed a genuine dead end, not a bug**: once the bucket fix was in, all 158 exercise
sessions parsed correctly but had no `heart_rate` field at all in their `live_data` — the watch
simply wasn't recording HR during exercise that far back. Combined with zero background coverage,
2024 contributes nothing to `HEALTH_HR_RAW` either way.

**2025 run (the real first result)**: 1,436,899 rows — 123,272 background (2025-03-16 through
2025-12-31), 1,313,627 exercise (2025-03-15 through 2025-12-31). **292 distinct days with data out
of 292 days in that range — no day-level gaps.** Monthly background runs a steady ~12-14k rows
(~6-7 hrs/day of continuous tracking); exercise ranges from 59k (partial March) to 169k (October,
the busiest month) — nothing anomalous in either.

Hit nginx's `fastcgi_read_timeout` (300s, set earlier for food's importers) partway through the
2025 run — confirmed via the still-running php-fpm worker and a climbing row count that this only
killed the *browser's* connection, not the import itself (`set_time_limit(0)` plus no output
flushed until the very end means php-fpm keeps going regardless). Bumped to 1800s on desktop's
`nginx-desktop/vhosts.d/24.local-rdmcloud.vhosts.conf` anyway, purely so the next staged run
doesn't produce a misleading "did it hang?" moment.

**`stage_history_year.sh` added** (`health/import/`): automates what had been three rounds of
manual `cp`/`chown` (2024, 2025, 2026) — finds the newest matching `health_lester_*_by_year/YYYY`
and `healthforyou_lester_*_by_year/YYYY`, wipes and replaces just that year's
`storage/health/history/YYYY/`, fixes ownership. Built because Samsung's own export is always a
fresh full-history dump, never incremental — Lester's own framing: "the next download will be a
complete set again, everything there already + then extra days." That means `split_by_year.py`'s
rebuilt-from-scratch `<year>/` folder is already the complete, current archive for that year after
every new download, with no merge/diff logic needed — re-running `split_health.sh` +
`split_by_year.py` (+ the HealthForYou equivalents) against the new export, then this script, then
`load_hr_raw.php?year=YYYY` again (safe/idempotent via `START_TIME`'s own PK) is the whole update
workflow. Going forward, the flat `storage/health/` top level is meant to hold just the newest
download for the other (non-year-staged) importers, not double as "this year's data" — 2024/2025
staged from the existing `20260814090949` export, 2026 likewise (14,813 background rows, 482
exercise sessions, partial year so far), all three verified identical whether staged by hand or via
the new script.
