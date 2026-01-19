# Phase 3: Delivery & Cost Planning Tool

## Current Progress (as of January 19, 2026)

### ✅ COMPLETED: UI Entry Points (Step 7 - Partial)

The following entry points have been implemented and are working:

1. **Header Navigation**
   - Added "Project Planning" link under Projects dropdown in `header.php`
   - Accessible to: `admin`, `global_admin`, `customer_admin`
   - Links to `anticipated_deliveries.php` (portfolio mode)

2. **Portfolio Mode in `anticipated_deliveries.php`**
   - When accessed without `project_id`, shows project selector grid
   - Queries all projects user has access to
   - Shows schedule status for each project
   - Includes `components/anticipated_deliveries_portfolio.php` component

3. **Forecast Badge on Project Overview Timeline**
   - Added badge below "Shipping" step in `views_unified.php`
   - Uses Solterra brand colors:
     - Orange gradient "Add Plan" when no schedule exists
     - Teal gradient "Plan Set" when schedule exists
   - Links to `anticipated_deliveries.php?project_id=X`
   - Styled in `project_overview.css`

### Files Created/Modified So Far:
- `header.php` - Added "Project Planning" nav link
- `anticipated_deliveries.php` - Added portfolio mode handling at top
- `components/anticipated_deliveries_portfolio.php` - New project selector grid
- `components/project_overview/views_unified.php` - Added forecast badge
- `components/project_overview/project_overview.css` - Added badge styles

### 🔜 NEXT STEPS: Start from Step 1 (Database)
Continue with the implementation sequence below, starting from Step 1.

---

## Overview

Transform `anticipated_deliveries.php` into a comprehensive **Delivery Trip Planner** that allows users to plan the complete logistics journey from manufacturer to jobsite, including multiple stops, warehouse configurations, freight costs, and milestone tracking - all visualized on a map.

---

## Core Concepts

### Delivery Planning Flow
```
MANUFACTURER → [Shipping Leg] → WAREHOUSE/PORT → [Shipping Leg] → WAREHOUSE → [Delivery Leg] → JOBSITE
                    ↓                  ↓                              ↓                ↓
              Shipping $$$      Customs Cleared $$$            Storage $$$    Project Delivery $$$
              milestone         milestone (if marked)          costs          milestone
```

### Key Features
1. **Pre-populated module data** - Uses existing module batch info, milestones, costs
2. **Multi-leg journeys** - Add unlimited stops (ports, warehouses, staging areas)
3. **Split shipments** - Same batch can be split across different routes/warehouses
4. **Warehouse fee configuration** - Similar to cost_estimate_calculator
5. **Customs clearance tracking** - Mark any stop as customs clearance point
6. **Freight cost entry** - Cost per truck, accessorial costs per leg
7. **Real-time milestone tracking** - Shows which milestones trigger at each step
8. **Cost summary** - Running totals and complete breakdown
9. **Map visualization** - Google Maps showing the complete route
10. **Template system** - Save projections as reusable templates
11. **Actual vs Projected** - Compare plan against actual deliveries

---

## Database Schema

### Table 1: `delivery_projections`
Master projection record for a project.

```sql
CREATE TABLE `delivery_projections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `projection_name` varchar(100) NOT NULL DEFAULT 'Default Projection',
  `is_primary` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Primary projection used for forecasts',
  `is_template` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Saved as reusable template',
  `template_name` varchar(100) DEFAULT NULL COMMENT 'Template name if is_template=1',
  `status` enum('draft','active','archived') NOT NULL DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp DEFAULT current_timestamp(),
  `updated_at` timestamp DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_project_id` (`project_id`),
  KEY `idx_is_primary` (`project_id`, `is_primary`),
  KEY `idx_is_template` (`is_template`),
  CONSTRAINT `fk_projection_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Table 2: `projection_module_allocations`
Which modules/quantities are included in this projection (supports split shipments).

