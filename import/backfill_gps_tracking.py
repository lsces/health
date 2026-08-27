#!/usr/bin/env python3
"""
One-off backfill: copy GPS-trace blobs (exercise/*.location_data*.json) from
the full historical Samsung export into /home/media1/Maps/_tracking/<year>/
- the same additive, skip-existing copy ImportSamsung.php's own
samsungArchiveBlob() does for every new upload going forward, but this
export predates that mechanism (its CSV/DB data was staged via the older
manual split_health.sh/split_by_year.py/stage_history_year.sh pipeline, which
never touched GPS traces at all), so the historical backlog needs this one
manual pass. Never needs re-running once done - future uploads pick this up
automatically via the upload button.

Same year-bucketing rule as split_by_year.py: start_time's own year,
PHONE_ACQUIRED cutoff for placeholder rows, matched via each exercise
session's datauuid.

Usage: ./backfill_gps_tracking.py <samsunghealth_export_dir>
"""
import csv
import re
import shutil
import sys
from pathlib import Path

EXERCISE_CSV_RE = re.compile(r"^com\.samsung\.shealth\.exercise\.\d+\.csv$")

TRACKING_PATH = Path("/home/media1/Maps/_tracking")
PHONE_ACQUIRED = "2024-06-29"
GPS_MARKERS = ("location_data.json", "location_data_internal.json")


def find_col(header, name):
    for i, col in enumerate(header):
        if col == name or col.endswith("." + name):
            return i
    return None


def main():
    if len(sys.argv) < 2:
        print(__doc__)
        sys.exit(1)
    export_dir = Path(sys.argv[1])
    csv_file = next( ( f for f in export_dir.glob("com.samsung.shealth.exercise.*.csv") if EXERCISE_CSV_RE.match(f.name) ), None )
    if not csv_file:
        print(f"no exercise CSV found in {export_dir}")
        sys.exit(1)
    json_dir = export_dir / "jsons" / "com.samsung.shealth.exercise"
    if not json_dir.is_dir():
        print(f"no exercise jsons dir found in {export_dir}")
        sys.exit(1)

    uuid_year = {}
    with open(csv_file, encoding="utf-8-sig") as f:
        f.readline()  # preamble
        header = next(csv.reader([f.readline()]))
        start_idx = find_col(header, "start_time")
        uuid_idx = find_col(header, "datauuid")
        for row in csv.reader(f):
            if not row or start_idx is None or uuid_idx is None:
                continue
            date_str = row[start_idx][:10] if start_idx < len(row) else None
            if not date_str or date_str < PHONE_ACQUIRED:
                continue
            duuid = row[uuid_idx] if uuid_idx < len(row) else None
            if duuid:
                uuid_year[duuid] = date_str[:4]

    copied = 0
    skipped_existing = 0
    unmatched = 0
    for blob in json_dir.rglob("*"):
        if not blob.is_file():
            continue
        if not any(blob.name.endswith(m) for m in GPS_MARKERS):
            continue
        duuid = blob.name.split(".", 1)[0]
        year = uuid_year.get(duuid)
        if not year:
            unmatched += 1
            continue
        dest_dir = TRACKING_PATH / year / blob.name[0]
        dest_dir.mkdir(parents=True, exist_ok=True)
        dest = dest_dir / blob.name
        if dest.exists():
            skipped_existing += 1
            continue
        shutil.copy2(blob, dest)
        copied += 1

    print(f"copied: {copied}, already present: {skipped_existing}, unmatched datauuid: {unmatched}")


if __name__ == "__main__":
    main()
