# Health

A [Bitweaver](https://github.com/lsces/bitweaver) package for tracking vitals, sleep, and
activity — built to import a full **Samsung Health** (and, optionally, **HealthForYou**) export
and turn it into your own queryable, day-based health record, instead of leaving that data locked
inside Samsung's app. Companion to the [`food`](https://github.com/lsces/food) package (same
Samsung Health export, same underlying approach) — Food covers the food/nutrition slice of the
export, Health covers everything else.

**Status: active development, personal use.** Built primarily for the author's own day-to-day
tracking. Shared here in case it's useful to other Samsung Health users who'd like to own a copy
of their own health data, not as a finished product yet.

## Why this exists

Samsung Health has no API — the only way to get your data out is a manual full export from the
app. Once it's out, it's a pile of CSVs and JSON blobs, not something you can browse, query, or
put on paper for a doctor's appointment. This package imports it into Bitweaver, where every
reading becomes normal content attached to the day it happened — browsable, reportable, and
archivable when a bad reading needs flagging rather than silently trusted forever.

## What it imports

Every day gets one `HealthDay` record; every reading/session attaches to that day. From
**HealthForYou** (cuff/probe-sourced): weight, blood pressure, pulse-oximeter, temperature. From
**Samsung Health** (phone/watch-sourced, far broader): weight and blood pressure again (both
cuff-synced and watch-PPG, kept alongside the HealthForYou source rather than merged blindly),
half-hour-slot heart rate/respiratory rate/skin temperature/HRV, daily steps/energy/step-trend
summaries, sleep sessions, exercise sessions (type, duration, calories, distance, heart rate), and
the raw per-second heart-rate trace behind all of it. Every raw reading is imported as its own
row, not reduced to a daily average at import time — a day-summary rollup is a query-time concern,
not something baked in during import.

**Not imported yet**: ECG report PDFs (Samsung Health exports these as real files, not CSV/JSON —
planned as a gallery in the [`fisheye`](https://github.com/lsces/fisheye) package instead of
bespoke handling here, not started).

## What's built so far

- `HealthDay` content type — the day-based model everything else attaches to
- Fourteen reading/session item types across vitals, activity, sleep, HRV, and exercise — see
  `MANUAL.md` for the full roster and exactly what each one stores
- A raw-data browser (per item type, across every day) with an archive/history mechanism — a
  rogue reading can be tagged out of the normal view rather than deleted or silently trusted
- A day view and a calendar-grid tile summary for quick browsing
- Printable range and blood-pressure-detail reports — built specifically to have something useful
  on paper for a doctor's appointment
- Single-pass upload for both HealthForYou and Samsung Health exports — additive and safe to
  re-run against a newer export; only genuinely new rows/sessions get imported
- Staged import for the raw heart-rate trace (millions of rows across a full history) — one year
  at a time, safe to re-run

## What's planned

- ECG PDF handling via a `fisheye` gallery (not scoped yet — no validated real ECG reading to
  build/test against)
- The real day-summary rollup logic (currently a simple min/max/average placeholder)

See `MANUAL.md` for the full current picture — schema, import architecture, and a more complete
"known gaps" list than the summary above.

## Requirements

- [Bitweaver](https://github.com/lsces/bitweaver) 5.x
- [`liberty`](https://github.com/lsces/liberty) package (≥ 5.0.2) — built entirely on Liberty's
  generic content/xref framework, same foundation [`stock`](https://github.com/lsces/stock) and
  [`food`](https://github.com/lsces/food) use
- A Samsung Health data export (Settings → your account → Download personal data, inside the
  Samsung Health app) and/or a HealthForYou export, if you have one

## Getting your data in

1. Export your data from Samsung Health (full export, no partial/date-range option exists).
2. Use the package's "Upload Export" button to upload the raw `.tar.gz` directly — it extracts,
   archives, and imports everything it recognises in one pass. Re-running it against a newer
   export later is safe: only genuinely new rows get imported.
3. The raw heart-rate trace (`HEALTH_HR_RAW`) is large enough that it's staged and imported one
   year at a time separately, rather than as part of the single upload — see `MANUAL.md` for the
   current process.

Since this package isn't through a stable install/upgrade cycle yet, see `MANUAL.md` in this repo
for the current schema-deployment approach if you're installing it fresh (`CLAUDE.md` is a dated
development log, not a reference — useful for *why* something's built the way it is, not *how* to
set it up).
