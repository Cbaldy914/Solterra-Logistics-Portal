# Customer Admin UX Brainstorm (Licensable Portal)

## Goal
Make `customer_admin` feel like a self-serve operations product for customers: easy to navigate, easy to learn, and hard to misuse.

## What I looked at
- `header.php` navigation and role-specific menu structure
- `dashboard.php`
- `project_overview.php`
- `create_shipment.php`
- `manage_warehouse_inventory.php`
- `scheduling.php`
- `warehousing_overview.php`
- `anticipated_deliveries.php`
- `project_planning.php`
- `modules.php` and `module_overview.php`
- `documents.php` and `global_documents.php`
- `questions.php`
- Sunny integration in header + config (`ai-assistant/components/sunny-chat.php`, `ai-assistant/config/sunny-config.php`)

## Top friction areas (highest priority)
1. Shipment creation flow is powerful but cognitively heavy for first-time customer admins.
2. Wayfinding from `project_overview` to operational actions (create shipment, receive, schedule) can be clearer.
3. Receiving and scheduling pages have many states/modals and rely on user memory of process order.
4. Terminology is operations-heavy; some users will need just-in-time definitions and examples.
5. Documents and support exist, but users need context-sensitive guidance exactly where they are working.

## UX principles for this role
1. Task-first over page-first: users think in goals, not file/page names.
2. Show “what to do next” everywhere.
3. Progressive disclosure: basic path first, advanced options second.
4. Contextual help over generic help pages.
5. Keep customer admins in their project/account scope by default.

## Recommended information architecture changes
1. Add a persistent “Quick Actions” area in the header or dashboard with:
- Create Shipment
- Receive Into Warehouse
- Schedule Delivery
- Upload Documents
- View Open Exceptions

2. Add a task-oriented landing view for customer_admin (new dashboard section):
- “Shipments to Create”
- “Receipts Pending”
- “Deliveries to Schedule”
- “Issues Requiring Action”

3. Rename or clarify menu labels where needed:
- `Manage Deliveries` currently redirects to project-level views; label should reflect destination.
- Distinguish clearly between “Warehouse Overview” (status) and “Receive Inventory” (action).

## Page-by-page optimization ideas

### 1) Project Overview (`project_overview.php`)
1. Add a sticky “Project Actions” card near top with primary CTAs:
- Create Shipment
- Receive Inventory
- Schedule Delivery
- Upload Project Docs

2. Add a “Project Workflow” strip:
- Plan -> Ship -> Receive -> Schedule -> Complete
- Each stage shows status + direct CTA.

3. Add short helper copy under CTAs:
- Example: “Create a shipment from manufacturer or warehouse inventory.”

### 2) Create Shipment (`create_shipment.php`)
1. Convert to explicit wizard mental model (even if technically same page):
- Step 1: Choose source (Manufacturer / Warehouse / Overseas)
- Step 2: Select project + inventory
- Step 3: Configure shipment details
- Step 4: Review + create

2. Add a scenario picker at top:
- “I am shipping new production modules”
- “I am shipping modules already in warehouse”
- “I am shipping overseas/container flow”

3. Add a right-side “Need help?” panel:
- Required fields checklist
- Definitions (BOL, drayage, status)
- Common mistakes to avoid

4. Add better empty/error states:
- If no eligible inventory, explain exactly why and link to next action.

5. Add post-create next steps inline (not only flash messages):
- “Now schedule delivery”
- “Now notify warehouse”
- “Upload related docs”

### 3) Warehouse Receiving (`manage_warehouse_inventory.php`)
1. Add a receiving checklist banner:
- Confirm arrival
- Mark received quantities
- Record exceptions/damage
- Upload POD/images
- Confirm storage location/status

2. Split advanced actions behind “Advanced” toggles.
3. Add guided “Receive flow” mode for new users (single-column, step-based).
4. Add clear status badges with tooltip definitions.

### 4) Scheduling (`scheduling.php`)
1. Add top “Scheduling queue” summary:
- Ready to schedule
- Scheduled upcoming
- Missed/needs reschedule
- Completed today

2. Standardize action language:
- Always use one term for status transitions (avoid multiple synonyms).

3. Add “What happens when I complete this?” helper in completion modal.
4. Add customer-facing safeguards:
- Warn before destructive scheduling changes.
- Show impacted parties and notifications before confirm.

### 5) Documents (`documents.php`, `global_documents.php`)
1. Add doc-type guidance chips near upload:
- “Use this for BOL”, “Use this for POD”, etc.

2. Add context upload entry points from operations pages:
- From shipment, receiving, scheduling screens with pre-tagged metadata.

3. Improve retrieval:
- Quick filters by project, module, shipment, date, doc type.

### 6) Dashboard (`dashboard.php`)
1. Make dashboard an action center, not just a summary board.
2. Add urgency-sorted cards:
- “Needs action today” first.

3. Add “Continue where you left off” section:
- recent project + recommended next action.

## Helpers: should we add them?
Yes. Use layered help, not one giant help center.

1. Inline helper text under complex fields (always visible).
2. “Learn more” tooltips for terminology.
3. Step checklists for complex workflows (shipment/receiving/scheduling).
4. First-time guided tours per major workflow.
5. Persistent “Need help?” panel with contextual FAQ links.

## Sunny: should Sunny answer questions?
Yes, with scoped responsibilities.

1. Sunny should handle:
- “How do I do X on this page?”
- Definitions/jargon translation
- “What should I do next?” based on current page + project context
- Direct deep-links to exact actions

2. Sunny should not silently perform risky actions.
- For changes, Sunny should guide and pre-fill, then user confirms.

3. Add page-specific Sunny quick prompts:
- Create Shipment page: “What fields are required here?”, “How do I ship from warehouse inventory?”
- Receiving page: “What is the receiving checklist?”
- Scheduling page: “How do I reschedule safely?”

4. Add “Explain this screen” button that asks Sunny with current context.

## Onboarding recommendations
1. Add role-based first-run onboarding for `customer_admin`:
- 3-minute walkthrough of core workflow.

2. Add “Start here” checklist on first login:
- Set up project
- Add/import modules
- Create first shipment
- Receive into warehouse
- Schedule first delivery

3. Provide sample/demo data mode for training accounts.

## Guardrails and clarity improvements
1. Make permissions transparent:
- If action unavailable, explain why and what to do instead.

2. Add confirmation summaries before key commits:
- Shipment creation, receiving completion, schedule changes.

3. Add consistent success states:
- “Done. Next best action is X.”

## Suggested rollout (pragmatic)
1. Phase 1 (quick wins, low effort)
- Add CTA/action cards on `project_overview` and dashboard.
- Add helper copy + glossary tooltips in shipment/receiving/scheduling.
- Add Sunny quick prompts by page.