```sql
CREATE TABLE `projection_module_allocations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `projection_id` int(11) NOT NULL,
  `module_id` int(11) NOT NULL COMMENT 'FK to modules.id (module batch)',
  `wattage` int(11) NOT NULL COMMENT 'Specific wattage being allocated',
  `quantity` int(11) NOT NULL COMMENT 'Number of modules allocated',
  `pallets` int(11) DEFAULT NULL COMMENT 'Calculated or manual pallet count',
  PRIMARY KEY (`id`),
  KEY `idx_projection_id` (`projection_id`),
  CONSTRAINT `fk_allocation_projection` FOREIGN KEY (`projection_id`) REFERENCES `delivery_projections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Table 3: `projection_stops`
Each stop in the journey (origin, warehouses, destination).

```sql
CREATE TABLE `projection_stops` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `projection_id` int(11) NOT NULL,
  `stop_order` int(11) NOT NULL DEFAULT 0,
  `stop_type` enum('origin','warehouse','port','customs','destination') NOT NULL,
  `location_name` varchar(255) NOT NULL,
  `location_address` varchar(500) DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `warehouse_id` int(11) DEFAULT NULL COMMENT 'FK to warehouses if existing warehouse',
  `is_customs_clearance` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Triggers customs_cleared milestone',
  `estimated_arrival_date` date DEFAULT NULL,
  `estimated_departure_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_projection_id` (`projection_id`),
  KEY `idx_stop_order` (`projection_id`, `stop_order`),
  CONSTRAINT `fk_stop_projection` FOREIGN KEY (`projection_id`) REFERENCES `delivery_projections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Table 4: `projection_stop_fees`
Fees/costs at each stop (warehousing, handling, customs, etc.).

```sql
CREATE TABLE `projection_stop_fees` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `stop_id` int(11) NOT NULL,
  `fee_type` enum('receiving','storage','outbound','customs','handling','other') NOT NULL,
  `fee_name` varchar(100) NOT NULL,
  `rate` decimal(10,2) NOT NULL,
  `rate_unit` enum('per_pallet','per_module','per_truck','per_day','flat') NOT NULL DEFAULT 'per_pallet',
  `quantity` int(11) DEFAULT NULL COMMENT 'If null, calculated from modules',
  `duration_days` int(11) DEFAULT NULL COMMENT 'For storage fees',
  `estimated_cost` decimal(12,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_stop_id` (`stop_id`),
  CONSTRAINT `fk_fee_stop` FOREIGN KEY (`stop_id`) REFERENCES `projection_stops` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Table 5: `projection_legs`
Shipping/delivery legs between stops.

```sql
CREATE TABLE `projection_legs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `projection_id` int(11) NOT NULL,
  `from_stop_id` int(11) NOT NULL,
  `to_stop_id` int(11) NOT NULL,
  `leg_order` int(11) NOT NULL DEFAULT 0,
  `transport_mode` enum('ocean','truck','rail','air') NOT NULL DEFAULT 'truck',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `delivery_rate` decimal(5,2) DEFAULT NULL COMMENT 'e.g., trucks per week',
  `delivery_rate_unit` enum('per_day','per_week','per_month') DEFAULT 'per_week',
  `trucks_required` int(11) DEFAULT NULL,
  `freight_cost_per_truck` decimal(10,2) DEFAULT NULL,
  `accessorial_cost_per_truck` decimal(10,2) DEFAULT NULL,
  `total_freight_cost` decimal(12,2) DEFAULT NULL,
  `triggers_milestone` enum('shipping','customs_cleared','project_delivery') DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_projection_id` (`projection_id`),
  KEY `idx_leg_order` (`projection_id`, `leg_order`),
  CONSTRAINT `fk_leg_projection` FOREIGN KEY (`projection_id`) REFERENCES `delivery_projections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_leg_from_stop` FOREIGN KEY (`from_stop_id`) REFERENCES `projection_stops` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_leg_to_stop` FOREIGN KEY (`to_stop_id`) REFERENCES `projection_stops` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Table 6: `projection_cost_summary`
Cached cost summary for quick retrieval.

```sql
CREATE TABLE `projection_cost_summary` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `projection_id` int(11) NOT NULL,
  `module_contract_value` decimal(12,2) NOT NULL DEFAULT 0,
  `total_milestone_payments` decimal(12,2) NOT NULL DEFAULT 0,
  `total_freight_cost` decimal(12,2) NOT NULL DEFAULT 0,
  `total_warehousing_cost` decimal(12,2) NOT NULL DEFAULT 0,
  `total_customs_cost` decimal(12,2) NOT NULL DEFAULT 0,
  `total_other_cost` decimal(12,2) NOT NULL DEFAULT 0,
  `grand_total` decimal(12,2) NOT NULL DEFAULT 0,
  `last_calculated` timestamp DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_projection_id` (`projection_id`),
  CONSTRAINT `fk_summary_projection` FOREIGN KEY (`projection_id`) REFERENCES `delivery_projections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## UI Design

### Page Structure: `anticipated_deliveries.php` (Enhanced)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ 📦 Delivery & Cost Planning                              [SP Solar ▼]      │
│ Plan your complete delivery journey from manufacturer to jobsite            │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│ ┌─────────────────────────────────┐  ┌────────────────────────────────────┐│
│ │ PROJECTION                      │  │ 🗺️ ROUTE MAP                       ││
│ │ [Default Projection ▼] [+ New]  │  │                                    ││
│ │                                 │  │    ┌───┐                           ││
│ │ Status: Draft                   │  │    │ 📍│ Ningbo, China             ││
│ │ Created: Jan 19, 2026           │  │    └─┬─┘                           ││
│ │                                 │  │      │                             ││
│ │ [Save] [Save as Template]       │  │      ▼ 🚢                          ││
│ └─────────────────────────────────┘  │    ┌───┐                           ││
│                                      │    │ 📍│ LA Port                   ││
│ ┌─────────────────────────────────┐  │    └─┬─┘                           ││
│ │ 📦 MODULES INCLUDED             │  │      │                             ││
│ │                                 │  │      ▼ 🚚                          ││
│ │ Test Mfg - 555W                 │  │    ┌───┐                           ││
│ │ ├── 500 modules (32 pallets)    │  │    │ 📍│ Phoenix, AZ               ││
│ │ ├── Contract: $69,375           │  │    └───┘                           ││
│ │ └── Milestones configured ✓     │  │                                    ││
│ │                                 │  │ Total Distance: 7,842 mi           ││
│ │ [+ Add Module Batch]            │  │ Est. Transit: 45 days              ││
│ └─────────────────────────────────┘  └────────────────────────────────────┘│
│                                                                             │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│ 📍 JOURNEY PLANNER                                                          │
│ ═══════════════════════════════════════════════════════════════════════════│
│                                                                             │
│ ┌─ ORIGIN ──────────────────────────────────────────────────────────────┐  │
│ │ 🏭 Manufacturer: Ningbo, China                                         │  │
│ │    └── PO Execution milestone: $20,812 ✓ Triggered Jan 5, 2026        │  │
│ └────────────────────────────────────────────────────────────────────────┘  │
│         │                                                                   │
│         │  ┌─ SHIPPING LEG 1 ────────────────────────────────────────────┐ │
│         │  │ 🚢 Ocean Freight to LA Port                                  │ │
│         ▼  │                                                              │ │
│            │ Start Date: [Feb 1, 2026    ]  Transport: [Ocean      ▼]    │ │
│            │ Est. Arrival: [Feb 28, 2026 ]  Trucks: 4 (auto-calculated)  │ │
│            │                                                              │ │
│            │ Freight Cost:  [$3,500 ] /truck    Total: $14,000           │ │
│            │ Accessorial:   [$200   ] /truck    Total: $800              │ │
│            │                                    ─────────────             │ │
│            │                                    Leg Total: $14,800        │ │
│            │                                                              │ │
│            │ → Triggers: SHIPPING milestone ($13,875)                     │ │
│            └──────────────────────────────────────────────────────────────┘ │
│         │                                                                   │
│         ▼                                                                   │
│ ┌─ STOP 1: WAREHOUSE ────────────────────────────────────────────────────┐ │
│ │ 📦 LA Port Warehouse                                      [✎] [🗑️]    │ │
│ │    123 Harbor Blvd, Los Angeles, CA                                    │ │
│ │                                                                        │ │
│ │ ☑️ Customs Clearance Point  → Triggers: CUSTOMS CLEARED ($13,875)      │ │
│ │                                                                        │ │
│ │ Arrival: Feb 28, 2026    Departure: Mar 15, 2026    Duration: 15 days │ │
│ │                                                                        │ │
│ │ ┌─ FEES ─────────────────────────────────────────────────────────────┐ │ │
│ │ │ Fee Type        │ Rate      │ Unit       │ Qty  │ Est. Cost        │ │ │
│ │ ├─────────────────┼───────────┼────────────┼──────┼──────────────────┤ │ │
│ │ │ Receiving       │ $25.00    │ per pallet │ 32   │ $800.00          │ │ │
│ │ │ Storage         │ $15.00    │ per pallet │ 32   │ $480.00 (15 days)│ │ │
│ │ │ Customs Fees    │ $150.00   │ per pallet │ 32   │ $4,800.00        │ │ │
│ │ │ Outbound        │ $25.00    │ per pallet │ 32   │ $800.00          │ │ │
│ │ ├─────────────────┴───────────┴────────────┴──────┼──────────────────┤ │ │
│ │ │                                    Stop Total:  │ $6,880.00        │ │ │
│ │ └─────────────────────────────────────────────────┴──────────────────┘ │ │
│ │                                                                        │ │
│ │ [+ Add Fee]                                                            │ │
│ └────────────────────────────────────────────────────────────────────────┘ │
│         │                                                                   │
│         │  ┌─ DELIVERY LEG 2 ────────────────────────────────────────────┐ │
│         │  │ 🚚 Truck Delivery to Jobsite                                 │ │
│         ▼  │                                                              │ │
│            │ Start Date: [Mar 15, 2026   ]  Transport: [Truck     ▼]     │ │
│            │ Delivery Rate: [4] trucks per [week ▼]                       │ │
│            │ Est. Completion: Mar 22, 2026                                │ │
│            │                                                              │ │
│            │ Freight Cost:  [$2,000 ] /truck    Total: $8,000            │ │
│            │ Accessorial:   [$150   ] /truck    Total: $600              │ │
│            │                                    ─────────────             │ │
│            │                                    Leg Total: $8,600         │ │
│            │                                                              │ │
│            │ → Triggers: PROJECT DELIVERY milestone ($20,812)             │ │
│            └──────────────────────────────────────────────────────────────┘ │
│         │                                                                   │
│         ▼                                                                   │
│ ┌─ DESTINATION ─────────────────────────────────────────────────────────┐  │
│ │ 🏗️ SP Solar - Phoenix, AZ                                              │  │
│ │    Est. Delivery Complete: Mar 22, 2026                                │  │
│ └────────────────────────────────────────────────────────────────────────┘  │
│                                                                             │
│                                              [+ Add Warehouse Stop]         │
│                                                                             │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│ 💰 COST SUMMARY                                                             │
│ ═══════════════════════════════════════════════════════════════════════════│
│                                                                             │
│ ┌─────────────────────────────────────┬─────────────────────────────────┐  │
│ │ PROJECTED COSTS                     │ MILESTONE PAYMENTS               │  │
│ ├─────────────────────────────────────┼─────────────────────────────────┤  │
│ │ Module Contract Value    $69,375.00 │ PO Execution (30%)   $20,812.50 │  │
│ │                                     │ Shipping (20%)       $13,875.00 │  │
│ │ Freight (Leg 1)          $14,800.00 │ Customs Cleared (20%) $13,875.00│  │
│ │ Warehousing (Stop 1)      $6,880.00 │ Project Delivery (30%)$20,812.50│  │
│ │ Freight (Leg 2)           $8,600.00 │ ────────────────────────────────│  │
│ │ ────────────────────────────────────│ Total Milestones     $69,375.00 │  │
│ │ Total Logistics          $30,280.00 │                                 │  │
│ │                                     │                                 │  │
│ │ ════════════════════════════════════│                                 │  │
│ │ GRAND TOTAL              $99,655.00 │                                 │  │
│ └─────────────────────────────────────┴─────────────────────────────────┘  │
│                                                                             │
│ ┌─ PROJECTED TIMELINE ──────────────────────────────────────────────────┐  │
│ │                                                                        │  │
│ │ Jan 5      Feb 1       Feb 28      Mar 15      Mar 22                 │  │
│ │   │          │           │           │           │                    │  │
│ │   ▼          ▼           ▼           ▼           ▼                    │  │
│ │  PO ✓    Ship Start   Arrive LA   Depart LA   Complete                │  │
│ │ $20,812   $13,875      $13,875               $20,812                  │  │
│ │           Shipping     Customs               Delivery                  │  │
│ │                                                                        │  │
│ │ Cumulative: $20,812 → $34,687 → $48,562 → ... → $99,655               │  │
│ └────────────────────────────────────────────────────────────────────────┘  │
│                                                                             │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│ 📊 ACTUAL VS PROJECTED (if actual deliveries exist)                         │
│ ═══════════════════════════════════════════════════════════════════════════│
│                                                                             │
│ ┌────────────────────┬──────────────┬──────────────┬───────────┐           │
│ │ Category           │ Projected    │ Actual       │ Variance  │           │
│ ├────────────────────┼──────────────┼──────────────┼───────────┤           │
│ │ Milestone Payments │ $69,375.00   │ $34,687.50   │ 50%       │           │
│ │ Freight Costs      │ $23,400.00   │ $14,800.00   │ 63%       │           │
│ │ Warehousing        │ $6,880.00    │ $2,080.00    │ 30%       │           │
│ │ ───────────────────┼──────────────┼──────────────┼───────────│           │
│ │ TOTAL              │ $99,655.00   │ $51,567.50   │ 52%       │           │
│ └────────────────────┴──────────────┴──────────────┴───────────┘           │
│                                                                             │
│ 2 of 4 deliveries completed • On track for Mar 22 completion               │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Component Breakdown

### 1. Projection Header
- Project selector (pre-selected if coming from project_overview)
- Projection selector (switch between multiple projections)
- Create new projection
- Save / Save as Template buttons
- Status indicator (Draft/Active/Archived)

### 2. Module Selector
- Shows all module batches for the project
- Displays: quantity, pallets, wattage, contract value
- Shows milestone configuration status
- Allows partial allocation (for split shipments)
- Auto-calculates pallets from modules_per_pallet

### 3. Route Map (Google Maps)
- Visual representation of the journey
- Markers at each stop (origin, warehouses, destination)
- Polylines connecting stops
- Click markers for stop details
- Similar implementation to `module_movement_maps.php`

### 4. Journey Planner
- **Origin** - Auto-populated from module batch manufacturer info
- **Stops** - Add/edit/remove warehouse stops
  - Location (address with geocoding)
  - Or select existing warehouse from system
  - Customs clearance checkbox
  - Date range (arrival/departure)
  - Fee configuration (like cost_estimate_calculator)
- **Legs** - Shipping between stops
  - Transport mode (ocean, truck, rail, air)
  - Date range
  - Delivery rate (trucks per week, etc.)
  - Freight cost per truck
  - Accessorial costs
  - Shows which milestone triggers
- **Destination** - Auto-populated from project address

### 5. Cost Summary
- Module contract value
- Breakdown by category (freight, warehousing, customs, etc.)
- Milestone payment schedule
- Grand total
- Projected timeline with cumulative costs

### 6. Actual vs Projected
- Only shown if actual deliveries exist
- Side-by-side comparison
- Variance calculation
- Progress indicator

---

## Smart Features

### Auto-Calculations
1. **Pallets** = modules ÷ modules_per_pallet (from module batch)
2. **Trucks** = pallets ÷ pallets_per_truck (from module batch)
3. **Storage cost** = rate × pallets × (duration_days ÷ 30)
4. **Transit time** = based on transport mode and distance
5. **Milestone amounts** = contract_value × milestone_percentage

### Milestone Triggers
| Action | Milestone Triggered |
|--------|---------------------|
| PO Execution | When module batch is created (already implemented) |
| First shipping leg starts | Shipping milestone |
| Arrive at customs clearance stop | Customs Cleared milestone |
| Final delivery to destination | Project Delivery milestone |

### Validation
- Total allocated modules cannot exceed batch quantity
- Stops must be in chronological order
- Departure date must be after arrival date
- At least one leg required between consecutive stops

---

## API Endpoints

### `api/projection_save.php`
- Save/update projection with all stops, legs, fees
- Recalculate cost summary
- Handle template saving

### `api/projection_load.php`
- Load projection with all related data
- Include module batch info
- Include actual delivery data for comparison

### `api/projection_delete.php`
- Delete projection (with confirmation)
- Cannot delete if is_primary (must reassign first)

### `api/projection_set_primary.php`
- Set a projection as primary
- Unset previous primary

### `api/projection_calculate.php`
- Real-time cost calculation
- Returns updated totals as user edits

### `api/geocode_address.php`
- Geocode address for map display
- Cache results to avoid repeated API calls

---

## Files to Create/Modify

### New Files
- `migrations/add_delivery_projections.sql` - Database migration
- `projection_helpers.php` - Core projection functions
- `components/projection_journey_planner.php` - Journey planner UI
- `components/projection_stop_editor.php` - Stop configuration modal
- `components/projection_leg_editor.php` - Leg configuration
- `components/projection_cost_summary.php` - Cost summary display
- `components/projection_map.php` - Google Maps integration
- `api/projection_save.php`
- `api/projection_load.php`
- `api/projection_delete.php`
- `api/projection_set_primary.php`
- `api/projection_calculate.php`

### Already Created Files (Entry Points)
- ✅ `components/anticipated_deliveries_portfolio.php` - Project selector grid for portfolio mode

### Modified Files
- ✅ `header.php` - Added "Project Planning" nav link (Projects dropdown)
- ✅ `anticipated_deliveries.php` - Added portfolio mode handling (when no project_id)
- ✅ `components/project_overview/views_unified.php` - Added forecast badge below Shipping step
- ✅ `components/project_overview/project_overview.css` - Added badge styles (Solterra colors)
- `anticipated_deliveries.php` - Still needs complete overhaul with new UI (Steps 2-6)

---

## Implementation Sequence

### Step 1: Database
- [ ] Create migration file
- [ ] Run migration
- [ ] Create projection_helpers.php with basic CRUD functions

### Step 2: Core UI Structure
- [ ] Rebuild anticipated_deliveries.php layout
- [ ] Add projection selector/header
- [ ] Add module selector component
- [ ] Basic save/load functionality

### Step 3: Journey Planner
- [ ] Origin display (from module batch)
- [ ] Destination display (from project)
- [ ] Add/edit stops (warehouses)
- [ ] Add/edit legs (shipping between stops)
- [ ] Fee configuration for stops

### Step 4: Cost Calculations
- [ ] Real-time cost calculation
- [ ] Milestone tracking and display
- [ ] Cost summary component
- [ ] Projected timeline

### Step 5: Map Integration
- [ ] Google Maps component
- [ ] Markers for each stop
- [ ] Route polylines
- [ ] Interactive stop selection

### Step 6: Comparison & Templates
- [ ] Actual vs Projected comparison
- [ ] Save as template functionality
- [ ] Load from template

### Step 7: Integration (PARTIALLY COMPLETE)
- [x] Add forecast badge to project_overview timeline
- [ ] Show projection summary in project_overview
- [x] Link from shipping step to planner
- [x] Add header navigation entry point
- [x] Create portfolio project selector page

---

## Role Restrictions

Only these roles can create/edit projections:
- `admin`
- `global_admin`
- `customer_admin`

All authenticated users can view projections.

---

## Success Criteria

- [ ] User can create a projection with multiple stops and legs
- [ ] Costs auto-calculate based on entered rates
- [ ] Milestones show when they'll trigger
- [ ] Map displays the complete route
- [ ] Projections can be saved as templates
- [ ] Actual vs projected comparison works
- [ ] Projection summary shows on project_overview
- [x] Badge on timeline indicates projection status (DONE - shows "Plan Set" or "Add Plan")
- [x] Entry point in header navigation (DONE - "Project Planning" link)
- [x] Portfolio-level project selector (DONE - `anticipated_deliveries_portfolio.php`)

---

## Future Enhancements (Post Phase 3)

1. **Portfolio-level planning** - Plan for projects that don't exist yet
2. **Scenario comparison** - Side-by-side view of different projections
3. **Import from template** - Quick-start from saved templates
4. **Notifications** - Alert when actual costs deviate from projected
5. **PDF export** - Generate projection report
6. **Bulk operations** - Apply same projection to multiple batches

---

*Document created: January 19, 2026*
*Last updated: January 19, 2026 - UI Entry Points completed*
