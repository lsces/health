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

Samsung Health's export is much broader than what's listed above — see "What's not imported" below
for the full picture of what's left out and why.

## What's built so far

- `HealthDay` content type — the day-based model everything else attaches to
- Fifteen reading/session item types across vitals, activity, sleep, HRV, and exercise — see
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

## What's not imported

A full Samsung Health export contains far more than the fifteen item types this package builds
from. Some of what's left out is deliberate (device config, goal-setting, redundant detail); some
is real data that just hasn't been built yet. If you're relying on this package for a complete
copy of your own data, this is the honest gap list — not everything the export contains ends up
queryable yet.

**Real physiological data not captured yet**
- **ECG report PDFs** (`files/com.samsung.health.ecg/*.data.pdf`) — Samsung exports these as real
  PDF files, not CSV/JSON. Planned as a gallery in the
  [`fisheye`](https://github.com/lsces/fisheye) package rather than bespoke handling here — not
  started.
- **Sleep stage detail** (`com.samsung.health.sleep_stage` — light/deep/REM/awake breakdown) —
  only the session-level score, duration, and efficiency are imported, not the stage timeline.
- **Stress score and its intraday histogram** (`com.samsung.shealth.stress` /
  `stress.histogram`) — not imported at all currently.
- **Per-night SpO2 detail beyond the low-point summary** — the oxygen-saturation item stores
  minutes spent below 90%/85%/80% and the night's low point, but not the full per-minute trace
  behind it; the plain SpO2 item stores only the session average.
- **Skin-scan metrics** (`com.samsung.health.advanced_glycation_endproduct`, `.antioxidant`) and
  **core body temperature** (`com.samsung.health.body_temperature`, a distinct field from the
  skin-temperature one that is imported) — not imported.
- **Floors climbed** (`com.samsung.health.floors_climbed` /
  `com.samsung.shealth.tracker.floors_day_summary`) — not imported.
- **Exercise sub-detail**: heart-rate zones, recovery heart rate, and per-session max heart rate
  (`com.samsung.shealth.exercise.hr_zone`, `.recovery_heart_rate`, `.max_heart_rate`) — the
  session itself is imported with its own average/min/max heart rate, but not this finer detail.
- **GPS traces from exercise sessions** — copied out to a shared location store for the
  [`mapper`](https://github.com/lsces/mapper) package rather than imported here, but nothing
  currently displays them yet.

**Deliberately out of scope**
- Device/app configuration, notification/insight noise, badges, and goal-setting data (step
  goals, active-calorie goals, personal-record tracking, activity-level classification, walking
  recommendations, etc.) — not health readings, no value in importing.
- High-volume raw sensor dumps too fine-grained to store as individual rows at any reasonable
  scale (`movement`, `pedometer_step_count`, `sleep_raw_data`) — archived as raw files on import,
  not decomposed into the database.
- The raw oxygen-saturation PPG waveform (`com.samsung.health.oxygen_saturation.raw`) — inspected
  directly and found to be unusable noise at the sample level, not a real signal.
- Samsung's own phone/watch weight readings (`com.samsung.health.weight`) — weight currently only
  imports HealthForYou's cuff/scale-sourced readings; a redundant Samsung-side source hasn't been
  added.

Every raw CSV and JSON file from an uploaded export is archived (`storage/health/history/<year>/`)
whether or not there's a database importer for it yet — nothing is discarded on upload, so any of
the above can be added later without needing a fresh export.

## What's planned

- ECG PDF handling via a `fisheye` gallery (not scoped yet — no validated real ECG reading to
  build/test against)
- The real day-summary rollup logic (currently a simple min/max/average placeholder)

See `MANUAL.md` for the full current picture — schema, import architecture, and the build/process
gaps (as opposed to data-coverage gaps, covered above).

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