2. Phase 2 (medium effort)
- Add shipment scenario picker and structured step sections.
- Add receiving checklist mode.
- Add scheduling queue and standardized status language.

3. Phase 3 (larger effort)
- Full workflow orchestration layer across Plan -> Ship -> Receive -> Schedule.
- Deeper Sunny context and proactive guidance.
- Demo/training environment improvements.

## Success metrics to track
1. Time-to-first-shipment for new customer_admin users.
2. Drop-off rate in shipment creation flow by step.
3. Number of support questions per active customer account.
4. Scheduling/receiving correction rate (rework events).
5. Sunny resolution rate (“question answered without support ticket”).

## Immediate next implementation targets
1. Improve discoverability from `project_overview` with a prominent "Create Shipment" task card and workflow strip.
2. Add shipment page helper architecture (scenario picker + required checklist + glossary).
3. Add receiving and scheduling checklists with clear next-step prompts.
4. Add contextual Sunny quick prompts and "Explain this screen".

---

# Claude's Deep-Dive Additions (Feb 2026)

I (Claude) read every major page in the portal end-to-end. Below are findings, additional recommendations, and a massive Quick Answers / FAQ expansion that builds on the Codex notes above. Everything is organized so either of us can pick up implementation work directly.

## What I reviewed (in addition to Codex's list)
- Full `header.php` menu structure and role-specific visibility
- `dashboard.php` (27K+ tokens) - stats cards, charts, project grid/table, health badges
- All 21 files in `components/` - breadcrumbs, milestone cards/timeline, module batch wizard, projection components, project overview modals/views
- `ai-assistant/` directory in full - Sunny chat UI (600+ lines JS), system prompt, config, all API endpoints, database migrations, fine-tuning examples
- `notification_helpers.php` and `delivery_notification_helpers.php`
- `add_project.php` and `add_warehouse.php` form help text patterns
- `warehouse_info.php` warehouse detail page
- `module_overview.php` pallet generation and management

---

## Additional friction areas I found (beyond Codex's top 5)

6. **Dashboard is status-only, not action-oriented.** Customer admins see 4 metric cards + 2 charts + project grid, but zero guidance on what needs attention. No "action items" section, no urgency sorting, no "next step" prompts anywhere.

7. **Health badge tooltips are hover-only - broken on mobile.** The project health definitions (On Track, At Risk, Behind, Completed) use CSS `:hover` tooltips that are invisible on touch devices. This is a critical accessibility gap since these badges are the primary status indicator.

8. **Payment milestones are invisible at portfolio level.** `milestone_summary_card.php` and `milestone_timeline_table.php` exist as components but are NOT surfaced on the dashboard. Customer admins with finance responsibilities have zero portfolio-level payment visibility unless they click into individual projects.

9. **No "Next Step" CTA exists on ANY page.** After adding modules, creating a shipment, receiving inventory, uploading documents, or completing any action, the user gets a flash message and nothing else. No page suggests what to do next.

10. **Menu items lack descriptions.** "Module Movements" vs "Manage Modules"? "Cost Analysis" vs "Cost Estimate Calculator"? "Warehousing Overview" vs "Manage Warehouse Inventory"? Customer admins must guess where to go.

11. **`create_shipment.php` cost fields are completely undocumented.** Freight Cost, Customer Cost, Accessorial Cost, Miles - none have help text. Customer admins don't know what each means, when to fill them, or how they affect downstream calculations.

12. **Port vs Warehouse distinction is invisible in `manage_warehouse_inventory.php`.** Same page serves both facility types but shows different grouping logic (containers vs BOL) without explaining why, and the page header doesn't indicate which type you're viewing.

13. **Scheduling page doesn't show operating hours near the time picker.** Site operating hours come from `site_operating_hours` table, but the user picks times blind - they can select 3 AM even if the site operates 8-5.

14. **Damage and safety incident reporting are buried inline.** In the scheduling edit form, "Has Damages: Yes/No" and "Has Safety Incident: Yes/No" radio buttons appear mid-form with no context on what constitutes damage or an incident, no examples, and no photo upload guidance.

15. **`anticipated_deliveries.php` journey builder is powerful but opaque.** The Module/Stop/Leg data model, the SVG route drawing UI, and the cost projection math are all undocumented. "Click location's connect button to start drawing" is the only instruction.

16. **`documents.php` shows hardcoded "9 Document Types" text** instead of actual per-project document counts. Users can't tell which projects have documents without clicking in.

17. **`global_documents.php` has no keyword search.** 8 filter dropdowns but you can't search by document name. For a customer with hundreds of documents, this is a significant friction point.

18. **Notification badge is count-only with no preview.** Users must navigate to a separate page to see any notification content. No quick-view dropdown, no urgency categorization, no action links.

19. **No bulk operations anywhere.** Adding 100 modules = 100 form submissions. Assigning modules to projects = one at a time. No CSV import, no multi-select, no templates.

20. **Breadcrumbs are missing on ~40% of pages.** `modules.php`, `warehousing_overview.php`, `module_overview.php`, `documents.php` all lack breadcrumbs, breaking navigation consistency.

---

## Existing help infrastructure audit

### What already exists (don't rebuild these)
| System | Location | Quality | Notes |
|--------|----------|---------|-------|
| **Sunny AI Assistant** | `ai-assistant/` on every page | Excellent | 14+ tools, streaming, memory, quick actions, conversation history. This is the portal's biggest UX asset. |
| **Inline form help text** | `add_project.php`, `add_warehouse.php` | Good pattern, sparse coverage | `<span class="help-text">` below fields. Only on 2 pages. |
| **Tooltip system** | `anticipated_deliveries_styles.php` | Good CSS pattern | `.tooltip-wrapper` + `.tooltip-trigger` + `.tooltip-content`. Exists but used on only 1 page. |
| **Contact form** | `questions.php` | Functional | Email to `cbaldy@solterrasol.com`, auto-fills user info, 8 subject categories. |
| **3 Quick Answer items** | `questions.php` sidebar | Grossly insufficient | Only 3 hardcoded Q&As for the entire platform. |
| **Notification system** | `notification_helpers.php` | Good backend | 10+ event types, per-user preferences, in-app + email channels. |
| **Breadcrumb component** | `components/breadcrumbs.php` | Smart but inconsistent | Auto-resolves project names, deduplicates, but not used on all pages. |
| **Multi-step form wizard** | `components/module_batch_step1-4.php` | Good pattern | 4-step progressive form for module batches. Should be the model for other complex forms. |

