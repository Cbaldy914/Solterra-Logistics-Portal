#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
MAP_FILE="$ROOT_DIR/ai-assistant/config/page-context-map.php"
AWK_FILE="$ROOT_DIR/scripts/sunny/extract_page_catalog.awk"

if [[ ! -f "$MAP_FILE" ]]; then
  echo "Missing map file: $MAP_FILE" >&2
  exit 1
fi
if [[ ! -f "$AWK_FILE" ]]; then
  echo "Missing awk parser: $AWK_FILE" >&2
  exit 1
fi

tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT

rg -l "include\s*'header\.php'|require_once\s*'header\.php'|include\(\s*'header\.php'\s*\)" "$ROOT_DIR"/*.php \
  | sed 's#.*/##' \
  | sed 's/\.php$//' \
  | tr '[:upper:]' '[:lower:]' \
  | sort -u > "$tmp_dir/header_pages.txt"

awk -f "$AWK_FILE" "$MAP_FILE" | cut -d'|' -f1 | sort -u > "$tmp_dir/map_pages.txt"

# Known contexts intentionally modeled although they are wrappers/indirect routes.
printf '%s\n' \
  'manage_deliveries' \
  'manage_pallets' \
  > "$tmp_dir/allow_extra.txt"

cat "$tmp_dir/header_pages.txt" "$tmp_dir/allow_extra.txt" | sort -u > "$tmp_dir/expected_pages.txt"

comm -23 "$tmp_dir/header_pages.txt" "$tmp_dir/map_pages.txt" > "$tmp_dir/missing_in_map.txt"
comm -13 "$tmp_dir/expected_pages.txt" "$tmp_dir/map_pages.txt" > "$tmp_dir/unexpected_in_map.txt"

# Alias target validity check
rg "^\s{8}'[a-z0-9_]+'\s*=>\s*'[^']+'" -o "$MAP_FILE" \
  | sed -E "s/.*'([a-z0-9_]+)'\s*=>\s*'([a-z0-9_]+)'.*/\1|\2/" > "$tmp_dir/aliases.txt"

: > "$tmp_dir/invalid_alias_targets.txt"
while IFS='|' read -r _alias target; do
  [[ -z "$target" ]] && continue
  if ! rg -q "^${target}$" "$tmp_dir/map_pages.txt"; then
    echo "$target" >> "$tmp_dir/invalid_alias_targets.txt"
  fi
done < "$tmp_dir/aliases.txt"

missing_count=$(wc -l < "$tmp_dir/missing_in_map.txt" | tr -d ' ')
unexpected_count=$(wc -l < "$tmp_dir/unexpected_in_map.txt" | tr -d ' ')
invalid_alias_count=$(wc -l < "$tmp_dir/invalid_alias_targets.txt" | tr -d ' ')

echo "Header pages: $(wc -l < "$tmp_dir/header_pages.txt" | tr -d ' ')"
echo "Modeled pages: $(wc -l < "$tmp_dir/map_pages.txt" | tr -d ' ')"
echo "Missing from map: $missing_count"
if [[ "$missing_count" -gt 0 ]]; then
  sed -n '1,200p' "$tmp_dir/missing_in_map.txt"
fi

echo "Unexpected modeled pages: $unexpected_count"
if [[ "$unexpected_count" -gt 0 ]]; then
  sed -n '1,200p' "$tmp_dir/unexpected_in_map.txt"
fi

echo "Invalid alias targets: $invalid_alias_count"
if [[ "$invalid_alias_count" -gt 0 ]]; then
  sed -n '1,200p' "$tmp_dir/invalid_alias_targets.txt"
fi

if [[ "$missing_count" -gt 0 || "$unexpected_count" -gt 0 || "$invalid_alias_count" -gt 0 ]]; then
  exit 1
fi

echo "Page context map drift check passed."
