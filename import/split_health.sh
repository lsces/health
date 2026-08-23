#!/bin/bash
set -euo pipefail

BASE="/home/lester/Personal/Health/Samsung Health"

FOOD_TYPES="com.samsung.health.food_info com.samsung.health.food_intake com.samsung.health.nutrition com.samsung.health.water_intake com.samsung.shealth.food_favorite com.samsung.shealth.food_frequent com.samsung.shealth.food_goal"

MISC_TYPES="com.samsung.health.device_profile com.samsung.health.user_profile com.samsung.shealth.badge com.samsung.shealth.insight_message com.samsung.shealth.insight.message_notification com.samsung.shealth.preferences com.samsung.shealth.service_preferences com.samsung.shealth.shm_device com.samsung.shealth.social.service_status com.samsung.shealth.report"

is_in() {
  local needle="$1"; shift
  for x in $@; do [ "$x" = "$needle" ] && return 0; done
  return 1
}

for archive in "$BASE"/samsunghealth_lester_*; do
  [ -d "$archive" ] || continue
  archname=$(basename "$archive")
  suffix=${archname#samsunghealth_lester_}
  fooddir="$BASE/food_lester_$suffix"
  healthdir="$BASE/health_lester_$suffix"
  mkdir -p "$fooddir" "$healthdir"

  food_n=0; health_n=0; misc_n=0

  # CSVs live flat in the archive root
  while IFS= read -r -d '' csvfile; do
    fname=$(basename "$csvfile")
    # strip trailing .<digits>.csv to get the bare type
    type=$(echo "$fname" | sed -E 's/\.[0-9]+\.csv$//')
    if is_in "$type" $FOOD_TYPES; then
      cp -p "$csvfile" "$fooddir/"
      food_n=$((food_n+1))
    elif is_in "$type" $MISC_TYPES; then
      misc_n=$((misc_n+1))
    else
      cp -p "$csvfile" "$healthdir/"
      health_n=$((health_n+1))
    fi
  done < <(find "$archive" -maxdepth 1 -name "*.csv" -print0)

  # files/ and jsons/ subfolders, keyed by type name
  for sub in files jsons; do
    [ -d "$archive/$sub" ] || continue
    for typedir in "$archive/$sub"/*/; do
      [ -d "$typedir" ] || continue
      type=$(basename "$typedir")
      if is_in "$type" $FOOD_TYPES; then
        mkdir -p "$fooddir/$sub"
        cp -pr "$typedir" "$fooddir/$sub/"
      elif is_in "$type" $MISC_TYPES; then
        :
      else
        mkdir -p "$healthdir/$sub"
        cp -pr "$typedir" "$healthdir/$sub/"
      fi
    done
  done

  echo "$archname -> food:$food_n health:$health_n dropped-misc:$misc_n"
done