### What does NOT exist (needs to be built)
- Searchable FAQ / Knowledge Base
- Glossary / terminology reference
- Onboarding tour / first-run guide
- "Next step" CTA component
- Status legend component (reusable across pages)
- Getting started checklist
- Video tutorials or walkthrough library
- Page-level help panel
- Progress indicators for multi-step workflows (outside of module batch wizard)

---

## Sunny enhancement opportunities

Sunny is already the most sophisticated help system in the portal. Rather than building a separate help center, we should enhance Sunny AND build static help content that feeds Sunny's context.

### Page-specific quick prompts (expanded from Codex's list)

**Dashboard:**
- "What needs my attention today?"
- "Show me projects that are behind schedule"
- "What's my portfolio payment status?"
- "Which projects have modules in storage?"

**Project Overview:**
- "What's the next step for this project?"
- "Explain the health status for this project"
- "How do I add modules to this project?"
- "Show me delivery progress for this project"
- "What milestones have been triggered?"

**Create Shipment:**
- "What's the difference between single and multi-shipment?"
- "What fields are required?"
- "How do I ship from warehouse inventory?"
- "What is a BOL number?"
- "What are accessorial costs?"

**Warehouse Receiving:**
- "Walk me through the receiving process"
- "What does 'receiving' actually do in the system?"
- "How are storage costs calculated?"
- "What's the difference between port and warehouse?"
- "How do I report damaged pallets?"

**Scheduling:**
- "What are the operating hours for this site?"
- "How do I reschedule a delivery?"
- "What happens when I mark a delivery as completed?"
- "How do I report damage during delivery?"
- "What is Proof of Delivery?"

**Modules:**
- "What is a module batch?"
- "What does 'unassigned' mean?"
- "How do I palletize modules?"
- "What is Domestic Content % and why does it matter?"
- "What's the difference between 'ordered' and 'delivered'?"

**Documents:**
- "What document types should I upload?"
- "How do I find a specific document?"
- "What's the difference between Project Documents and Global Documents?"
- "What is a BOL document?"

**Anticipated Deliveries / Planning:**
- "How do I create a delivery projection?"
- "What is a Stop vs a Leg?"
- "How are freight costs estimated?"
- "What's the difference between projected and actual costs?"
- "How do I add a warehouse stop to my route?"

### Sunny system prompt additions (for `sunny_system_prompt.md`)
Add page-awareness so Sunny can detect which page the user is on and tailor responses:
- When on `create_shipment.php`, prioritize shipment-related tool calls
- When on `manage_warehouse_inventory.php`, lead with receiving/storage context
- When on `scheduling.php`, surface operating hours and appointment guidance
- When on `modules.php` or `module_overview.php`, explain batch/pallet concepts
- On any page, if user asks "what should I do next?", reference the workflow: Plan -> Add Modules -> Palletize -> Ship -> Receive -> Schedule -> Deliver -> Complete

---

## Glossary of terms (for both FAQ and Sunny context)

These definitions should be available in three places: (1) the FAQ/Quick Answers page, (2) as inline tooltips on relevant pages, and (3) in Sunny's system prompt so it can define terms conversationally.

| Term | Plain-language definition | Where it appears |
|------|--------------------------|------------------|
| **Module** | A single solar panel unit. Modules are grouped into batches from a manufacturer. | Everywhere |
| **Module Batch** | A group of modules from one manufacturer with the same specs. Think of it as a purchase order for panels. | modules.php, module_overview.php |
| **Wattage** | The power output rating of a solar module (e.g., 400W, 500W). Higher wattage = more powerful panel. | Module forms, delivery tables |
| **Pallet** | A standardized shipping unit that holds multiple modules. Modules must be palletized before they can be shipped. | module_overview.php, warehouse pages |
| **Palletization** | The process of organizing modules onto pallets for transport. You define how many modules fit per pallet based on dimensions. | module_overview.php |
| **Megawatt (MW)** | A unit of power equal to 1,000,000 watts. Used to describe project size. 1 MW ~ 2,000-2,500 modules depending on wattage. | Dashboard, project overview |
| **Cost per Watt ($/W)** | Total module cost divided by total wattage. The standard industry metric for comparing solar module pricing. | Dashboard, cost analysis |
| **Domestic Content %** | The percentage of a module's value that comes from US-manufactured components. Higher percentages may qualify for federal tax credits. | Module batch form |
| **BOL (Bill of Lading)** | The shipping document that serves as a receipt and contract between shipper and carrier. Every shipment gets a unique BOL number. | Shipment pages, documents |
| **Master BOL** | For overseas/container shipments: tracks the entire container across the ocean voyage. | create_shipment.php (overseas) |
| **House BOL** | For overseas/container shipments: tracks your specific portion within a shared container. | create_shipment.php (overseas) |
| **POD (Proof of Delivery)** | Documentation confirming that a shipment was received at its destination. Usually a signed receipt or photo. | Scheduling, documents |
| **Drayage** | Short-distance trucking, typically from a port to a nearby warehouse after customs clearance. | Warehouse receiving (ports) |
| **Freight Cost** | The transportation cost charged by the carrier to move a shipment. | create_shipment.php |
| **Customer Cost** | The cost that gets passed through to the customer for a shipment. May differ from freight cost. | create_shipment.php |
| **Accessorial Cost** | Additional charges beyond base freight: driver detention, lumper fees, fuel surcharges, lift-gate, etc. | create_shipment.php, anticipated_deliveries.php |
| **Warehouse** | A facility that stores palletized modules between manufacturing and final delivery to the project site. | Warehousing pages |
| **Port** | A facility that receives overseas container shipments and handles customs clearance before inland transport. | Warehouse pages (port type) |
| **Entry Fee** | A per-pallet charge when goods first arrive at a warehouse/port. | warehouse_info.php fee modal |
| **Exit Fee** | A per-pallet charge when goods leave a warehouse/port. | warehouse_info.php fee modal |
| **Monthly Storage Fee** | A recurring per-pallet charge for each month modules remain in storage. | warehouse_info.php fee modal |
| **Milestone** | A payment trigger event tied to a project lifecycle stage. Example: "Pay 30% when shipping is initiated." | Project financials |
| **Projection** | A delivery cost estimate that models the route, stops, and timeline for getting modules to a project site. | anticipated_deliveries.php |
| **Stop** | A location in a delivery projection where modules pause (e.g., a port or warehouse). | anticipated_deliveries.php |
| **Leg** | A transportation segment between two stops in a delivery projection. Each leg has its own freight cost. | anticipated_deliveries.php |
| **Flash Test Data** | Factory quality-control test results that verify each module meets its rated power output. | Module documents |
| **On Water** | Delivery status: modules are currently in transit via ocean shipping. | Delivery status displays |
| **In Transit to Warehouse** | Delivery status: modules are being trucked from manufacturer or port to a warehouse. | Delivery status displays |
| **In Transit to Project** | Delivery status: modules are being trucked from warehouse to the final project site. | Delivery status displays |
| **Delivered to Project** | Delivery status: modules have arrived at the project site and been confirmed received. | Delivery status displays |
| **Cleared Customs** | Delivery status (port only): modules have passed customs inspection and are ready for inland transport. | Port receiving |
| **In Warehouse / In Storage** | Delivery status: modules are sitting in a warehouse facility awaiting their next move. | Warehouse inventory |
| **Project Health** | An assessment of whether a project is on track relative to its completion date. Can be: On Track, At Risk, Behind, or Completed. | Dashboard, project overview |
| **On Track** | Project health status: deliveries and milestones are progressing on schedule. | Health badges |
| **At Risk** | Project health status: some delays or issues detected that could impact the completion date. | Health badges |
| **Behind** | Project health status: the project has missed key dates and needs immediate escalation. | Health badges |
| **Unassigned Modules** | Modules that have been purchased/ordered but are not yet allocated to any specific project. They're available stock. | modules.php, dashboard |
| **Module Movements** | A tracking feature that shows where modules have been and where they are now - their location history through the supply chain. | Navigation menu |
| **Sustainability** | The Domestic Content and carbon footprint reporting section. Used for compliance and tax credit documentation. | Navigation menu |

