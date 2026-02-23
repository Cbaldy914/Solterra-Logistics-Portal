# Customs Hold Implementation Plan (Revised)

Last updated: 2026-02-23
Owner: Logistics Portal engineering
Status: In progress (phase implementation underway)

## Current Implementation Snapshot (2026-02-23)

Completed in code:
1. Added migration `migrations/2026_02_23_add_pallet_customs_hold_cost.sql`.
2. Added shipment blocking for pallet status `Customs Hold` in `create_shipment.php`.
3. Added customs-hold status surfacing in:
   - `components/project_overview/data_processing.php`
   - `components/project_overview/views_unified.php`
   - `components/project_overview/project_overview.css`
   - `project_overview.php`
   - `view_project.php`
4. Added port customs-hold operations in `manage_warehouse_inventory.php`:
   - New `Customs Hold` tab.
   - Per-pallet `Place on Customs Hold` action with per-pallet cost entry.
   - Per-pallet `Release to Cleared Customs` action with optional additional cost.
5. Added module movement updates in `module_movements.php`:
   - No ETA interpolation fallback for on-water marker placement.
   - Boat marker uses: manual position -> latest waypoint -> destination port fallback.
   - Destination port markers added to map.
   - Port icon set to anchor, warehouse icon updated to building.
   - Waypoint sampling added to reduce map clutter.
6. Added initial reporting cost integration:
   - `project_cost_details.php`
   - `pallet_details.php`
   - `module_cost_analysis.php`
   - `project_close.php`

Outstanding validation / cleanup:
1. Verify live UX flow for customs hold actions against real data.
2. Confirm reporting totals with QA spot checks in production-like data.
3. Decide whether to keep existing delivery-level customs migrations after QA.

## 1) Executive Decisions

1. Keep ports inside `manage_warehouse_inventory.php` for phase 1.
2. Add pallet-level customs hold actions inside the port workflow (no separate ports page yet).
3. Keep and use the migrations already run (`2026_02_20_*`).
4. Add one follow-up migration for pallet-level customs hold cost tracking.
5. In `module_movements.php`, stop ETA interpolation for vessel position.
6. On-water marker placement rule:
   - If manual vessel position exists, use it.
   - Else if waypoints exist, use the latest waypoint.
   - Else place vessel at destination port.
7. Show destination port marker on map with a port icon (anchor).
8. Use a building icon for warehouses.

## 2) Recommendation on Ports vs Separate Page

Recommendation: keep everything in `manage_warehouse_inventory.php` for now.

Why:
1. Current port/warehouse branching already exists via `warehouses.is_port`.
2. Splitting now increases navigation and doubles maintenance.
3. We can still provide a focused customs flow using tabs/sub-tabs and role-based actions.

Planned UX inside port view:
1. `Inbound Transit`
2. `Cleared Customs`
3. `Customs Hold` (new)
4. `History`

## 3) Data Model

## 3.1 Keep Existing Migrations (already run)

Keep these as-is:
1. `migrations/2026_02_20_add_customs_hold_fields.sql`
2. `migrations/2026_02_20_add_delivery_customs_fee_overrides.sql`

Rationale:
1. They provide delivery-level hold metadata and fee override fields.
2. They are additive and low-risk.
3. They support container-level customs costs and auditability.

## 3.2 Add Follow-up Migration (new)

Add pallet-level customs hold cost support:
1. `migrations/2026_02_23_add_pallet_customs_hold_cost.sql`
2. DDL:
   - `ALTER TABLE inventory_pallets ADD COLUMN customs_hold_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER accessorial_cost;`
   - `ALTER TABLE inventory_pallets ADD COLUMN customs_hold_cost_notes VARCHAR(255) NULL AFTER customs_hold_cost;`
   - `ALTER TABLE inventory_pallets ADD COLUMN customs_hold_cost_updated_at DATETIME NULL AFTER customs_hold_cost_notes;`

