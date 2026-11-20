#!/bin/sh
# Two PHP workers compete to reserve the same last units (production_order).
set -eu
ROOT=/app
ARGS1="$1"
ARGS2="$2"
OUT1=$(mktemp)
OUT2=$(mktemp)
php "$ROOT/tests/harness/wms_e2e_runner.php" reserve "$ARGS1" >"$OUT1" 2>/tmp/wms_e1 &
PID1=$!
php "$ROOT/tests/harness/wms_e2e_runner.php" reserve "$ARGS2" >"$OUT2" 2>/tmp/wms_e2 &
PID2=$!
wait $PID1 || true
wait $PID2 || true
echo "{\"ok\":true,\"out1\":$(cat "$OUT1" | tail -n 1),\"out2\":$(cat "$OUT2" | tail -n 1)}"
rm -f "$OUT1" "$OUT2"
