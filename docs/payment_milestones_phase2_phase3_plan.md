# Payment Milestones System - Phase 2 & Phase 3 Implementation Plan

## Overview

Building on Phase 1 (milestone configuration and triggering), Phase 2 focuses on integrating milestone data into existing reports, while Phase 3 creates a comprehensive cost projection and forecasting system.

---

## Current State (Post Phase 1)

### What We Have:
- **Module Costs Panel** - Configure cost_per_watt and payment milestones per module batch
- **Trigger Events** - PO Execution, Shipping, Customs Cleared, Project Delivery
- **Automatic Triggering** - Milestones trigger when deliveries hit status changes
- **Database Tables:**
  - `module_batch_milestones` - Milestone configuration (trigger_event, percentage)
  - `delivery_milestone_instances` - Triggered milestone records with payment_amount

### Existing Cost Data:
- **Module costs** - cost_per_watt × wattage × quantity (from modules/unassigned_module_items)
- **Freight costs** - deliveries.freight_cost, deliveries.customer_cost
- **Accessorial costs** - deliveries.accessorial_costs, deliveries.accessorial_costs_paid
- **Warehousing costs** - warehouse_cost_items, inventory_pallets.warehouse_cost

### Existing Reports:
- `project_overview.php` - Invoices/Cashflow table, Forecasted vs Actual chart
- `project_cost_details.php` - Detailed cost breakdown by category
- `module_cost_analysis.php` - Portfolio-wide cost analysis by manufacturer
- `anticipated_deliveries.php` - Delivery forecasting (MW over time)

---

## Phase 2: Reporting Integration

**Goal:** Display triggered milestone data in existing reports to show the complete picture of actual costs and when they were paid.

### 2.1 Database Enhancement

#### New Helper Functions (milestone_helpers.php)
```php
/**
 * Get all triggered milestones for a project
 * Returns: array of milestone instances with delivery info
 */
function get_project_milestone_instances($project_id, $conn);

/**
 * Get milestone summary for a project
 * Returns: ['total_triggered' => $, 'by_event' => [...], 'by_month' => [...]]
 */
function get_project_milestone_summary($project_id, $conn);

/**
 * Get milestone payment timeline for a project
 * Returns: array sorted by triggered_at with cumulative totals
 */
function get_milestone_payment_timeline($project_id, $conn);

/**
 * Get configured vs triggered milestone comparison
 * Returns: ['configured_total' => $, 'triggered_total' => $, 'remaining' => $]
 */
function get_milestone_completion_status($project_id, $conn);
```

### 2.2 Project Overview Page Updates (project_overview.php)

#### A. Milestone Payment Summary Card
Add a new card in the project header area showing:
```
┌─────────────────────────────────────────┐
│ Module Payment Progress                 │
├─────────────────────────────────────────┤
│ Total Contract Value    │ $1,250,000    │
│ Payments Triggered      │ $875,000 (70%)│
│ Remaining               │ $375,000      │
├─────────────────────────────────────────┤
│ ▓▓▓▓▓▓▓▓▓▓▓▓▓▓░░░░░░░░ 70%            │
└─────────────────────────────────────────┘
```

#### B. Enhanced Invoices and Cashflow Forecast Table
Update the existing table to include milestone payments:
```
┌──────────────┬─────────────┬──────────────┬──────────────┬─────────────┐
│ Date         │ Event       │ Description  │ Amount       │ Cumulative  │
├──────────────┼─────────────┼──────────────┼──────────────┼─────────────┤
│ 2026-01-05   │ PO Exec     │ Batch #1     │ $375,000     │ $375,000    │
│ 2026-01-12   │ Shipping    │ DEL-001      │ $125,000     │ $500,000    │
│ 2026-01-15   │ Freight     │ DEL-001      │ $8,500       │ $508,500    │
│ 2026-01-18   │ Customs     │ DEL-001      │ $166,667     │ $675,167    │
│ 2026-01-22   │ Warehousing │ Jan Storage  │ $2,400       │ $677,567    │
│ 2026-02-01   │ Delivery    │ DEL-001      │ $125,000     │ $802,567    │
└──────────────┴─────────────┴──────────────┴──────────────┴─────────────┘
```

#### C. Enhanced Forecasted vs Actual Cost Chart
- Add milestone payment data as a third line/area
- Show: Forecasted Costs, Actual Logistics Costs, Milestone Payments
- Color coding: Blue (forecasted), Green (actual logistics), Teal (milestones)

### 2.3 Project Cost Details Page Updates (project_cost_details.php)