Rationale:
1. Cost assignment is explicitly pallet-level as requested.
2. Existing reporting can include this field with minimal query changes.
3. Keeps delivery-level customs override for container-level charges.

Optional phase-2 enhancement:
1. Replace single field with `pallet_customs_cost_events` if multiple line items/history is needed.

## 4) Status Rules

Canonical pallet statuses:
1. `On Water`
2. `Customs Hold`
3. `Cleared Customs`
4. Existing downstream statuses unchanged.

Rules:
1. Pallets in `Customs Hold` cannot be shipped/moved out.
2. Port release action moves selected pallets from `Customs Hold` -> `Cleared Customs`.
3. Delivery status sync policy:
   - If all linked pallets are `Customs Hold`, set delivery `status_of_delivery = 'Customs Hold'`.
   - If all linked pallets are `Cleared Customs`, set delivery `status_of_delivery = 'Cleared Customs'`.
   - If mixed, keep pallet-level statuses as source of truth and allow partial release/drayage of cleared pallets.

## 5) UI/Workflow Changes

## 5.1 `manage_warehouse_inventory.php` (port mode)

Add customs operations:
1. New `Customs Hold` tab listing held pallets grouped by container.
2. In `Cleared Customs` tab, action `Mark Customs Hold`:
   - Supports per-pallet selection from a selected container.
   - Requires reason.
   - Optional notes and optional per-pallet hold cost.
3. In `Customs Hold` tab, action `Release to Cleared Customs`:
   - Per-pallet release.
   - Optional pallet-level release cost adjustment.

Guardrails:
1. Role: `admin`, `global_admin`, `customer_admin` write; others read-only where applicable.
2. Server-side validation required for all transitions.

## 5.2 `create_shipment.php`

Update blocking logic:
1. Add `Customs Hold` to disallowed pallet statuses in JS and server guard.
2. Update status mix widgets to include `Customs Hold` and `Cleared Customs` cleanly.

## 5.3 Project and delivery views

Update status lists, filters, and aggregates to include `Customs Hold` and `Cleared Customs` consistently:
1. `components/project_overview/data_processing.php`
2. `components/project_overview/views_unified.php`
3. `project_overview.php` modal/filter behavior
4. `view_project.php` status filter dropdown and status stats
5. `container_tracking.php` status badge style mapping

## 6) Reporting and Cost Rollup

Where customs hold cost should appear:
1. Project cost rollups
2. Pallet-level details
3. Module cost analysis / logistics totals
4. Any pages summing `accessorial` or pallet logistics

Rollup rule:
1. `total_customs_hold_cost = SUM(inventory_pallets.customs_hold_cost)` by scope.
2. Keep delivery-level `customs_fee_override` for non-palletized/container-level customs fees.
3. Total customs displayed as:
   - `Pallet Customs Hold Cost` + `Delivery Customs Fee Override/Default Customs`

Priority files:
1. `project_cost_details.php`
2. `components/project_overview/data_processing.php`
3. `pallet_details.php`
4. `module_cost_analysis.php`
5. `project_close.php`

## 7) Module Movements Map Changes

## 7.1 Position source and path logic

Current issue:
1. Map estimates vessel position by ETA interpolation when no points exist.

New behavior:
1. Use only recorded tracking data for water movement visualization.
2. Marker position precedence:
   - Latest waypoint
   - Manual vessel position
   - Origin port marker (fallback destination port)
3. No synthetic midpoint interpolation.

## 7.2 Origin and destination ports

Add to on-water query:
1. Origin port data from `deliveries.origin_port_id` -> `warehouses`.
2. Destination port data from `deliveries.warehouse_id` where port destination applies.

Map display:
1. Show destination port marker always for on-water containers.
2. Popup style should match warehouse popup structure.
3. Label/metadata should clearly say `Port`.

## 7.3 Icons

Change marker iconography:
1. Port: anchor icon.
2. Warehouse: building icon.
3. Keep manufacturer and project distinct.

