#!/usr/bin/env python3
"""
Split a HealthForYouApp_DataExport CSV into one CSV per section (Weight,
Blood pressure, Pulse Oximeter, Temperature, ...), mirroring what
split_health.sh does for the Samsung export.

Input files (dropped by hand into BASE below) look like
"HealthForYouApp_DataExport (N).csv" and are a single CSV containing a
preamble (user details / time period / selected categories) followed by one
block per section: a bare title line (all caps), a header line
(semicolon-delimited, ends "Added manually"), then data rows, newest first,
until the next blank line.

Output goes to a sibling dir under BASE named after the export's own "To"
date (the end of the selected range), e.g. healthforyou_lester_20260822/
weight.csv - matching Samsung Health's health_lester_<date>/ naming
convention. Re-run any time a new dated export lands; each export's own
To-date keeps outputs from separate exports apart.

New section titles showing up in a future export (Lester selected extra
categories, or Samsung/HealthForYou add a new metric type) need adding to
SECTION_FILES below - unrecognised section titles are skipped with a warning
rather than silently dropped.

See also split_healthforyou_by_year.py, which further splits an already-split
folder's 4 section CSVs by year - use once a year's worth of data needs
archiving separately from the ongoing current-year export.
"""
import csv
import glob
import os
import re
import sys

BASE = "/home/lester/Personal/Health/HealthForYouApp"

SECTION_FILES = {
    "PULSE OXIMETER": "pulse_oximeter.csv",
    "TEMPERATURE": "temperature.csv",
    "WEIGHT": "weight.csv",
    "BLOOD PRESSURE": "blood_pressure.csv",
}


def parse_to_date(lines):
    for i, line in enumerate(lines):
        if line.strip() == "To" or line.startswith("To;"):
            parts = line.rstrip("\n").split(";")
            if len(parts) == 2:
                d, m, y = parts[1].split("/")
                return f"{y}{m}{d}"
    return None


def split_file(path):
    with open(path, encoding="utf-8-sig") as fh:
        lines = fh.readlines()

    to_date = parse_to_date(lines)
    if not to_date:
        print(f"skip {os.path.basename(path)}: no 'To;<date>' line found", file=sys.stderr)
        return

    outdir = os.path.join(BASE, f"healthforyou_lester_{to_date}")
    os.makedirs(outdir, exist_ok=True)

    i = 0
    n = len(lines)
    counts = {}
    while i < n:
        title = lines[i].strip()
        if title in SECTION_FILES and i + 1 < n and ";" in lines[i + 1]:
            header = lines[i + 1].rstrip("\n").split(";")
            rows = []
            j = i + 2
            while j < n and lines[j].strip() != "":
                rows.append(lines[j].rstrip("\n").split(";"))
                j += 1
            outfile = os.path.join(outdir, SECTION_FILES[title])
            with open(outfile, "w", newline="", encoding="utf-8") as out:
                w = csv.writer(out, delimiter=";")
                w.writerow(header)
                w.writerows(rows)
            counts[title] = len(rows)
            i = j
        else:
            i += 1

    summary = " ".join(f"{k}:{v}" for k, v in counts.items()) or "(no recognised sections found)"
    print(f"{os.path.basename(path)} -> {os.path.basename(outdir)}/ {summary}")


def main():
    for path in sorted(glob.glob(os.path.join(BASE, "HealthForYouApp_DataExport*.csv"))):
        split_file(path)


if __name__ == "__main__":
    main()
