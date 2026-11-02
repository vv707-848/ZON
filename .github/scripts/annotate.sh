#!/usr/bin/env bash
# Puts the end of a log into one annotation on the run, so a failure or a report reads from the run's page
# (and through the API) without opening the raw logs.
#
#   annotate.sh <error|warning|notice> <title> <file> [lines=80]
#
# The title must not contain a comma or a colon (they delimit annotation properties).
set -euo pipefail

level=$1
title=$2
file=$3
lines=${4:-80}

[ -s "$file" ] || exit 0

body=$(tail -n "$lines" "$file" \
  | sed -E 's/\x1B\[[0-9;]*[A-Za-z]//g' \
  | sed -e 's/%/%25/g' -e 's/\r/%0D/g' \
  | awk 'BEGIN { ORS = "%0A" } { print }')

printf '::%s title=%s::%s\n' "$level" "$title" "$body"
