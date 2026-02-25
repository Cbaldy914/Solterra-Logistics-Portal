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
| dashboard.php | Dashboard | all_read | user, customer_admin, admin, global_admin | project_overview, module_cost_analysis, warehousing_overview, manage_deliveries |
| project_overview.php | Project Overview | all_read_with_admin_actions | user, customer_admin, admin, global_admin | create_shipment, scheduling, project_photos, module_overview |
| add_project.php | Add Project | account_admins_only | customer_admin, admin, global_admin | manage_projects, project_overview, project_planning |
| manage_projects.php | Manage Projects | account_admins_only | customer_admin, admin, global_admin | project_overview, archived_projects, project_planning |
| archived_projects.php | Archived Projects | all_read_with_admin_actions | user, customer_admin, admin, global_admin | manage_projects, project_overview |
| project_planning.php | Project Planning | account_admins_only | customer_admin, admin, global_admin | anticipated_deliveries, project_overview, module_cost_analysis |
| anticipated_deliveries.php | Anticipated Deliveries | all_read_with_admin_actions | user, customer_admin, admin, global_admin | project_planning, project_overview, module_cost_analysis |
| admin_project_forecast.php | Admin Project Forecast | global_only | global_admin | module_cost_analysis, accounting, dashboard |
| project_site.php | Link Project to Site | global_only | global_admin | add_project, scheduling, project_overview |
| module_cost_analysis.php | Module Cost Analysis | all_read | user, customer_admin, admin, global_admin | project_overview, invoices_all, accounting |
| sustainability_overview.php | Sustainability Overview | all_read | user, customer_admin, admin, global_admin | modules, module_overview, project_overview |
| modules.php | Manage Modules | all_read_with_admin_actions | user, customer_admin, admin, global_admin | add_module_batch, module_overview, module_movements, manage_pallets |
| add_module_batch.php | Add Module Batch | account_admins_only | customer_admin, admin, global_admin | modules, module_overview, manufacturers |
| module_overview.php | Module Overview | all_read_with_admin_actions | user, customer_admin, admin, global_admin | modules, manage_pallets, create_shipment, module_movements |
| module_movements.php | Module Movements | all_read_with_admin_actions | user, customer_admin, admin, global_admin | pallet_details, project_overview, warehouse |
| manage_pallets.php | Manage Pallets | all_read | user, customer_admin, admin, global_admin | pallet_details, module_overview, create_shipment, module_movements |
| pallet_details.php | Pallet Details | all_read | user, customer_admin, admin, global_admin | module_movements, manage_pallets, create_shipment |
| create_shipment.php | Create Shipment | shipment_workspace | user, customer_admin, admin, global_admin | manage_pallets, manage_deliveries, container_tracking, generate_bol |
| manage_deliveries.php | Manage Deliveries | all_read_with_admin_actions | user, customer_admin, admin, global_admin | view_project, scheduling, pods |
| container_tracking.php | Container Tracking | all_read_with_admin_actions | user, customer_admin, admin, global_admin | create_shipment, manage_deliveries, warehouse |
| scheduling.php | Scheduling | all_read_with_admin_actions | user, customer_admin, admin, global_admin | manage_deliveries, pods, project_overview |
| bills_of_lading.php | Bills of Lading | all_read | user, customer_admin, admin, global_admin | create_shipment, manage_deliveries, pods |
| pods.php | Proof of Delivery | all_read_with_admin_actions | user, customer_admin, admin, global_admin | scheduling, manage_deliveries, global_documents |
| ftd.php | Flash Test Data | all_read | user, customer_admin, admin, global_admin | module_overview, global_documents, project_overview |
| freight_estimate.php | Freight Estimate | all_personal_workspace | user, customer_admin, admin, global_admin | admin_freight_estimates, create_shipment, module_cost_analysis |
| admin_freight_estimates.php | Admin Freight Estimates | account_admins_only | customer_admin, admin, global_admin | freight_estimate, create_shipment, module_cost_analysis |
| generate_bol.php | Generate BOL | account_admins_only | customer_admin, admin, global_admin | create_shipment, bills_of_lading, global_documents |
| warehousing_overview.php | Warehousing Overview | all_read_with_admin_actions | user, customer_admin, admin, global_admin | warehouse, cost_estimate_calculator, warehouse_optimization, warehouse_estimate |
| warehouse.php | Warehouse Inventory | all_read_with_admin_actions | user, customer_admin, admin, global_admin | warehousing_overview, create_shipment, module_movements, pods |
| add_warehouse.php | Add Warehouse | account_admins_only | customer_admin, admin, global_admin | warehousing_overview, warehouse, admin_warehouse_estimate |
| admin_warehouse_estimate.php | Admin Warehouse Estimate | account_admins_only | customer_admin, admin, global_admin | warehouse_estimate, warehousing_overview, accounting |
| warehouse_estimate.php | Warehouse Estimate | all_personal_workspace | user, customer_admin, admin, global_admin | admin_warehouse_estimate, warehousing_overview |
| cost_estimate_calculator.php | Cost Estimate Calculator | all_personal_workspace | user, customer_admin, admin, global_admin | warehousing_overview, warehouse_estimate, warehouse_optimization |
| warehouse_optimization.php | Warehouse Optimization | all_personal_workspace | user, customer_admin, admin, global_admin | cost_estimate_calculator, warehousing_overview |
| manufacturer_overview.php | Manufacturer Overview | all_read | user, customer_admin, admin, global_admin | manufacturers, add_manufacturer, modules |
| manufacturers.php | Manufacturers | custom | customer_admin, admin, global_admin | manufacturer_overview, add_manufacturer, modules |
| add_manufacturer.php | Add Manufacturer | custom | customer_admin, admin, global_admin | manufacturers, manufacturer_overview |
| documents.php | Project Documents | documents_workspace | user, customer_admin, admin, global_admin | global_documents, project_overview, pods, bills_of_lading |
| global_documents.php | Global Documents | documents_workspace | user, customer_admin, admin, global_admin | documents, bills_of_lading, pods, ftd |
| project_photos.php | Project Photos | all_read_with_admin_actions | user, customer_admin, admin, global_admin | project_overview, incident_reports, warranty |
| incident_reports.php | Incident Reports | all_read_with_admin_actions | user, customer_admin, admin, global_admin | scheduling, project_overview, project_photos |
| warranty.php | Warranty Claims | all_read_with_admin_actions | user, customer_admin, admin, global_admin | warranty_create, warranty_detail, project_overview |
| account_settings.php | Account Settings | custom | user, customer_admin, admin, global_admin | notifications, questions |
| notifications.php | Notifications | custom | user, customer_admin, admin, global_admin | account_settings, documents, project_overview |
| questions.php | Questions and Support | custom | user, customer_admin, admin, global_admin | account_settings |
| invoices_all.php | Invoices | all_read | user, customer_admin, admin, global_admin | module_cost_analysis, add_invoice, generate_invoice |
| add_invoice.php | Add Invoice | global_only | global_admin | invoices_all, generate_invoice, accounting |
| generate_invoice.php | Generate Invoice | global_only | global_admin | add_invoice, invoices_all, accounting |
| accounting.php | Accounting Overview | global_only | global_admin | add_invoice, accounts_payable, total_payables, generate_invoice |
| accounts_payable.php | Accounts Payable | global_only | global_admin | accounting, total_payables |
| total_payables.php | Total Payables | global_only | global_admin | accounts_payable, accounting |
| admin_management.php | Admin Management | global_only | global_admin | account_settings |
| ddpm_overview.php | DDPm Overview (Legacy) | all_read | user, customer_admin, admin, global_admin | project_overview, dashboard |
| ddpm_deliveries.php | DDPm Deliveries (Legacy) | all_read | user, customer_admin, admin, global_admin | view_project, manage_deliveries, project_overview |
| accounts_payable_info.php | Accounts Payable Detail | global_only | global_admin | accounts_payable, accounting, total_payables |
| add_delivery.php | Add Delivery | account_admins_only | customer_admin, admin, global_admin | manage_deliveries, create_shipment, scheduling |
| add_manufacturer_location.php | Add Manufacturer Location | custom | customer_admin, admin, global_admin | manufacturer_locations, manufacturers, add_manufacturer |
| admin_freight_estimate_view.php | Admin Freight Estimate Detail | account_admins_only | customer_admin, admin, global_admin | admin_freight_estimates, freight_estimate |
| admin_login_attempts.php | Admin Login Attempts | global_only | global_admin | admin_management, account_settings |
| admin_warehouse_estimate_view.php | Admin Warehouse Estimate Detail | account_admins_only | customer_admin, admin, global_admin | admin_warehouse_estimate, warehouse_estimate |
| assign_warehouse.php | Assign Warehouse (Legacy) | global_only | global_admin | warehousing_overview, warehouse, manage_projects |
| calculator_results.php | Calculator Results | all_personal_workspace | user, customer_admin, admin, global_admin | cost_estimate_calculator, view_estimate |
| create_replacements.php | Create Replacements | account_admins_only | customer_admin, admin, global_admin | warranty, warranty_detail, warranty_create |
| edit_delivery.php | Edit Delivery | account_admins_only | customer_admin, admin, global_admin | manage_deliveries, scheduling, pods |
| edit_future_project.php | Edit Future Project | all_personal_workspace | user, customer_admin, admin, global_admin | future_projects, future_projects_details, view_forecast |
| edit_invoice.php | Edit Invoice | global_only | global_admin | invoices_all, add_invoice, generate_invoice |
| edit_manufacturer.php | Edit Manufacturer | custom | customer_admin, admin, global_admin | manufacturers, manufacturer_overview, manufacturer_locations |
| edit_manufacturer_location.php | Edit Manufacturer Location | custom | customer_admin, admin, global_admin | manufacturer_locations, add_manufacturer_location |
| edit_module.php | Edit Module (Legacy Redirect) | account_admins_only | customer_admin, admin, global_admin | edit_module_batch, module_overview, modules |
| edit_module_batch.php | Edit Module Batch | account_admins_only | customer_admin, admin, global_admin | module_overview, modules, add_module_batch |
| edit_pallet.php | Edit Pallet | account_admins_only | customer_admin, admin, global_admin | pallet_details, manage_pallets, module_movements |
| edit_project.php | Edit Project | account_admins_only | customer_admin, admin, global_admin | project_overview, manage_projects, project_planning |
| edit_warehouse.php | Edit Warehouse | account_admins_only | customer_admin, admin, global_admin | warehouse, warehousing_overview, add_warehouse |
| future_projects.php | Future Projects | all_personal_workspace | user, customer_admin, admin, global_admin | future_projects_details, edit_future_project, view_forecast |
| future_projects_details.php | Future Project Details | all_personal_workspace | user, customer_admin, admin, global_admin | future_projects, edit_future_project, view_forecast |
| invoice_info.php | Invoice Info | global_only | global_admin | generate_invoice, invoices_all, accounts_payable_info |
| invoices.php | Project Invoices (Legacy) | all_read | user, customer_admin, admin, global_admin | invoices_all, project_overview, module_cost_analysis |
| link_pallet_deliveries.php | Link Pallets to Deliveries | account_admins_only | customer_admin, admin, global_admin | manage_delivery_pallets, manage_deliveries, create_shipment |
| manage_delivery_pallets.php | Manage Delivery Pallets | account_admins_only | customer_admin, admin, global_admin | link_pallet_deliveries, edit_delivery, manage_deliveries |
| manage_port_inventory.php | Manage Port Inventory | account_admins_only | customer_admin, admin, global_admin | warehouse, warehousing_overview, container_tracking |
| manufacturer_details.php | Manufacturer Details | all_read | user, customer_admin, admin, global_admin | manufacturer_overview, manufacturers, modules |
| manufacturer_locations.php | Manufacturer Locations | custom | customer_admin, admin, global_admin | manufacturers, add_manufacturer_location, edit_manufacturer_location |
| modules_docs.php | Module Documents (Legacy) | all_read | user, customer_admin, admin, global_admin | project_documents, global_documents, module_overview |
| project_close.php | Project Close-Out | all_read_with_admin_actions | user, customer_admin, admin, global_admin | project_overview, archived_projects, project_documents |
| project_cost_details.php | Project Cost Details | all_read | user, customer_admin, admin, global_admin | module_cost_analysis, project_overview, invoices_all |
| project_documents.php | Project Documents (Legacy) | documents_workspace | user, customer_admin, admin, global_admin | documents, global_documents, project_overview |
| project_information.php | Project Information | all_read | user, customer_admin, admin, global_admin | project_overview, manage_projects, edit_project |
| project_overview_backup.php | Project Overview Backup (Legacy) | all_read_with_admin_actions | user, customer_admin, admin, global_admin | project_overview, module_overview, create_shipment |
| project_safety.php | Project Safety | all_read | user, customer_admin, admin, global_admin | incident_reports, project_overview |
| project_sustainability_details.php | Project Sustainability Details | all_read | user, customer_admin, admin, global_admin | sustainability_overview, project_overview, module_overview |
| scenario_detail.php | Scenario Detail | all_read_with_admin_actions | user, customer_admin, admin, global_admin | project_planning, anticipated_deliveries, module_cost_analysis |
| upload_manufacturer_links.php | Upload Manufacturer Links | account_admins_only | customer_admin, admin, global_admin | upload_pallets, module_overview, manage_pallets |
| upload_manufacturer_schedule.php | Upload Manufacturer Schedule | account_admins_only | customer_admin, admin, global_admin | upload_pallets, upload_shipments |
| upload_pallets.php | Upload Pallets | account_admins_only | customer_admin, admin, global_admin | upload_manufacturer_schedule, module_overview, modules |
| upload_pod.php | Upload POD | account_admins_only | customer_admin, admin, global_admin | pods, edit_delivery, manage_deliveries |
| upload_shipments.php | Upload Shipments | account_admins_only | customer_admin, admin, global_admin | upload_manufacturer_schedule, manage_deliveries, create_shipment |
| view_estimate.php | View Cost Estimate | all_personal_workspace | user, customer_admin, admin, global_admin | cost_estimate_calculator, calculator_results |
| view_forecast.php | View Forecast | all_personal_workspace | user, customer_admin, admin, global_admin | future_projects, future_projects_details, edit_future_project |
| view_freight_estimate.php | View Freight Estimate | all_personal_workspace | user, customer_admin, admin, global_admin | freight_estimate, admin_freight_estimates |
| view_project.php | Project Delivery Tracker | all_read_with_admin_actions | user, customer_admin, admin, global_admin | manage_deliveries, scheduling, pods, create_shipment |
| view_warehouse_estimate.php | View Warehouse Estimate | all_personal_workspace | user, customer_admin, admin, global_admin | warehouse_estimate, admin_warehouse_estimate |
| warehouses.php | Warehouses (Legacy Global List) | global_only | global_admin | warehousing_overview, warehouse, add_warehouse |
| warranty_create.php | Create Warranty Claim | account_admins_only | customer_admin, admin, global_admin | warranty, warranty_detail, create_replacements |
| warranty_detail.php | Warranty Claim Detail | all_read_with_admin_actions | user, customer_admin, admin, global_admin | warranty, warranty_create, create_replacements |

Total modeled pages: 104

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