---

## Comprehensive Quick Answers / FAQ expansion

### Implementation spec for `questions.php`

**Current state:** 3 hardcoded Q&A items in a sidebar card. No search, no categories, no expand/collapse.

**Target state:** A full, searchable, categorized FAQ system on the `questions.php` page.

#### UI design:
1. **Search bar at top** - Live-filters as you type across all questions and answers
2. **Category tabs or filter chips** - Getting Started, Projects, Modules, Shipments, Warehousing, Scheduling, Documents, Billing & Milestones, Account & Access, Troubleshooting
3. **Accordion-style Q&A items** - Question visible, answer hidden until clicked. Smooth expand/collapse animation.
4. **"Show top 8" by default** - Most common questions visible. "Show all" button expands the full list.
5. **Category counts** - Each category chip shows "(12)" count of questions in it
6. **Deep-link support** - Each Q&A has an anchor so you can link directly to it (e.g., `questions.php#faq-what-is-bol`)
7. **"Was this helpful?" on each answer** - Thumbs up/down to track usefulness
8. **"Still need help?" at bottom** - Links to contact form and Sunny

#### Data approach:
- Store Q&A items in a PHP array or database table (start with PHP array for speed, migrate to DB later if we want admin editing)
- Each item: `id`, `category`, `question`, `answer` (supports HTML/markdown), `sort_order`, `is_featured` (shows in top 8)
- Search filters across both question text and answer text

---

### Complete FAQ content (organized by category)

#### Getting Started (show these first for new users)

**Q: What is this portal and what can I do here?**
A: The Solterra Logistics Portal is your central hub for managing solar module procurement, warehousing, and delivery. As a Customer Admin, you can: create and manage projects, track module batches from manufacturer to site, manage warehouse inventory, schedule deliveries, upload and organize documents, and monitor costs and milestones. Think of it as your operations command center for solar logistics.

**Q: What should I do first after logging in?**
A: Start with these steps in order: (1) Review your Dashboard to see your portfolio overview. (2) Click into a project to see its current status. (3) If you need to add modules, go to Modules > Add Module Batch. (4) Once modules are palletized, you can create shipments. (5) After shipments arrive, receive them into your warehouse. (6) Finally, schedule deliveries to your project site. Your dashboard will guide you to what needs attention.

**Q: What is the general workflow for getting modules to my project site?**
A: The standard workflow follows these stages: **Plan** (create project, set delivery projections) -> **Order** (add module batches from manufacturers) -> **Palletize** (organize modules onto pallets for shipping) -> **Ship** (create shipments from manufacturer or warehouse) -> **Receive** (confirm arrival at warehouse/port) -> **Schedule** (set delivery appointments at project site) -> **Deliver** (confirm delivery, upload POD). Each stage has its own page in the portal.

