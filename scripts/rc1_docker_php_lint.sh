#!/usr/bin/env bash
# Batch php -l for release_gate when host PHP CLI is absent.
# Expects LF file list at .rc1_php_lint_list.txt (paths relative to /app).
set -eu
failed=0
list=".rc1_php_lint_list.txt"
if [ ! -f "$list" ]; then
  echo "missing $list" >&2
  exit 2
fi
# Strip CR so Windows-authored lists still work.
while IFS= read -r f || [ -n "$f" ]; do
  f="${f%$'\r'}"
  [ -z "$f" ] && continue
  if [ ! -f "$f" ]; then
    echo "missing:$f" >&2
    failed=1
    continue
  fi
  php -l "$f" || failed=1
done < "$list"
if [ -f tests/php_live_observability_test.php ]; then
  php -n tests/php_live_observability_test.php || failed=1
fi
exit "$failed"
