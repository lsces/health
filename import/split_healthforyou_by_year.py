#!/usr/bin/env python3
"""
Split a healthforyou_lester_<date>/ folder (produced by split_healthforyou.py)
by year, using each row's Date column (always column 0, DD/MM/YYYY) - the
HealthForYou equivalent of split_by_year.py for Samsung Health.

Only the 4 canonical section files (weight.csv, blood_pressure.csv,
pulse_oximeter.csv, temperature.csv) are split - HealthForYou has no
per-record files/jsons blobs to worry about, unlike Samsung Health, and
weight_daily.csv (extract_weight_daily.py's own derived output, not part of
the raw split) is left alone.

Read-only on its inputs - only ever writes a new "<dir>_by_year" tree, fully
rebuilt on every run.
"""
import csv
import shutil
import sys
from pathlib import Path

BASE = Path("/home/lester/Personal/Health/HealthForYouApp")

SECTION_FILES = ["weight.csv", "blood_pressure.csv", "pulse_oximeter.csv", "temperature.csv"]


def split_csv(csvfile: Path, out_dir: Path):
    writers = {}  # year -> (file handle, csv.writer)
    row_count = 0

    with open(csvfile, newline="", encoding="utf-8-sig") as f:
        reader = csv.reader(f, delimiter=";")
        header = next(reader)
        for row in reader:
            if not row or not row[0].strip():
                continue
            row_count += 1
            date = row[0].strip()
            parts = date.split("/")
            year = parts[2] if len(parts) == 3 else "unknown"

            if year not in writers:
                ydir = out_dir / year
                ydir.mkdir(parents=True, exist_ok=True)
                fh = open(ydir / csvfile.name, "w", newline="", encoding="utf-8")
                w = csv.writer(fh, delimiter=";")
                w.writerow(header)
                writers[year] = (fh, w)
            writers[year][1].writerow(row)

    for fh, _ in writers.values():
        fh.close()

    return row_count, sorted(writers.keys())


def process_dir(src_dir: Path):
    csv_files = [src_dir / name for name in SECTION_FILES if (src_dir / name).exists()]
    if not csv_files:
        return

    out_dir = src_dir.parent / (src_dir.name + "_by_year")
    if out_dir.exists():
        shutil.rmtree(out_dir)
    out_dir.mkdir(parents=True)

    print(f"=== {src_dir.name} -> {out_dir.name} ===")
    for csvfile in csv_files:
        row_count, years = split_csv(csvfile, out_dir)
        print(f"  {csvfile.name}: {row_count} rows -> {years}")


def main():
    only = sys.argv[1] if len(sys.argv) > 1 else None
    for src in sorted(BASE.glob("healthforyou_lester_*")):
        if src.name.endswith("_by_year") or not src.is_dir():
            continue
        if only and only not in src.name:
            continue
        process_dir(src)


if __name__ == "__main__":
    main()
