#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "$0")" && pwd)"
hash="$(printf '%s' 'https://quote-rate-fixture.invalid/:1' | sha1sum | cut -d ' ' -f 1)"
rate_file="$(php -r 'echo sys_get_temp_dir();')/kalicart-quote-${hash}.json"
results="$(mktemp)"
trap 'rm -f -- "$rate_file" "$results"' EXIT
rm -f -- "$rate_file"
seq 1 100 | xargs -P 20 -I '{}' php "$script_dir/quote-rate-worker.php" > "$results"
accepted="$(grep -c '^accepted$' "$results" || true)"
busy="$(grep -c '^429:quote_busy$' "$results" || true)"
limited="$(grep -c '^429:quote_rate_limited$' "$results" || true)"
other="$(grep -Evc '^(accepted|429:quote_busy|429:quote_rate_limited)$' "$results" || true)"
stored="$(php -r '$s=json_decode(file_get_contents($argv[1]), true); echo $s["count"] ?? "invalid";' "$rate_file")"
printf 'accepted=%s busy=%s limited=%s other=%s stored=%s total=%s\n' "$accepted" "$busy" "$limited" "$other" "$stored" "$(wc -l < "$results")"
test "$accepted" -le 30
test "$accepted" -eq "$stored"
test "$other" -eq 0
test "$(wc -l < "$results")" -eq 100