**Q: How do I navigate the portal?**
A: The main navigation menu at the top groups pages by function: Projects (your solar installations), Modules (the panels you're managing), Warehousing (storage facilities), Manufacturers (panel suppliers), Shipments (creating and tracking transport), and Documents (file management). Your Profile menu in the top right has Notifications, Settings, Questions & Support, and Invoices.

**Q: What is Sunny and how do I use it?**
A: Sunny is your AI logistics assistant - the chat bubble in the bottom-right corner of every page. You can ask Sunny questions like "What's the status of my project?", "How many pallets are in storage?", or "What should I do next?" Sunny has access to your project data and can provide instant answers, definitions, and guidance. You can also customize quick-action buttons and Sunny will remember your preferences across sessions.

**Q: How do I get help if I'm stuck?**
A: You have three options: (1) **Ask Sunny** - click the chat bubble on any page for instant AI-powered help. (2) **Check Quick Answers** - browse this FAQ page for common questions. (3) **Contact Support** - use the contact form below, email info@solterrasol.com, or call (919) 637-8842 during business hours (Mon-Fri 9 AM - 5 PM EST).

**Q: What does each color badge mean on the dashboard?**
A: Project health badges use four colors: **Green (On Track)** means deliveries and milestones are progressing on schedule. **Yellow (At Risk)** means some delays or issues have been detected that could impact the completion date. **Red (Behind)** means the project has missed key dates and needs immediate attention. **Blue/Teal (Completed)** means all deliveries are done and the project is finished.

**Q: Can I customize my dashboard view?**
A: Yes. You can toggle between Grid view (visual project cards) and Table view (data-dense rows) using the view switcher. You can also toggle units between Modules and MW using the unit toggle at the top. Click on any health badge in the chart legend to filter projects by that status.

#### Projects

**Q: How do I create a new project?**
A: Go to Projects > Add Project from the main menu. Fill in the project name, address, target size in MW, and other details. You can also upload site documents (delivery SOPs, site maps, safety docs) during setup. After creating the project, you'll be taken to the Project Overview where you can start adding modules and planning deliveries.

**Q: What does "Project Size (MW)" mean?**
A: Project Size is the target power capacity of your solar installation, measured in megawatts (MW). 1 MW equals 1,000,000 watts. For reference, a 5 MW project typically needs 10,000-12,500 individual solar modules depending on the wattage of each panel. This helps the portal calculate your order progress and delivery needs.

**Q: What is "Order Progress" on a project?**
A: Order Progress shows what percentage of modules needed for the project have been ordered from manufacturers. If your project needs 5 MW and you've ordered 4.2 MW worth of module batches, your Order Progress is 84%. This does NOT mean they've been delivered - just that purchase orders exist.

**Q: What is "Delivery Progress" on a project?**
A: Delivery Progress shows what percentage of modules have actually arrived at the project site and been confirmed as delivered. This is always less than or equal to Order Progress because modules must go through shipping, warehousing, and scheduling before they reach the site.

**Q: What is a "General Projection" vs. a project delivery plan?**
A: A **project delivery plan** is tied to a specific project and models the actual delivery route, timeline, and costs for that project. A **General Projection** is an unattached scenario - useful for "what if" planning, comparing routes, or estimating costs before a project is fully set up. You can create general projections from the Project Planning page.

**Q: What does the "Plan Set" / "In Progress" / "Add Plan" badge mean?**
A: These badges appear on the Project Overview and indicate your delivery planning status. **Add Plan** means no delivery schedule has been created yet. **In Progress** means you've started a delivery projection but it's not complete (missing dates, stops, or legs). **Plan Set** means a complete delivery schedule exists with all dates and routes defined.

**Q: How do I view reports for my project?**
A: From the Project Overview page, use the Reports dropdown menu. Available reports include: Cost Analysis (financial breakdown), Sustainability (domestic content reporting), Manufacturers (supplier details), Exceptions (damage/issues log), and Export Data (download raw data). Each report can be filtered by date range and wattage.

#### Modules & Palletization

**Q: What is a "Module Batch"?**
A: A Module Batch is a group of solar modules ordered from a single manufacturer with the same specifications. Think of it like a purchase order line item. For example, "500 modules at 400W from Manufacturer X" would be one batch. A project can have multiple batches from different manufacturers or with different wattages.

**Q: How do I add a new module batch?**
A: Go to Modules > Add Module Batch from the main menu (or click "+ Add Module Batch" on the Modules page). Select the manufacturer, optionally assign it to a project, enter the wattage and quantity for each type, and optionally enable Domestic Content tracking. Click "Add Batch" to save. You can add multiple wattage/quantity rows if the batch has mixed panel sizes.

**Q: What does "Unassigned" mean for modules?**
A: Unassigned modules are batches that have been added to the system but are NOT yet allocated to any specific project. Think of them as available inventory or stock. You might order modules before knowing which project they'll go to. You can assign them to a project later by editing the batch.

**Q: What is palletization and why is it required?**
A: Palletization is the process of organizing individual modules onto shipping pallets. You need to palletize before you can create shipments because the logistics system tracks and ships by the pallet (not individual panels). During palletization, you define how many modules fit on each pallet based on physical dimensions and weight limits. Go to the Module Overview page for a batch and use the pallet generation tools.

**Q: How do I palletize modules?**
A: (1) Go to Modules > Manage Modules. (2) Click on the batch you want to palletize. (3) On the Module Overview page, find the wattage section. (4) Enter the number of modules per pallet. (5) Click "Generate Pallets." The system will create the appropriate number of pallets. You can also undo palletization if you need to re-configure.

**Q: What is "Domestic Content %" and should I track it?**
A: Domestic Content % measures how much of each module's value comes from US-manufactured components. Under the Inflation Reduction Act (IRA), solar projects using modules with high domestic content may qualify for additional federal tax credits (up to 10% bonus). If your project may apply for these credits, enable Domestic Content tracking when adding module batches. The portal will calculate a weighted average across all your batches.

**Q: Can I edit a module batch after creating it?**
A: Yes. Go to Modules > Manage Modules, find the batch, and click the actions menu (three dots icon) > Edit. You can change the assigned project, update quantities, or modify domestic content percentages. Note: if pallets have already been generated or shipped, some changes may be restricted.

**Q: What do the palletization status badges mean?**
A: **Not Palletized** (red) means no pallets have been generated for this batch yet - modules can't be shipped. **Partially Palletized** (orange with percentage) means some modules have been palletized but not all. **Fully Palletized** (green) means all modules in the batch are on pallets and ready for shipment.

**Q: What are "Module Movements"?**
A: Module Movements is a tracking feature that shows the location history of your modules as they move through the supply chain. It tracks status transitions like: At Manufacturer -> In Transit -> In Warehouse -> In Transit to Project -> Delivered. Use it to see where any module or pallet currently is and where it's been.

#### Shipments

**Q: How do I create a shipment?**
A: Go to Shipments > Create Shipment. Select the pallets you want to ship (they must be palletized first), choose the destination (project site or warehouse), enter shipment details (carrier, dates, costs), and click Create. The system will generate a BOL number for tracking. After creating, you'll want to schedule the delivery appointment.

**Q: What is the difference between "Single" and "Multi" shipment mode?**
A: **Single** mode creates one shipment with one BOL number for all selected pallets - use this when everything fits on one truck. **Multi** mode splits your selected pallets across multiple trucks, each getting its own BOL number - use this when you have more pallets than can fit on a single truck (check the "Pallets per Truck" field to set the split).

**Q: What is a BOL (Bill of Lading)?**
A: A Bill of Lading (BOL) is the official shipping document that serves as a receipt and contract between the shipper and the carrier. It lists what's being shipped, where it's going, and who's responsible. Every shipment in the portal gets a unique BOL number for tracking. You'll reference this number when receiving shipments and scheduling deliveries.

**Q: What are Master BOL and House BOL?**
A: These apply to overseas/container shipments only. The **Master BOL** covers the entire ocean container and is issued by the shipping line. The **House BOL** covers your specific portion within that container (if sharing a container with other shippers). For domestic shipments, you only need a regular BOL.

**Q: What is the difference between Freight Cost, Customer Cost, and Accessorial Cost?**
A: **Freight Cost** is the base transportation charge from the carrier to move the shipment. **Customer Cost** is the amount passed through to the customer (you) - it may be different from freight cost if Solterra negotiates rates or adds margins. **Accessorial Cost** covers additional charges beyond base freight: things like driver detention (waiting time), lumper fees (unloading help), fuel surcharges, lift-gate charges, or other extras.

**Q: What does the "Miles" field do on a shipment?**
A: The Miles field records the distance between origin and destination. It's used for proportional cost distribution when a shipment contains mixed wattages - costs are allocated based on distance and volume. The system can auto-calculate this from the origin and destination addresses.

**Q: When should I use overseas/container shipping vs. domestic trucking?**
A: Use overseas/container flow when modules are being shipped internationally by ocean freight (typically from overseas manufacturers). This adds Port of Entry, Container Number, and Master/House BOL fields. Use domestic trucking for shipments within the country by truck. The system auto-detects overseas shipments based on the manufacturer's country.

**Q: What should I do after creating a shipment?**
A: After creating a shipment: (1) Upload the BOL document in the Documents section. (2) If the destination is a warehouse, wait for arrival and then "Receive" the shipment on the warehouse page. (3) If the destination is a project site, schedule a delivery appointment on the Scheduling page. (4) Notify any relevant parties if needed.

**Q: What does the "Generate BOL" checkbox do?**
A: When checked, the system will automatically create a BOL document with the shipment details pre-filled. This saves you from having to manually create and upload a separate BOL file. If a BOL with the same number already exists, you'll see a warning about the duplicate.

#### Warehousing & Receiving

**Q: What is warehousing and why would I use it?**
A: Warehousing is temporary storage of solar modules between manufacturing and final delivery to your project site. You might use warehousing when: (1) Modules arrive before the project site is ready. (2) You want to consolidate shipments from multiple manufacturers before delivering. (3) You're receiving overseas containers that need customs clearance at a port. Warehouse facilities charge entry, exit, and monthly storage fees.

**Q: What is the difference between a Port and a Warehouse?**
A: A **Port** is a facility that receives overseas container shipments and handles customs clearance. It has additional functionality for container tracking, customs status, and drayage (short-haul trucking from port to inland warehouse). A **Warehouse** is a standard storage facility for domestic shipments. Both store pallets, but ports deal with containers and customs first. The portal handles both on the same page but with different workflows.

**Q: How do I "receive" a shipment at a warehouse?**
A: When a shipment arrives at your warehouse: (1) Go to the warehouse's inventory page. (2) Find the shipment in the "Inbound Transit" tab. (3) Click "Receive" to confirm arrival. (4) Verify quantities match expectations. (5) Report any damage or exceptions. (6) Upload Proof of Delivery (POD) if available. Receiving updates the pallet status from "In Transit" to "In Warehouse" and makes them available for outbound shipping.

**Q: How are warehouse storage costs calculated?**
A: Warehouse costs have three components: **Entry fees** are charged per pallet when goods first arrive. **Exit fees** are charged per pallet when goods leave. **Monthly storage fees** are charged per pallet per month for as long as modules remain in storage. You can view the fee schedule by clicking "Cost Structure" on the warehouse page. Total cost = (Entry Fee x Pallets In) + (Exit Fee x Pallets Out) + (Monthly Fee x Pallets x Months Stored).

**Q: What does "Cleared Customs" mean?**
A: Cleared Customs is a port-specific status meaning that your shipment has passed government customs inspection and all import duties/fees have been processed. After clearing customs, modules can be moved from the port to an inland warehouse via drayage or shipped directly to the project site.

**Q: What is "Drayage"?**
A: Drayage is short-distance trucking, typically from a port facility to a nearby inland warehouse. After overseas containers clear customs at a port, drayage moves them to a warehouse where modules can be deconsolidated (taken out of the container) and stored as individual pallets until delivery.

**Q: How do I see what's currently in storage?**
A: You have two options: (1) Go to Warehousing > Warehousing Overview to see aggregate inventory across all your warehouses. (2) Click into any specific warehouse to see its detailed inventory, inbound/outbound history, and cost breakdown. You can also check individual project pages to see which modules are in storage for that project.

**Q: What happens to modules after I receive them?**
A: After receiving, modules are marked as "In Warehouse" (or "In Storage"). They remain there until you create an outbound shipment to the project site. From the project overview, you can create a shipment that pulls from warehouse inventory. Then schedule a delivery appointment at the project site.

#### Scheduling & Deliveries

**Q: How do I schedule a delivery to my project site?**
A: Go to the Scheduling page for your project. Select the delivery (shipment) you want to schedule from the dropdown, pick a date and time for the appointment, add any reference numbers or notes, and save. The appointment will appear on the calendar view. Make sure to check the site's operating hours before selecting a time slot.

**Q: What are "operating hours" and where do I find them?**
A: Operating hours define when your project site can accept deliveries (e.g., Monday-Friday 8 AM - 5 PM). These are set when the project is created and determine valid appointment time slots. Your site manager or project admin sets these. If you're not sure what the hours are, check with your project team or ask Sunny.

**Q: How do I report damage found during a delivery?**
A: When editing a delivery appointment, set "Has Damages: Yes." This reveals per-pallet damage fields where you can record: expected quantity, actual quantity received, and number of damaged units. Upload photos of the damage. This creates an exception report that can be used for warranty claims. Report damage as soon as possible after discovery.

**Q: How do I report a safety incident during delivery?**
A: When editing a delivery appointment, set "Has Safety Incident: Yes." Add notes describing the incident. Check "Report Driver: Yes" if the driver was involved. Examples of incidents to report: near-miss events, equipment damage, unsafe driver behavior, environmental spills, injury or injury risk during unloading. Safety incidents are tracked separately from damage reports.

**Q: What is "Proof of Delivery" (POD)?**
A: Proof of Delivery is documentation confirming a shipment was received at its destination. Typically a signed delivery receipt, a driver's signature photo, or a stamped BOL. Upload your POD when editing the delivery appointment - this creates an official record that the delivery was completed.

**Q: What happens when I mark a delivery as "Delivered"?**
A: Marking a delivery as complete updates all pallet statuses in that shipment to "Delivered to Project." This may trigger payment milestones if your contract has delivery-based payments. The change is permanent and cannot be undone without admin assistance, so make sure everything checks out before confirming.

**Q: What do the different delivery statuses mean?**
A: **On Water** = modules are on an ocean vessel (overseas only). **In Transit to Warehouse** = truck is heading to a warehouse/port. **In Transit to Project** = truck is heading to the project site. **At Warehouse / In Storage** = modules are stored at a facility. **Delivered to Project** = modules have arrived at the site and been confirmed. **Cleared Customs** = port-only, modules passed customs inspection.

**Q: How do I reschedule a delivery?**
A: Find the appointment on the scheduling calendar, click to edit, and change the date/time. Be aware that rescheduling may require coordination with the carrier and project site team. The system will warn you if the new time conflicts with existing appointments or falls outside operating hours.

#### Documents

**Q: What types of documents can I upload?**
A: The portal supports 8 document categories, each with sub-types: **Site** (Delivery SOP, Site Map, Safety Document, Permit), **Invoices** (Solterra, Freight, Module invoices), **Shipments** (Arrival Notice, Customs Document, POD), **Warehousing** (Warehouse POD, Inventory Report, Photos, Quote), **Modules** (Invoice, Flash Test Data, Spec Sheets), **Photos** (Project, Warehouse, Damage), **Exception Reports** (Damage Photo, Warranty Document, Safety Incident, POD), and **Other** (General).

**Q: What's the difference between "Project Documents" and "Global Documents"?**
A: **Project Documents** shows documents organized by project - click a project to see all its files. Best for finding docs related to a specific project. **Global Documents** shows ALL your documents across all projects with powerful filters (by type, sub-type, project, date range). Best for finding a specific document when you know its type but not which project it belongs to.

**Q: How do I upload a document?**
A: Go to Global Documents and click the "Upload" button. Select the project it belongs to, choose the document type and sub-type, optionally link it to a BOL/shipment number, add any notes, then drag-and-drop your file or click "Browse" to select it. Supported formats include PDF, Word, Excel, images, and more.

**Q: How do I find a specific document I uploaded before?**
A: Go to Global Documents and use the filters: select the project, document type, and/or date range, then click "Apply Filters." You can also go to Project Documents and click the specific project to see all its files. If you remember the BOL number, filter by that in Global Documents.

**Q: What should I always upload for each shipment?**
A: At minimum, upload these for every shipment: (1) **BOL** (Bill of Lading) - the shipping contract/receipt. (2) **POD** (Proof of Delivery) - confirmation of receipt at destination. (3) **Damage photos** - if any damage occurred. (4) **Flash Test Data** - if available from the manufacturer. Optional but helpful: freight invoices, customs documents (for overseas), and warehouse receipts.

**Q: Can I bulk download documents?**
A: Yes. On the Global Documents page, use the checkboxes to select multiple documents, then click "Bulk Download." All selected files will be packaged and downloaded. You can also use the filters first to narrow down to the documents you need, then select all visible results.

#### Billing, Costs & Milestones

**Q: What are payment milestones?**
A: Payment milestones are contractually defined trigger points where payments are due. For example, your contract might say "30% due when shipping is initiated, 50% due on delivery, 20% due on project completion." The portal tracks which milestones have been triggered based on actual logistics events and calculates the cumulative payment amount.

**Q: How are milestones triggered?**
A: Milestones are automatically triggered when specific logistics events occur. Common triggers include: **PO Execution** (when the purchase order date is set), **Shipping** (when a shipment is created), **Customs Clearance** (when modules clear customs at a port), **Delivery to Project** (when modules arrive at the site). The specific triggers depend on your contract terms configured by your account manager.

**Q: Where can I see my milestone payment status?**
A: Go to any project's Overview page and look at the Financial tab. You'll see the Milestone Summary Card showing completion percentage, contract value, triggered amount, and remaining balance. The Milestone Timeline shows each payment event chronologically with running totals.

**Q: How are delivery projection costs calculated?**
A: Delivery projections estimate three cost categories: **Freight** (transportation costs per leg of the journey based on distance and volume), **Warehousing** (entry, exit, and storage fees at each stop based on duration and pallet count), and **Milestones** (payment triggers based on contract terms). The Weekly Cost Projections table breaks these down by week so you can forecast cash flow.

**Q: What is "Cost per Watt" and how is it calculated?**
A: Cost per Watt ($/W) is the standard industry metric for comparing solar module pricing. It's calculated by dividing the total module cost by the total wattage. Example: $200,000 for 500,000 watts (500 kW) = $0.40/W. Lower $/W is generally better. The dashboard shows your portfolio-wide average.

**Q: What does the "Portfolio Cost" card on the dashboard show?**
A: Portfolio Cost shows the total value of all module batches across all your projects, along with the average cost per watt. This is the aggregate purchase cost of your solar panels, not including freight, warehousing, or other logistics costs.

#### Account & Access

**Q: What can I do as a Customer Admin?**
A: As a Customer Admin, you can: create and manage projects, add and palletize module batches, create shipments, receive inventory at warehouses, schedule deliveries, upload documents, view cost analysis and reports, manage delivery projections, and access the Sunny AI assistant. You can see all data for your account/organization. You cannot add manufacturers or warehouses directly (you can request them), and you cannot access other customers' data.

**Q: How do I request a new manufacturer or warehouse?**
A: To request a new manufacturer, go to Manufacturers > Request Manufacturers. To request a new warehouse, contact your Solterra account manager. Provide the facility name, address, and any relevant details. Your account manager will set it up and it will appear in your portal within 1-2 business days.

**Q: How do I manage my notification preferences?**
A: Go to your Profile > Profile Settings. You can toggle on/off in-app and email notifications for each event type: document uploads, project updates, delivery status changes, warranty claims, freight estimates, warehouse estimates, and manufacturer requests. By default, in-app notifications are enabled and emails are disabled.

**Q: Can other people in my organization access the portal?**
A: Yes. Your organization can have multiple portal users. Contact your Solterra account manager to add new users. Users can be assigned different roles with different permission levels. As a Customer Admin, you have the highest level of access for your organization's data.

**Q: How do I change my password or profile information?**
A: Go to Profile > Profile Settings in the top-right menu. You can update your name, email, phone number, and other profile details. To change your password, use the password change form on the settings page. If you've forgotten your password, use the "Forgot Password" link on the login page.

#### Troubleshooting

**Q: I can't find my project on the dashboard.**
A: Check if you have a health filter active - look for a yellow filter banner above the project grid and click "Clear Filter." If you have many projects, try switching to Table view for easier scanning, or use Sunny to search: "Show me the status of [project name]." If the project still doesn't appear, it may have been archived - check the "Archived" link on the dashboard.

**Q: I'm trying to create a shipment but I don't see any pallets to select.**
A: Pallets must exist before you can create a shipment. Check that: (1) Module batches have been added (Modules page). (2) Modules have been palletized (Module Overview page - look for "Generate Pallets"). (3) The pallets haven't already been shipped (check status column). If pallets show "Delivered to Project" or "In Transit," they're already in use.

**Q: My shipment costs don't look right.**
A: Shipment costs can appear unexpected for a few reasons: (1) For mixed-wattage shipments, costs are proportionally distributed by wattage. (2) Accessorial costs (detention, lumper, fuel surcharges) may have been added separately. (3) Customer Cost and Freight Cost are different fields - make sure you're looking at the right one. Contact your account manager if the numbers still don't match your expectations.

**Q: I received a shipment but the quantities don't match.**
A: When receiving inventory, record the actual quantities in the receiving form. If quantities are short, mark the discrepancy. If modules are damaged, use the damage reporting feature to document expected vs. actual vs. damaged counts, and upload photos. This creates an exception report for your records and any warranty claims.

**Q: A delivery is scheduled but I need to change the time.**
A: Go to the Scheduling page, find the appointment on the calendar, and click to edit. Change the date and/or time and save. Coordinate with the carrier and site team on the new time. If you need to cancel entirely, contact your account manager.

**Q: I uploaded a document but can't find it.**
A: Go to Global Documents and try these filters: (1) Select the project you uploaded it for. (2) Select the document type you chose during upload. (3) Set the date range to when you uploaded it. If you still can't find it, try clearing all filters and sorting by most recent. The document may have been tagged with a different type than expected.

**Q: Why can't I add a manufacturer or warehouse?**
A: Customer Admins can request new manufacturers and warehouses, but cannot add them directly. This is because manufacturers and warehouses require address verification, fee configuration, and other setup that your Solterra account manager handles. Use the Request feature or contact your account manager.

**Q: The Sunny chat isn't responding.**
A: Try refreshing the page first. If Sunny still isn't responding: (1) Check your internet connection. (2) Make sure you're logged in (Sunny requires authentication). (3) Try starting a new conversation using the settings panel. If the issue persists, use the contact form on this page to report a bug, and we'll investigate.

**Q: What do I do if I see data that looks wrong?**
A: Don't try to fix it yourself - take a screenshot and contact your account manager or use the support form on this page. Include: which page you were on, what data looks incorrect, and what you expected to see. Common causes include: data entry errors during batch creation, cost fields entered in wrong units, or timing issues with in-transit shipments.

---

## Additional implementation notes (for whoever builds next)

### Priority 1: Fix mobile health badge tooltips
**File:** `dashboard.php` ~line 506-728
**Problem:** `.health-tooltip` uses CSS `:hover` which doesn't work on touch devices
**Fix:** Add a click handler that toggles a `.health-tooltip-visible` class, or replace with a small modal. Also add `aria-label` attributes for screen readers.

### Priority 2: Build the FAQ system on `questions.php` ✅ COMPLETED (Feb 13, 2026)
**File:** `questions.php`
**Status:** DONE. Implemented full FAQ system with:
- 57 Q&A items across 10 categories in a `$faq_items` PHP array
- Live search filtering across question + answer text
- Category filter chips with counts (Getting Started, Projects, Modules & Palletization, Shipments, Warehousing & Receiving, Scheduling & Deliveries, Documents, Billing & Milestones, Account & Access, Troubleshooting)
- Accordion expand/collapse with smooth animation
- "Show top 8 featured" by default with "Show all" toggle
- Deep-link anchors per question (e.g., `questions.php#faq-what-is-bol`)
- Link copy to clipboard on anchor click
- Hash-based auto-open on page load
- Replaced old 3-item sidebar Q&A with "Ask Sunny" CTA card
- "Still need help?" divider between FAQ and contact form
- All FAQ content filtered for logistics-only (no module procurement)
- Existing contact form and contact info cards preserved below

### Priority 3: Add "Next Step" CTAs to post-action success states
**Files:** `create_shipment.php`, `manage_warehouse_inventory.php`, `scheduling.php`, `modules.php`, `add_project.php`
**Pattern:** After any successful action (shipment created, batch added, delivery received), replace or augment the flash message with a "What's next?" card showing 2-3 relevant next actions with direct links.

### Priority 4: Add inline help text to `create_shipment.php`
**File:** `create_shipment.php`
**Fields needing help text:** Single vs Multi mode, Freight Cost, Customer Cost, Accessorial Cost, Miles, Pallets per Truck, Master BOL, House BOL, Container Numbers, Port of Entry
**Pattern:** Use the existing `<span class="help-text">` pattern from `add_project.php`

### Priority 4.5: Give Sunny FAQ & Glossary Knowledge ✅ COMPLETED (Feb 13, 2026)
**Files:** `ai-assistant/sunny_faq_knowledge.md` (new), `ai-assistant/api/chat-stream.php`, `ai-assistant/api/openai-client.php`
**Status:** DONE. Created `sunny_faq_knowledge.md` containing:
- Full glossary (35+ terms with plain-language definitions)
- All 57 FAQ answers organized by category (Getting Started, Projects, Modules, Shipments, Warehousing, Scheduling, Documents, Billing, Account, Troubleshooting)
- Content formatted for conversational AI consumption (not word-for-word — Sunny paraphrases naturally)
- Wired into `chat-stream.php` line ~218 and `openai-client.php` via `file_get_contents` append to system prompt
- Sunny can now answer any "how do I..." or "what is..." portal question without needing tool calls

### Priority 5: Sunny page-awareness system ✅ COMPLETED (Feb 13, 2026)
**Files:** `ai-assistant/components/sunny-chat.js`, `ai-assistant/api/chat-stream.php`
**Status:** DONE. Implemented full page-awareness:
- `sunny-chat.js` now sends `&page=` param with every message (extracted from `window.location.pathname`)
- `chat-stream.php` contains a `$pageContextMap` with 20 pages, each with: name, description, help guidance, and key actions
- Pages covered: dashboard, project_overview, create_shipment, manage_warehouse_inventory, warehousing_overview, scheduling, modules, module_overview, documents, global_documents, anticipated_deliveries, project_planning, add_project, add_warehouse, questions, profile_settings, notifications, warehouse_info, cost_analysis, sustainability
- Context injected into system prompt as "Current Page Context" section
- Sunny now gives page-specific answers to vague questions like "help", "what can I do here?", "what is this page?"
- **Still TODO:** Page-specific quick action buttons in the chat UI (Priority 5 originally also mentioned dynamic quick prompts in the UI — that part is not yet built)

### Priority 6: Surface milestone summary on dashboard
**File:** `dashboard.php`
**Component:** `components/milestone_summary_card.php` (already exists)
**Approach:** Add a "Payment Milestones" card next to the existing stats cards showing aggregate triggered payments vs. total contract value across all projects.

### Priority 7: Add breadcrumbs to all pages
**Files missing breadcrumbs:** `modules.php`, `module_overview.php`, `warehousing_overview.php`, `documents.php`
**Component:** `components/breadcrumbs.php` (already exists and works well)
**Just need to add the include with appropriate parameters on each page.**

### Priority 8: Add keyword search to `global_documents.php`
**File:** `global_documents.php`
**Current:** 8 filter dropdowns but no text search
**Add:** A search input above the filters that queries document name, notes, and project name. Use JavaScript for client-side filtering if document count is manageable, or add a server-side search endpoint.

### Priority 9: Add port/warehouse type badge to `manage_warehouse_inventory.php`
**File:** `manage_warehouse_inventory.php`
**Problem:** Same page serves both ports and warehouses with different behavior but no indicator
**Fix:** Add a colored badge in the page header: "PORT OPERATIONS" (blue) or "WAREHOUSE INVENTORY" (green) based on the facility's `is_port` flag.

### Priority 10: Display operating hours on scheduling page
**File:** `scheduling.php`
**Data:** `site_operating_hours` table already has the hours per project
**Fix:** Display hours near the time picker: "Site Hours: Mon-Fri 8:00 AM - 5:00 PM" and add client-side validation warning if selected time is outside operating hours.
