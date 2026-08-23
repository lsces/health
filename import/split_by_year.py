#!/usr/bin/env python3
"""
Re-split the health_lester_*/food_lester_* folders (produced by split_health.sh)
into per-year archives, keyed by each row's start_time (the actual event date,
not create_time which is just when the record was saved to the app).

Read-only on its inputs - only ever writes to new "<dir>_by_year" output trees,
never touches health_lester_*/food_lester_* or the raw samsunghealth_lester_*
archives.

CSV rows go straight into <type>.csv under the matching year. The files/ and
jsons/ per-record blob folders (named <datauuid>.<blobtype>.json, bucketed by
Samsung into meaningless numbered hash folders) are re-bucketed by year instead,
using the datauuid -> year map built while splitting that type's own CSV -
but still re-bucketed one level deeper by the blob filename's own first
character (e.g. jsons/<type>/f/f8e3e023-....json), since that's the
convention ImportHRRaw.php's healthLoadBinningData() (and everything else
reading these blobs) actually expects - dropping it entirely, as this script
used to, silently produced zero matches for every blob in a year split.

Re-run any time after split_health.sh has been (re-)run for a new export - each
"<dir>_by_year" output is rebuilt from scratch, so it's always a clean, current
reflection of its source.
"""
import csv
import re
import shutil
import sys
from pathlib import Path

BASE = Path("/home/lester/Personal/Health/Samsung Health")

TYPE_SUFFIX_RE = re.compile(r"^(.*)\.\d+\.csv$")

# Confirmed real phone-acquisition date (see health/CLAUDE.md's HealthForYou 2024 backfill
# entry) - anything dated earlier is device-setup placeholder noise, not real data (e.g. the
# 4 blood_pressure rows that used to land in a spurious "2023" year folder here). Dropped
# entirely at split time rather than filtered later, so the archive output stays as clean as
# HealthForYou's own split already is - HFY's export never had this problem since its own
# date range only ever started from the real acquisition date.
PHONE_ACQUIRED = "2024-06-29"


def find_col(header, name):
    for i, col in enumerate(header):
        if col == name or col.endswith("." + name):
            return i
    return None


def type_name_from_csv(csvfile: Path) -> str:
    m = TYPE_SUFFIX_RE.match(csvfile.name)
    return m.group(1) if m else csvfile.stem


def split_csv(csvfile: Path, out_dir: Path):
    """Split one CSV into per-year copies. Returns (typename, {datauuid: year}, row_count)."""
    typename = type_name_from_csv(csvfile)
    uuid_year = {}
    writers = {}  # year -> (file handle, csv.writer)
    row_count = 0

    with open(csvfile, newline="", encoding="utf-8-sig") as f:
        preamble = f.readline().rstrip("\n")
        header_line = f.readline().rstrip("\n")
        header = next(csv.reader([header_line]))
        start_idx = find_col(header, "start_time")
        if start_idx is None:
            start_idx = find_col(header, "create_time")
        uuid_idx = find_col(header, "datauuid")

        reader = csv.reader(f)
        for row in reader:
            if not row:
                continue
            row_count += 1
            date_str = None
            if start_idx is not None and start_idx < len(row) and row[start_idx]:
                date_str = row[start_idx][:10]
            if date_str is not None and date_str < PHONE_ACQUIRED:
                continue  # pre-acquisition placeholder noise, not real data - drop entirely
            year = date_str[:4] if date_str else "unknown"
            if uuid_idx is not None and uuid_idx < len(row) and row[uuid_idx]:
                uuid_year[row[uuid_idx]] = year

            if year not in writers:
                ydir = out_dir / year
                ydir.mkdir(parents=True, exist_ok=True)
                fh = open(ydir / csvfile.name, "w", newline="", encoding="utf-8")
                fh.write(preamble + "\n")
                w = csv.writer(fh)
                w.writerow(header)
                writers[year] = (fh, w)
            writers[year][1].writerow(row)

    for fh, _ in writers.values():
        fh.close()

    return typename, uuid_year, row_count, sorted(writers.keys())


def split_blobs(typedir: Path, typename: str, uuid_year: dict, sub: str, out_dir: Path):
    moved = 0
    unknown = 0
    for blobfile in typedir.rglob("*"):
        if not blobfile.is_file():
            continue
        duuid = blobfile.name.split(".", 1)[0]
        year = uuid_year.get(duuid, "unknown")
        if year == "unknown":
            unknown += 1
        dest_dir = out_dir / year / sub / typename / blobfile.name[0]
        dest_dir.mkdir(parents=True, exist_ok=True)
        shutil.copy2(blobfile, dest_dir / blobfile.name)
        moved += 1
    return moved, unknown


def process_dir(src_dir: Path):
    out_dir = src_dir.parent / (src_dir.name + "_by_year")
    csv_files = sorted(src_dir.glob("*.csv"))
    if not csv_files:
        return

    if out_dir.exists():
        shutil.rmtree(out_dir)
    out_dir.mkdir(parents=True)

    print(f"=== {src_dir.name} -> {out_dir.name} ===")
    type_uuid_maps = {}
    for csvfile in csv_files:
        typename, uuid_year, row_count, years = split_csv(csvfile, out_dir)
        type_uuid_maps[typename] = uuid_year
        print(f"  {csvfile.name}: {row_count} rows -> {years}")

    for sub in ("files", "jsons"):
        subdir = src_dir / sub
        if not subdir.is_dir():
            continue
        for typedir in sorted(subdir.iterdir()):
            if not typedir.is_dir():
                continue
            typename = typedir.name
            uuid_year = type_uuid_maps.get(typename, {})
            moved, unknown = split_blobs(typedir, typename, uuid_year, sub, out_dir)
            note = f" ({unknown} unmatched -> unknown)" if unknown else ""
            print(f"  {sub}/{typename}: {moved} blobs{note}")


def main():
    only = sys.argv[1] if len(sys.argv) > 1 else None
    for kind in ("health", "food"):
        for src in sorted(BASE.glob(f"{kind}_lester_*")):
            if src.name.endswith("_by_year"):
                continue
            if only and only not in src.name:
                continue
            process_dir(src)


if __name__ == "__main__":
    main()
