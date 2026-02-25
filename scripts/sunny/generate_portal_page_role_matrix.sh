#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
MAP_FILE="$ROOT_DIR/ai-assistant/config/page-context-map.php"
AWK_FILE="$ROOT_DIR/scripts/sunny/extract_page_catalog.awk"
OUT_FILE="$ROOT_DIR/docs/portal_page_role_matrix.md"

if [[ ! -f "$MAP_FILE" ]]; then
  echo "Missing map file: $MAP_FILE" >&2
  exit 1
fi
if [[ ! -f "$AWK_FILE" ]]; then
  echo "Missing awk parser: $AWK_FILE" >&2
  exit 1
fi

count=$(rg "^\s{8}'[a-z0-9_]+'\s*=>\s*\[" -o "$MAP_FILE" | wc -l | tr -d ' ')

{
cat <<'MD'
# Portal Page Role Matrix (Sunny Source)

Last updated: 2026-02-25

This is the operational source for page purpose + role behavior used by Sunny page-awareness.

## Role Capability Profiles

`global_only`
- `user`: no access
- `customer_admin`: no access
- `admin`: no access
- `global_admin`: full global management

`account_admins_only`
- `user`: no access
- `customer_admin`: full management for assigned account
- `admin`: full management for assigned account
- `global_admin`: full global management

`all_read`
- `user`: read-only in assigned account scope
- `customer_admin`: read-only insights
- `admin`: read-only insights
- `global_admin`: read-only across all accounts

`all_read_with_admin_actions`
- `user`: read-only in assigned account scope
- `customer_admin`: manage actions in assigned account
- `admin`: manage actions in assigned account
- `global_admin`: manage actions globally

`all_personal_workspace`
- all roles: can manage their own saved scenarios/estimates on that page

`shipment_workspace`
- `user`: review-only shipment workspace
- `customer_admin`: create/manage shipments in account scope
- `admin`: create/manage shipments in account scope
- `global_admin`: create/manage shipments globally

`documents_workspace`
- `user`: browse/download docs in scope
- `customer_admin`: browse/download/upload/manage in account scope
- `admin`: browse/download/upload/manage in account scope
- `global_admin`: browse/download/upload/manage globally

`custom`
- role behavior is page-specific and defined directly in [page-context-map.php](/mnt/c/xampp/htdocs/Solterra-Solutions/Solterra-Logistics-Portal/ai-assistant/config/page-context-map.php).

## Actual Page Files and Access

This table covers all pages currently modeled in Sunny page context.

| Page File | Purpose | Role Profile | Access Roles | Supporting Pages |
|---|---|---|---|---|
MD
awk -f "$AWK_FILE" "$MAP_FILE" | while IFS='|' read -r page name profile access support; do
  page_file="${page}.php"
  access_fmt=$(echo "$access" | sed 's/,/, /g')
  support_fmt=$(echo "$support" | sed 's/,/, /g')
  printf '| %s | %s | %s | %s | %s |\n' "$page_file" "$name" "$profile" "$access_fmt" "$support_fmt"
done
printf '\nTotal modeled pages: %s\n\n' "$count"
cat <<'MD'
## Alias Routes (Resolved Before Context Lookup)

- `profile_settings` -> `account_settings`
- `cost_analysis` -> `module_cost_analysis`
- `sustainability` -> `sustainability_overview`
- `manage_warehouse_inventory` -> `warehouse`
- `warehouse_info` -> `warehouse`
- `manage_warehouses` -> `warehousing_overview`
- `invoices` -> `invoices_all`

## Notes

- This matrix intentionally focuses on customer-facing pages and major operational drill-down pages.
- Utility endpoints (`get_*`, `process_*`, `api/*`) are not page contexts and are excluded.
- Some modeled contexts are legacy routes retained for compatibility.
MD
} > "$OUT_FILE"

echo "Wrote $OUT_FILE"