#### A. New Section: Module Payment Milestones
```
┌─────────────────────────────────────────────────────────────────────────┐
│ Module Payment Milestones                                               │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                         │
│ Module Batch: Test Manufacturer - 555W                                  │
│ Contract Value: $693,750 (500 modules × 555W × $0.25/W)                │
│                                                                         │
│ ┌───────────────────┬────────────┬────────────┬─────────────┬────────┐ │
│ │ Milestone         │ Percentage │ Amount     │ Status      │ Date   │ │
│ ├───────────────────┼────────────┼────────────┼─────────────┼────────┤ │
│ │ PO Execution      │ 30%        │ $208,125   │ ✓ Triggered │ Jan 5  │ │
│ │ Shipping          │ 20%        │ $138,750   │ ✓ Triggered │ Jan 12 │ │
│ │ Customs Cleared   │ 20%        │ $138,750   │ ✓ Triggered │ Jan 18 │ │
│ │ Project Delivery  │ 30%        │ $208,125   │ ○ Pending   │ --     │ │
│ └───────────────────┴────────────┴────────────┴─────────────┴────────┘ │
│                                                                         │
│ Total Triggered: $485,625 / $693,750 (70%)                             │
└─────────────────────────────────────────────────────────────────────────┘
```

#### B. Update Cost Summary
Add milestone payments to the total cost breakdown:
```
Cost Breakdown:
├── Module Costs (Contract Value)     $693,750
│   └── Payments Triggered            $485,625  (70%)
├── Freight Costs                     $12,500
├── Warehousing Costs                 $4,800
├── Accessorial Costs                 $1,200
└── TOTAL COSTS INCURRED              $504,125
```

### 2.4 Module Cost Analysis Page Updates (module_cost_analysis.php)

#### A. Portfolio-Wide Milestone Summary
Add aggregate milestone data across all projects:
```
┌─────────────────────────────────────────┐
│ Payment Milestone Overview              │
├─────────────────────────────────────────┤
│ Total Contract Value    │ $15,234,000   │
│ Total Triggered         │ $10,125,000   │
│ Completion Rate         │ 66.5%         │
├─────────────────────────────────────────┤
│ By Trigger Event:                       │
│ • PO Execution          │ $4,570,200    │
│ • Shipping              │ $3,046,800    │
│ • Customs Cleared       │ $1,523,400    │
│ • Project Delivery      │ $984,600      │
└─────────────────────────────────────────┘
```

#### B. Update Manufacturer Cards
Add milestone payment data to manufacturer breakdown:
- Total milestone payments by manufacturer
- Average payment completion rate

### 2.5 Implementation Files

**New Files:**
- `components/milestone_summary_card.php` - Reusable milestone summary component
- `components/milestone_timeline_table.php` - Payment timeline table component
- `api/get_milestone_data.php` - AJAX endpoint for milestone data

**Modified Files:**
- `milestone_helpers.php` - Add new summary functions
- `project_overview.php` - Add milestone card and enhance tables/charts
- `project_cost_details.php` - Add milestone section
- `module_cost_analysis.php` - Add portfolio milestone summary
- `cost_helpers.php` - Add milestone costs to total calculations

---

## Phase 3: Enhanced Cost Forecasting

**Goal:** Create a comprehensive cost projection system that follows the delivery lifecycle from manufacturer to jobsite, allowing users to forecast all costs (milestones, freight, warehousing, accessorials).

### 3.1 Enhanced Anticipated Deliveries Page

Transform `anticipated_deliveries.php` from delivery-only forecasting to a complete cost projection tool.

#### A. New Page Structure: "Delivery & Cost Projections"

**Tab 1: Delivery Schedule** (existing, enhanced)
- Quick Schedule mode
- Detailed Schedule mode
- Cumulative delivery chart

**Tab 2: Cost Projections** (new)
- Step-by-step cost entry following delivery lifecycle
- Calculate totals automatically
- Show payment timeline

**Tab 3: Forecast vs Actual** (enhanced)
- Combined view of delivery and cost forecasts
- Comparison charts
- Variance analysis

#### B. Cost Projection Workflow (Tab 2)

Follow the natural sequence of events from manufacturer to jobsite:

```
┌─────────────────────────────────────────────────────────────────────────┐
│ STEP 1: MODULE COSTS                                                    │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                         │
│ Module Batch          │ Cost/Watt │ Total Watts │ Contract Value       │
│ ──────────────────────┼───────────┼─────────────┼────────────────────  │
│ Test Mfg - 555W       │ $0.25     │ 277,500     │ $69,375              │
│ Test Mfg - 580W       │ $0.27     │ 290,000     │ $78,300              │
│ ──────────────────────┴───────────┴─────────────┴────────────────────  │
│                                     TOTAL MODULE VALUE: $147,675        │
│                                                                         │
│ Payment Milestone Schedule:                                             │
│ ┌─────────────────┬────────┬───────────┬─────────────────────────────┐ │
│ │ Event           │ %      │ Amount    │ Estimated Date              │ │
│ ├─────────────────┼────────┼───────────┼─────────────────────────────┤ │
│ │ PO Execution    │ 30%    │ $44,303   │ ✓ Jan 5, 2026 (triggered)   │ │
│ │ Shipping        │ 20%    │ $29,535   │ ~ Jan 15, 2026              │ │
│ │ Customs Cleared │ 20%    │ $29,535   │ ~ Jan 25, 2026              │ │
│ │ Project Delivery│ 30%    │ $44,303   │ ~ Feb 10, 2026              │ │
│ └─────────────────┴────────┴───────────┴─────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────┐
│ STEP 2: SHIPPING & FREIGHT                                              │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                         │
│ Estimated Deliveries: 4 truckloads                                      │
│                                                                         │
│ ┌─────────────────────────────────────────────────────────────────────┐ │
│ │ Delivery    │ Est. Ship Date │ Freight Cost │ Accessorial │ Total   │ │
│ ├─────────────┼────────────────┼──────────────┼─────────────┼─────────┤ │
│ │ Truck 1     │ Jan 15         │ $3,500       │ $200        │ $3,700  │ │
│ │ Truck 2     │ Jan 22         │ $3,500       │ $200        │ $3,700  │ │
│ │ Truck 3     │ Jan 29         │ $3,500       │ $200        │ $3,700  │ │
│ │ Truck 4     │ Feb 5          │ $3,500       │ $200        │ $3,700  │ │
│ └─────────────┴────────────────┴──────────────┴─────────────┴─────────┘ │
│                                                                         │
│ Quick Entry: [4] trucks × [$3,500] freight + [$200] accessorial each   │
│                                         TOTAL FREIGHT: $14,800          │
└─────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────┐
│ STEP 3: PORT & CUSTOMS (if applicable)                    [○ Include]  │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                         │
│ Port/Customs Facility: [Select or skip]                                 │
│                                                                         │
│ ┌─────────────────────────────────────────────────────────────────────┐ │
│ │ Fee Type          │ Rate        │ Units   │ Estimated Cost          │ │
│ ├───────────────────┼─────────────┼─────────┼─────────────────────────┤ │
│ │ Customs Clearance │ $150/pallet │ 32      │ $4,800                  │ │
│ │ Port Handling     │ $75/pallet  │ 32      │ $2,400                  │ │
│ │ Drayage           │ $800/truck  │ 4       │ $3,200                  │ │
│ └───────────────────┴─────────────┴─────────┴─────────────────────────┘ │
│                                          TOTAL PORT/CUSTOMS: $10,400    │
└─────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────┐
│ STEP 4: WAREHOUSING (if applicable)                       [● Include]  │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                         │
│ Warehouse: [Select warehouse ▼]          Est. Storage: [30] days        │
│                                                                         │
│ ┌─────────────────────────────────────────────────────────────────────┐ │
│ │ Fee Type          │ Rate        │ Units   │ Estimated Cost          │ │
│ ├───────────────────┼─────────────┼─────────┼─────────────────────────┤ │
│ │ Receiving (In)    │ $25/pallet  │ 32      │ $800                    │ │
│ │ Monthly Storage   │ $15/pallet  │ 32      │ $480                    │ │
│ │ Outbound (Out)    │ $25/pallet  │ 32      │ $800                    │ │
│ └───────────────────┴─────────────┴─────────┴─────────────────────────┘ │
│                                            TOTAL WAREHOUSING: $2,080    │
│                                                                         │
│ [+ Add Another Warehouse Stop]  (for multi-warehouse scenarios)         │
└─────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────┐
│ STEP 5: FINAL DELIVERY                                                  │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                         │
│ Estimated Project Delivery Date: [Feb 10, 2026]                         │
│                                                                         │
│ ┌─────────────────────────────────────────────────────────────────────┐ │
│ │ Fee Type                │ Rate      │ Units │ Estimated Cost        │ │
│ ├─────────────────────────┼───────────┼───────┼───────────────────────┤ │
│ │ Final Mile Delivery     │ $500/truck│ 4     │ $2,000                │ │
│ │ Unloading/Spotting      │ $150/truck│ 4     │ $600                  │ │
│ └─────────────────────────┴───────────┴───────┴───────────────────────┘ │
│                                         TOTAL FINAL DELIVERY: $2,600    │
└─────────────────────────────────────────────────────────────────────────┘
```

