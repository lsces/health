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