## 7.4 Waypoint density strategy (recommendation)

Recommendation:
1. Keep all waypoints in DB.
2. Render simplified path by default to avoid clutter:
   - Always include first + last point.
   - Include every Nth middle point (or max 12 visible points).
3. Optional toggle in future: `Show full route history`.

This keeps the map readable while preserving full tracking fidelity.

## 8) Impacted Files (initial implementation set)

1. `manage_warehouse_inventory.php`
2. `handle_pallet_arrival.php`
3. `create_shipment.php`
4. `module_movements.php`
5. `container_tracking.php`
6. `components/project_overview/data_processing.php`
7. `components/project_overview/views_unified.php`
8. `project_overview.php`
9. `view_project.php`
10. `project_cost_details.php`
11. `pallet_details.php`
12. `module_cost_analysis.php`
13. `project_close.php`
14. `dashboard.php`

## 9) Rollout Phases

Phase 1 (core):
1. Status + UI actions in port workflow
2. Shipment blocking for `Customs Hold`
3. Project/delivery status surfacing updates

Phase 2 (costs):
1. Pallet customs hold cost migration
2. Cost rollup/reporting updates

Phase 3 (map):
1. On-water position source fix (no interpolation)
2. Destination port marker + icon updates
3. Waypoint simplification rendering

## 10) Acceptance Criteria

1. Port users can place/release hold at pallet level.
2. Held pallets are blocked from shipment creation.
3. `Customs Hold` and `Cleared Customs` appear across timeline/deliveries/status filters.
4. Customs hold pallet cost is visible in reporting rollups.
5. On-water map positions rely on recorded tracking data only.
6. Destination port appears clearly on map with anchor icon.
7. Warehouse markers use building icon.

---

## 11) Explicit Execution Runbook (Context-Reset Safe)

Use this as the exact implementation order when coding starts. Do not skip steps.

## 11.1 Ground Rules

1. Do not change user-facing business logic outside customs/map scope.
2. Keep ports in `manage_warehouse_inventory.php` (no new standalone page in phase 1).
3. Default operational behavior: pallet-level hold with partial release allowed (only cleared pallets can move).
4. Keep existing migrations already run:
   - `migrations/2026_02_20_add_customs_hold_fields.sql`
   - `migrations/2026_02_20_add_delivery_customs_fee_overrides.sql`
5. Add only additive schema changes (no destructive migration).

## 11.2 Implementation Phases (Hard Order)

1. Phase A: schema + constants/helpers.
2. Phase B: port workflow (hold/release) + container blocking.
3. Phase C: status surfacing across project/delivery pages.
4. Phase D: map behavior/icons/ports in `module_movements.php`.
5. Phase E: reporting cost rollups for pallet customs hold cost.
6. Phase F: regression checks + SQL validation.

## 11.3 Phase A: Schema + Shared Status Definitions

### A1) Add migration for pallet-level customs hold cost

Create `migrations/2026_02_23_add_pallet_customs_hold_cost.sql` with:

1. `ALTER TABLE inventory_pallets ADD COLUMN customs_hold_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER accessorial_cost;`
2. `ALTER TABLE inventory_pallets ADD COLUMN customs_hold_cost_notes VARCHAR(255) NULL AFTER customs_hold_cost;`
3. `ALTER TABLE inventory_pallets ADD COLUMN customs_hold_cost_updated_at DATETIME NULL AFTER customs_hold_cost_notes;`

### A2) Add/centralize status constants

If no existing shared status helper is present, create one (or add to existing helper):

1. Canonical statuses:
   - `At Manufacturer`
   - `On Water`
   - `Customs Hold`
   - `Cleared Customs`
   - `In Transit to Warehouse`
   - `In Warehouse`
   - `In Transit to Project`
   - `Delivered to Project`