#### C. Projection Summary & Timeline

After completing the steps, show a comprehensive summary:

```
┌─────────────────────────────────────────────────────────────────────────┐
│ COST PROJECTION SUMMARY                                                 │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                         │
│ Category                           │ Projected    │ Actual    │ Var    │
│ ───────────────────────────────────┼──────────────┼───────────┼─────── │
│ Module Payments                    │ $147,675     │ $44,303   │ 30%    │
│ Shipping & Freight                 │ $14,800      │ $3,700    │ 25%    │
│ Port & Customs                     │ $10,400      │ $0        │ 0%     │
│ Warehousing                        │ $2,080       │ $0        │ 0%     │
│ Final Delivery                     │ $2,600       │ $0        │ 0%     │
│ ───────────────────────────────────┼──────────────┼───────────┼─────── │
│ TOTAL                              │ $177,555     │ $48,003   │ 27%    │
└─────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────┐
│ PROJECTED PAYMENT TIMELINE                                              │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                         │
│ Jan 2026                                                                │
│ ├── Jan 5  │ PO Execution (30%)       │ $44,303    │ ✓ Paid            │
│ ├── Jan 15 │ Shipping (20%)           │ $29,535    │ ○ Projected       │
│ ├── Jan 15 │ Freight - Truck 1        │ $3,700     │ ○ Projected       │
│ ├── Jan 22 │ Freight - Truck 2        │ $3,700     │ ○ Projected       │
│ ├── Jan 25 │ Customs Cleared (20%)    │ $29,535    │ ○ Projected       │
│ ├── Jan 25 │ Port/Customs Fees        │ $10,400    │ ○ Projected       │
│ └── Jan 29 │ Freight - Truck 3        │ $3,700     │ ○ Projected       │
│                                                                         │
│ Feb 2026                                                                │
│ ├── Feb 1  │ Warehousing - January    │ $1,280     │ ○ Projected       │
│ ├── Feb 5  │ Freight - Truck 4        │ $3,700     │ ○ Projected       │
│ ├── Feb 10 │ Project Delivery (30%)   │ $44,303    │ ○ Projected       │
│ ├── Feb 10 │ Final Delivery Fees      │ $2,600     │ ○ Projected       │
│ └── Feb 10 │ Warehousing - Outbound   │ $800       │ ○ Projected       │
│                                                                         │
│                                     TOTAL: $177,555                     │
└─────────────────────────────────────────────────────────────────────────┘
```

### 3.2 Enhanced Project Overview Visualizations

#### A. Improved Cashflow Forecast Table
Use the projection data to populate the cashflow forecast:
- Show both projected and actual payments
- Color code: Green (paid), Blue (projected), Red (overdue)
- Sortable by date, category, amount

#### B. Improved Forecasted vs Actual Chart
Multi-line/area chart showing:
```
Line 1: Projected Cumulative Costs (from projections)
Line 2: Actual Cumulative Costs (from triggered data)
Area:   Variance between projected and actual
```

Features:
- Toggle between cost categories (All, Milestones, Logistics, Warehousing)
- Zoom to date ranges
- Hover tooltips with detailed breakdown

### 3.3 Database Schema Updates

