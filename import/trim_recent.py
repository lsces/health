#!/usr/bin/env python3
"""
Trim a raw samsunghealth_lester_<date>/ export down to just the last N days,
keeping the same combined flat layout (CSVs + files/ + jsons/) rather than
splitting into food_*/health_* - this is meant to feed the still-unbuilt
Samsung single-pass importer (mirrors the HealthForYou upload button's own
single-pass shape), which needs to see the same raw combined structure as a
real download, plus it's a much smaller set to actually import from directly
today rather than the full historical dump.

Read-only on its input - only ever writes the new "<archive>_trimmed" dir,
rebuilt from scratch each run. Drops the same app/device-noise types
split_health.sh already drops (nothing lost that either package would want).

Usage: ./trim_recent.py <archive_dir_name> [days|YYYY-MM-DD]   # days default 14
"""
import csv
import shutil
import sys
from datetime import datetime, timedelta
from pathlib import Path

BASE = Path("/home/lester/Personal/Health/Samsung Health")

MISC_TYPES = {
    "com.samsung.health.device_profile", "com.samsung.health.user_profile",
    "com.samsung.shealth.badge", "com.samsung.shealth.insight_message",
    "com.samsung.shealth.insight.message_notification",
    "com.samsung.shealth.preferences", "com.samsung.shealth.service_preferences",
    "com.samsung.shealth.shm_device", "com.samsung.shealth.social.service_status",
    "com.samsung.shealth.report",
}


def find_col(header, name):
    for i, col in enumerate(header):
        if col == name or col.endswith("." + name):
            return i
    return None


def type_name_from_csv(csvfile: Path) -> str:
    name = csvfile.name
    parts = name.rsplit(".", 2)
    if len(parts) == 3 and parts[1].isdigit():
        return parts[0]
    return csvfile.stem


def trim_csv(csvfile: Path, out_dir: Path, cutoff: str):
    typename = type_name_from_csv(csvfile)
    if typename in MISC_TYPES:
        return typename, set(), 0, 0

    keep_uuids = set()
    total = 0
    kept = 0
    with open(csvfile, newline="", encoding="utf-8-sig") as f:
        preamble = f.readline().rstrip("\n")
        header_line = f.readline().rstrip("\n")
        header = next(csv.reader([header_line]))
        start_idx = find_col(header, "start_time")
        if start_idx is None:
            start_idx = find_col(header, "create_time")
        uuid_idx = find_col(header, "datauuid")

        out_rows = []
        for row in csv.reader(f):
            if not row:
                continue
            total += 1
            date_str = None
            if start_idx is not None and start_idx < len(row) and row[start_idx]:
                date_str = row[start_idx][:10]
            if date_str is None or date_str < cutoff:
                continue
            kept += 1
            if uuid_idx is not None and uuid_idx < len(row) and row[uuid_idx]:
                keep_uuids.add(row[uuid_idx])
            out_rows.append(row)

    if kept:
        out_dir.mkdir(parents=True, exist_ok=True)
        with open(out_dir / csvfile.name, "w", newline="", encoding="utf-8") as fh:
            fh.write(preamble + "\n")
            w = csv.writer(fh)
            w.writerow(header)
            w.writerows(out_rows)

    return typename, keep_uuids, total, kept


def trim_blobs(typedir: Path, keep_uuids: set, sub: str, typename: str, out_dir: Path):
    kept = 0
    for blobfile in typedir.rglob("*"):
        if not blobfile.is_file():
            continue
        duuid = blobfile.name.split(".", 1)[0]
        if duuid not in keep_uuids:
            continue
        dest_dir = out_dir / sub / typename / blobfile.name[0]
        dest_dir.mkdir(parents=True, exist_ok=True)
        shutil.copy2(blobfile, dest_dir / blobfile.name)
        kept += 1
    return kept


def main():
    if len(sys.argv) < 2:
        print(__doc__)
        sys.exit(1)
    archname = sys.argv[1]
    arg2 = sys.argv[2] if len(sys.argv) > 2 else "14"

    src = BASE / archname
    if not src.is_dir():
        print(f"not found: {src}")
        sys.exit(1)

    if "-" in arg2:
        cutoff = arg2
    else:
        cutoff = (datetime.now() - timedelta(days=int(arg2))).strftime("%Y-%m-%d")

    out_dir = BASE / (archname + "_trimmed")
    if out_dir.exists():
        shutil.rmtree(out_dir)
    out_dir.mkdir(parents=True)

    print(f"=== {archname} -> {out_dir.name} (cutoff {cutoff}) ===")
    type_uuid_maps = {}
    for csvfile in sorted(src.glob("*.csv")):
        typename, keep_uuids, total, kept = trim_csv(csvfile, out_dir, cutoff)
        if typename in MISC_TYPES:
            continue
        type_uuid_maps[typename] = keep_uuids
        print(f"  {csvfile.name}: {kept}/{total} rows kept")

    for sub in ("files", "jsons"):
        subdir = src / sub
        if not subdir.is_dir():
            continue
        for typedir in sorted(subdir.iterdir()):
            if not typedir.is_dir():
                continue
            typename = typedir.name
            if typename in MISC_TYPES:
                continue
            keep_uuids = type_uuid_maps.get(typename, set())
            if not keep_uuids:
                continue
            kept = trim_blobs(typedir, keep_uuids, sub, typename, out_dir)
            print(f"  {sub}/{typename}: {kept} blobs kept")

    total_size = sum(f.stat().st_size for f in out_dir.rglob("*") if f.is_file())
    print(f"=== done: {total_size / 1024 / 1024:.1f} MB ===")


if __name__ == "__main__":
    main()