2. Helper booleans:
   - `is_customs_blocked(status)` -> true for `Customs Hold`
   - `is_in_transit(status)` -> transit statuses
3. Keep all comparisons exact-string and case-sensitive to current conventions.

## 11.4 Phase B: Port Workflow + Blocking Rules

### B1) `manage_warehouse_inventory.php` (port mode only)

Implement:

1. New `Customs Hold` tab in port interface.
2. Add action in `Cleared Customs` area: `Mark Customs Hold`.
3. Add action in `Customs Hold` area: `Release to Cleared Customs`.
4. Role enforcement for actions:
   - allow `admin`, `global_admin`, `customer_admin`
   - keep read-only behavior otherwise.
5. For hold action capture:
   - reason (required),
   - notes (optional),
   - optional per-pallet hold cost.
6. Store delivery metadata when available:
   - set `deliveries.customs_hold_started_date`,
   - set `deliveries.customs_hold_reason`,
   - set `deliveries.customs_hold_notes`.
7. For release action:
   - set `deliveries.customs_hold_released_date`,
   - set `deliveries.customs_cleared_date` if needed,
   - optional `deliveries.customs_fee_override`/`customs_fee_notes`.
8. Container-blocking sync rule:
   - If any pallet in a container is hold, set container delivery status display to hold.
   - Block drayage move for that container.

### B2) `handle_pallet_arrival.php`

Adjust receive logic:

1. Preserve customs fields on port receive.
2. Never auto-clear pallets that are explicitly `Customs Hold` unless release action is used.
3. Ensure multi-receive branches follow same rule as single receive.

### B3) `create_shipment.php`

Hard block hold pallets in both JS and server:

1. Add `Customs Hold` to server disallowed list (`$disallowed`).
2. Add `Customs Hold` to JS invalid selection detection (`selectionHasInvalidPallets`).
3. Update user-facing error text to mention customs hold explicitly.
4. Include `Customs Hold` in status mix widgets/charts where applicable.

## 11.5 Phase C: Status Surfacing Across Project/Delivery Pages

### C1) `components/project_overview/data_processing.php`

Update aggregates:

1. Add `Customs Hold` to `$status_totals` initialization.
2. Add `Customs Hold` into per-wattage delivery totals data model.
3. Include hold in `detailed_breakdown` key mapping.
4. Include hold in:
   - combined totals,
   - pie chart data,
   - `pallets_status_main`,
   - `pallets_sub_rows_status`.

### C2) `components/project_overview/views_unified.php`

Update UI:

1. Add a shipping box for `Customs Hold`.
2. Keep `Cleared Customs` separate.
3. In module delivery status table, add conditional column for hold (same behavior as other conditional status columns).
4. Ensure timeline shipping step includes hold counts.

### C3) `project_overview.php`

Update modal/filter logic:

1. Ensure `showCustomerShippingModal` and `generateCustomerShippingContent` handle `Customs Hold`.
2. Add correct CTA links for hold status rows.

### C4) `view_project.php`

Update:

1. Status filter dropdown: add `Customs Hold` and keep `Cleared Customs`.
2. Stats color mapping: treat hold as warning.
3. Row badge class mapping: add hold class.
4. Grouped/mixed status logic remains intact.

### C5) `container_tracking.php`

1. Ensure status pill styling recognizes `Customs Hold`.
2. Keep table sorting/filtering behavior unchanged.

## 11.6 Phase D: Module Movements Map Behavior

File: `module_movements.php`

### D1) Destination port data

In on-water container query:

1. Use destination port via `deliveries.warehouse_id` to `warehouses`.
2. Select destination port id/name/address fields in output JSON.
3. Always add destination port marker to the map when on-water containers exist.

### D2) Remove ETA interpolation fallback

Current behavior to remove:

1. `estimateContainerProgress(...)`
2. `interpolatePosition(...)`
3. placing marker on synthetic midpoint when no tracking points exist.

New marker precedence:

1. manual vessel position (if provided),
2. else latest waypoint (if any),
3. else destination port position.

### D3) Draw waypoint path without clutter

1. Keep all DB waypoints.
2. Render simplified polyline points by default:
   - first + last + sampled middle points (max ~12 visible points).
3. Keep info window showing full waypoint count + last timestamp.

### D4) Destination port marker and icon updates

1. Add destination port marker even when no pallets are currently `In Warehouse`.
2. Marker popup style should match existing location popups.
3. Port marker icon: anchor.
4. Warehouse marker icon: building.
5. Update map legend labels/icons accordingly.

## 11.7 Phase E: Cost Rollups and Reporting

Files:

1. `project_cost_details.php`
2. `components/project_overview/data_processing.php`
3. `pallet_details.php`
4. `module_cost_analysis.php`
5. `project_close.php`
6. `dashboard.php` (if summary totals display in-transit/customs buckets)

Implement:

1. Add `SUM(inventory_pallets.customs_hold_cost)` rollup by project.
2. Show customs total split:
   - pallet hold cost,
   - delivery customs fee (override/default).
3. Keep existing freight/accessorial math intact.
4. Do not overwrite historical freight/accessorial allocations.

## 11.8 Phase F: Milestones, Events, and Notifications

### F1) `milestone_helpers.php`

Ensure no false customs milestone trigger:

1. `Customs Hold` should NOT trigger `customs_cleared`.
2. `Cleared Customs` and downstream statuses continue to trigger as today.
3. Update `get_delivery_milestone_events(...)` and backfill condition arrays accordingly.

### F2) delivery status event audit

Use existing `delivery_status_events` from prior migration:

1. Insert event `customs_hold_set` on hold.
2. Insert event `customs_hold_released` on release.
3. Save `changed_by`, timestamps, and notes.

## 11.9 SQL Validation Queries (Post-Deploy)

Run and verify:

1. Deliveries marked hold without reason:
   - `SELECT id FROM deliveries WHERE status_of_delivery='Customs Hold' AND (customs_hold_reason IS NULL OR TRIM(customs_hold_reason)='');`
2. Hold deliveries missing start date:
   - `SELECT id FROM deliveries WHERE status_of_delivery='Customs Hold' AND customs_hold_started_date IS NULL;`
3. Held pallets still shippable (should be none):
   - validate via shipment create guard test using selected hold pallet IDs.
4. Containers with mixed hold/cleared pallets:
   - confirm UI flags and partial release/drayage behavior match pallet-level rule.

## 11.10 Manual QA Checklist (Required)

1. Port page:
   - mark pallet hold,
   - release hold,
   - verify tabs/counters update.
2. Drayage partial release:
   - select container with mixed held/cleared pallets,
   - confirm only cleared pallets move and held pallets remain blocked.
3. Shipment block:
   - try create shipment with held pallet,
   - confirm JS and server both block.
4. Project overview:
   - timeline shows hold + cleared.
5. Deliveries tracker:
   - status filter includes hold.
6. Module movements:
   - no waypoints + no manual coords => marker at destination port (no midpoint interpolation).
   - destination port marker visible with anchor icon.
   - warehouse icon rendered as building.
7. Cost pages:
   - pallet hold costs appear in totals and details.

## 11.11 Commit Strategy

Use small commits in this order:

1. `feat(customs): add pallet customs hold cost schema + status constants`
2. `feat(ports): add customs hold/release workflow and container blocking`
3. `feat(status): propagate customs hold across project/delivery views`
4. `feat(map): use tracking-only vessel placement + destination port marker/icons`
5. `feat(costs): include pallet customs hold cost in reporting rollups`
6. `fix(milestones): exclude customs hold from customs-cleared triggers`

## 11.12 Stop Condition

Before announcing completion:

1. Run lint/syntax checks on touched PHP files.
2. Confirm no unrelated files changed.
3. Re-open this plan and verify every checklist section has been executed.