#### New Table: `cost_projections`
```sql
CREATE TABLE `cost_projections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `projection_name` varchar(100) DEFAULT 'Default Projection',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp DEFAULT current_timestamp(),
  `updated_at` timestamp DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_project_id` (`project_id`),
  CONSTRAINT `fk_projection_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
);
```

#### New Table: `cost_projection_items`
```sql
CREATE TABLE `cost_projection_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `projection_id` int(11) NOT NULL,
  `category` enum('milestone','freight','accessorial','customs','warehousing','final_delivery','other') NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `estimated_date` date DEFAULT NULL,
  `estimated_amount` decimal(12,2) NOT NULL,
  `actual_amount` decimal(12,2) DEFAULT NULL,
  `actual_date` date DEFAULT NULL,
  `linked_delivery_id` int(11) DEFAULT NULL,
  `linked_milestone_id` int(11) DEFAULT NULL,
  `display_order` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_projection_id` (`projection_id`),
  KEY `idx_category` (`category`),
  CONSTRAINT `fk_item_projection` FOREIGN KEY (`projection_id`) REFERENCES `cost_projections` (`id`) ON DELETE CASCADE
);
```

### 3.4 Implementation Files

**New Files:**
- `migrations/add_cost_projections.sql` - Database migration
- `projection_helpers.php` - Core projection functions
- `components/cost_projection_wizard.php` - Step-by-step projection UI
- `components/projection_summary.php` - Summary display component
- `components/cashflow_timeline.php` - Timeline visualization
- `api/save_cost_projection.php` - Save projection data
- `api/get_cost_projection.php` - Retrieve projection data
- `api/calculate_projection_totals.php` - Real-time calculations

**Modified Files:**
- `anticipated_deliveries.php` - Add cost projection tab
- `project_overview.php` - Enhanced charts and tables
- `cost_helpers.php` - Add projection comparison functions

### 3.5 UI/UX Considerations

1. **Seamless Flow:** Each step auto-populates from previous data where possible
2. **Progressive Disclosure:** Optional sections (port, warehousing) collapsed by default
3. **Real-time Calculations:** Totals update as user enters data
4. **Warehouse Integration:** Pull rates from configured warehouses
5. **Save & Resume:** Projections save automatically, can be edited later
6. **Comparison Mode:** Side-by-side projected vs actual view
7. **Export Options:** PDF report, Excel export for projections

---

## Implementation Sequence

### Phase 2 (Reporting Integration) - COMPLETE ✅
1. ✅ Add milestone summary helper functions - COMPLETE
2. ✅ Create reusable milestone components - COMPLETE
   - milestone_summary_card.php (payment progress card)
   - milestone_timeline_table.php (payment events table)
   - milestone_detail_table.php (per-batch milestone status)
3. ✅ Update project_overview.php with milestone card and payment timeline - COMPLETE
4. ✅ Update project_cost_details.php with milestone section - COMPLETE
5. ✅ Update module_cost_analysis.php with portfolio milestone summary - COMPLETE
6. ⏳ Test and refine visualizations

### Phase 3 (Enhanced Forecasting) - Estimated: 5-8 development sessions
1. Create database migration for cost_projections tables
2. Build projection helper functions
3. Create cost projection wizard component
4. Integrate into anticipated_deliveries.php (or new page)
5. Build projection summary and timeline components
6. Enhance project_overview visualizations
7. Add comparison/variance analysis
8. Test full workflow end-to-end

---

## Success Criteria

### Phase 2:
- [x] Triggered milestones visible on project_overview.php
- [x] Payment timeline shows all cost events (milestones + logistics)
- [x] project_cost_details.php shows complete milestone breakdown
- [x] module_cost_analysis.php shows portfolio-wide milestone data

### Phase 3:
- [ ] Users can create cost projections following delivery lifecycle
- [ ] Projections auto-calculate from entered rates
- [ ] Cashflow forecast shows projected vs actual
- [ ] Forecasted vs Actual chart accurately compares data
- [ ] Projections persist and can be updated
- [ ] Export/reporting capabilities work

---

## Design Decisions (Resolved)

1. **Projection scope:** Per-project for project_overview and project_cost_details. Portfolio-level aggregation for module_cost_analysis.

2. **Multiple scenarios:** Yes, allow multiple projection scenarios per project. One projection is marked as "primary" and is used by other pages (cashflow forecasts, charts). Users can switch which projection is primary.

3. **Editing projections:** Users can edit projections at any time. Changes to delivery schedules require manual projection updates (projections are point-in-time snapshots).

4. **Warehouse rate changes:** Do NOT auto-update existing projections. Rates are captured at projection creation time. Users can manually update if needed.

5. **Role restrictions:** Projection creation/editing limited to `admin`, `global_admin`, and `customer_admin` roles only.

---

## Updated Database Schema

Based on the design decisions, update the `cost_projections` table:

```sql
CREATE TABLE `cost_projections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `projection_name` varchar(100) DEFAULT 'Default Projection',
  `is_primary` tinyint(1) DEFAULT 0 COMMENT 'Primary projection used for forecasts',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp DEFAULT current_timestamp(),
  `updated_at` timestamp DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_project_id` (`project_id`),
  KEY `idx_primary` (`project_id`, `is_primary`),
  CONSTRAINT `fk_projection_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
);
```

**Note:** When setting a projection as primary, ensure only one projection per project has `is_primary = 1`.

---

*Document created: January 17, 2026*
*Last updated: January 17, 2026*
