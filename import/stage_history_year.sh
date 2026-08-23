#!/bin/bash
# Stage one year's already-split archive data into the live site's
# storage/health/history/<year>/ - the shape load_hr_raw.php?year=YYYY (and
# any future per-year importer) expects. See health/CLAUDE.md's 2026-08-23
# staged-import entries for why this exists: Samsung's own export is always
# a fresh full history dump, not incremental, so split_by_year.py's rebuilt-
# from-scratch <year>/ folder is already the complete, up-to-date archive
# for that year after every new download - this script just re-copies it
# (and the matching HealthForYou year, if any) onto the live site.
#
# Safe to re-run any time after a new download + split_health.sh +
# split_by_year.py (+ split_healthforyou.py/split_healthforyou_by_year.py)
# pass: picks the newest matching _by_year export automatically, wipes and
# replaces just this year's storage/health/history/<year>/ (never touches
# other years), and load_hr_raw.php's own PK-dedup means re-running the
# import afterward only ever adds whatever's actually new.
#
# Usage: ./stage_history_year.sh 2026
set -euo pipefail

YEAR="${1:?usage: stage_history_year.sh YYYY}"
BASE="/home/lester/Personal/Health/Samsung Health"
HFY_BASE="/home/lester/Personal/Health/HealthForYouApp"
DEST="/srv/website/rdmcloud/storage/health/history/$YEAR"

# Newest health_lester_*_by_year containing this year (mtime - split_by_year.py
# rebuilds its whole output tree on every run, so mtime reflects "last split", not
# export date).
samsung_src=$(ls -dt "$BASE"/health_lester_*_by_year/"$YEAR" 2>/dev/null | head -1)
if [ -z "$samsung_src" ]; then
	echo "No health_lester_*_by_year/$YEAR found - run split_health.sh + split_by_year.py first" >&2
	exit 1
fi

hfy_src=$(ls -dt "$HFY_BASE"/healthforyou_lester_*_by_year/"$YEAR" 2>/dev/null | head -1)

echo "Samsung source: $samsung_src"
echo "HealthForYou source: ${hfy_src:-(none for $YEAR)}"
echo "Destination: $DEST"

sudo rm -rf "$DEST"
sudo cp -r "$samsung_src" "$DEST"

if [ -n "$hfy_src" ]; then
	for f in blood_pressure pulse_oximeter temperature weight; do
		[ -f "$hfy_src/$f.csv" ] && sudo cp "$hfy_src/$f.csv" "$DEST/healthforyou_$f.csv"
	done
fi

sudo chown -R nginx:nginx "$DEST"
echo "Done."
