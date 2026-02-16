<?php
session_name("logistics_session");
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

// Initialize variables
$name = '';
$email = '';
$subject = '';
$message = '';
$success_message = '';
$error_message = '';

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Fetch user info for auto-population
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'user';
$stmt = $conn->prepare("SELECT first_name, last_name, email FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$userData = $result->fetch_assoc();
$stmt->close();

$prefill_name = '';
$prefill_email = '';
if ($userData) {
    $fn = trim($userData['first_name'] ?? '');
    $ln = trim($userData['last_name'] ?? '');
    $prefill_name = trim("$fn $ln");
    $prefill_email = trim($userData['email'] ?? '');
}

// Default form values to prefilled data
$name = $prefill_name;
$email = $prefill_email;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and validate form inputs
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $subject = trim($_POST['subject']);
    $message_content = trim($_POST['message']);

    if (empty($name) || empty($email) || empty($subject) || empty($message_content)) {
        $error_message = "Please fill in all fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Please enter a valid email address.";
    } else {
        // Prepare email
        $to = 'cbaldy@solterrasol.com';
        $email_subject = "Portal Inquiry: $subject";
        $message_body = "Name: $name\n";
        $message_body .= "Email: $email\n";
        $message_body .= "Subject: $subject\n";
        $message_body .= "Message:\n$message_content\n";

        $headers = "From: $email\r\n";
        $headers .= "Reply-To: $email\r\n";

        if (mail($to, $email_subject, $message_body, $headers)) {
            $success_message = "Your message has been sent successfully. We'll get back to you shortly.";
            $name = '';
            $email = '';
            $subject = '';
            $message = '';
        } else {
            $error_message = "There was an error sending your message. Please try again later.";
        }
    }
}

$conn->close();

// ─── FAQ Data ────────────────────────────────────────────────────────────────
$faq_categories = [
    'Getting Started',
    'Projects',
    'Modules & Palletization',
    'Shipments',
    'Warehousing & Receiving',
    'Scheduling & Deliveries',
    'Documents',
    'Billing & Milestones',
    'Account & Access',
    'Troubleshooting',
];

$faq_items = [
    // ── Getting Started ──────────────────────────────────────────────────────
    [
        'id' => 'what-is-this-portal',
        'category' => 'Getting Started',
        'question' => 'What is this portal and what can I do here?',
        'answer' => 'The Solterra Logistics Portal is your central hub for managing solar module warehousing, shipping, and delivery. As a Customer Admin you can: track module batches from manufacturer to project site, manage warehouse inventory, create shipments, schedule deliveries, upload and organize documents, and monitor costs and milestones. Think of it as your operations command center for solar logistics.',
        'is_featured' => true,
        'sort_order' => 1,
    ],
    [
        'id' => 'what-to-do-first',
        'category' => 'Getting Started',
        'question' => 'What should I do first after logging in?',
        'answer' => 'Start with these steps: (1) Review your <strong>Dashboard</strong> to see your portfolio overview and any projects that need attention. (2) Click into a project to see its current status. (3) Check the <strong>Modules</strong> page to see your module batches and palletization status. (4) If modules are palletized, you can create shipments. (5) After shipments arrive at a warehouse, receive them into inventory. (6) Finally, schedule deliveries to your project site.',
        'is_featured' => false,
        'sort_order' => 2,
    ],
    [
        'id' => 'general-workflow',
        'category' => 'Getting Started',
        'question' => 'What is the general workflow for getting modules to my project site?',
        'answer' => 'The standard workflow follows these stages: <strong>Plan</strong> (create project, set delivery projections) &rarr; <strong>Palletize</strong> (organize modules onto pallets for shipping) &rarr; <strong>Ship</strong> (create shipments from manufacturer or warehouse) &rarr; <strong>Receive</strong> (confirm arrival at warehouse or port) &rarr; <strong>Schedule</strong> (set delivery appointments at the project site) &rarr; <strong>Deliver</strong> (confirm delivery, upload Proof of Delivery). Each stage has its own page in the portal.',
        'is_featured' => true,
        'sort_order' => 3,
    ],
    [
        'id' => 'how-to-navigate',
        'category' => 'Getting Started',
        'question' => 'How do I navigate the portal?',
        'answer' => 'The main navigation menu at the top groups pages by function: <strong>Projects</strong> (your solar installations), <strong>Modules</strong> (the panels you\'re managing), <strong>Warehousing</strong> (storage facilities), <strong>Manufacturers</strong> (panel suppliers), <strong>Shipments</strong> (creating and tracking transport), and <strong>Documents</strong> (file management). Your <strong>Profile</strong> menu in the top right has Notifications, Settings, Questions &amp; Support, and Invoices.',
        'is_featured' => false,
        'sort_order' => 4,
    ],
    [
        'id' => 'what-is-sunny',
        'category' => 'Getting Started',
        'question' => 'What is Sunny and how do I use it?',
        'answer' => 'Sunny is your AI logistics assistant &mdash; the chat bubble in the bottom-right corner of every page. You can ask Sunny questions like "What\'s the status of my project?", "How many pallets are in storage?", or "What should I do next?" Sunny has access to your project data and can provide instant answers, definitions, and guidance. You can also customize quick-action buttons and Sunny will remember your preferences across sessions.',
        'is_featured' => true,
        'sort_order' => 5,
    ],
    [
        'id' => 'how-to-get-help',
        'category' => 'Getting Started',
        'question' => 'How do I get help if I\'m stuck?',
        'answer' => 'You have three options: (1) <strong>Ask Sunny</strong> &mdash; click the chat bubble on any page for instant AI-powered help. (2) <strong>Check this FAQ</strong> &mdash; browse or search the questions on this page. (3) <strong>Contact Support</strong> &mdash; use the contact form below, email <a href="mailto:info@solterrasol.com">info@solterrasol.com</a>, or call <a href="tel:9196378842">(919) 637-8842</a> during business hours (Mon&ndash;Fri 9 AM &ndash; 5 PM EST).',
        'is_featured' => true,
        'sort_order' => 6,
    ],
    [
        'id' => 'dashboard-color-badges',
        'category' => 'Getting Started',
        'question' => 'What does each color badge mean on the dashboard?',
        'answer' => 'Project health badges use four colors: <strong style="color:#2e7d32;">Green (On Track)</strong> means deliveries and milestones are progressing on schedule. <strong style="color:#f9a825;">Yellow (At Risk)</strong> means some delays or issues have been detected that could impact the completion date. <strong style="color:#c62828;">Red (Behind)</strong> means the project has missed key dates and needs immediate attention. <strong style="color:#488C9A;">Teal (Completed)</strong> means all deliveries are done and the project is finished.',
        'is_featured' => false,
        'sort_order' => 7,
    ],
    [
        'id' => 'customize-dashboard',
        'category' => 'Getting Started',
        'question' => 'Can I customize my dashboard view?',
        'answer' => 'Yes. You can toggle between <strong>Grid view</strong> (visual project cards) and <strong>Table view</strong> (data-dense rows) using the view switcher. You can also toggle units between Modules and MW using the unit toggle at the top. Click on any health badge in the chart legend to filter projects by that status.',
        'is_featured' => false,
        'sort_order' => 8,
    ],

    // ── Projects ─────────────────────────────────────────────────────────────
    [
        'id' => 'create-new-project',
        'category' => 'Projects',
        'question' => 'How do I create a new project?',
        'answer' => 'Go to <strong>Projects &gt; Add Project</strong> from the main menu. Fill in the project name, address, target size in MW, and other details. You can also upload site documents (delivery SOPs, site maps, safety docs) during setup. After creating the project, you\'ll be taken to the Project Overview where you can start adding modules and planning deliveries.',
        'is_featured' => false,
        'sort_order' => 10,
    ],
    [
        'id' => 'project-size-mw',
        'category' => 'Projects',
        'question' => 'What does "Project Size (MW)" mean?',
        'answer' => 'Project Size is the target power capacity of your solar installation, measured in megawatts (MW). 1 MW equals 1,000,000 watts. For reference, a 5 MW project typically needs 10,000&ndash;12,500 individual solar modules depending on the wattage of each panel. This helps the portal calculate your delivery progress and logistics needs.',
        'is_featured' => false,
        'sort_order' => 11,
    ],
    [
        'id' => 'order-progress',
        'category' => 'Projects',
        'question' => 'What is "Order Progress" on a project?',
        'answer' => 'Order Progress shows what percentage of modules needed for the project have been registered in the system from manufacturers. If your project needs 5 MW and you have 4.2 MW worth of module batches tracked, your Order Progress is 84%. This does <strong>not</strong> mean they\'ve been delivered &mdash; just that batches are being tracked.',
        'is_featured' => false,
        'sort_order' => 12,
    ],
    [
        'id' => 'delivery-progress',
        'category' => 'Projects',
        'question' => 'What is "Delivery Progress" on a project?',
        'answer' => 'Delivery Progress shows what percentage of modules have actually arrived at the project site and been confirmed as delivered. This is always less than or equal to Order Progress because modules must go through shipping, warehousing, and scheduling before they reach the site.',
        'is_featured' => false,
        'sort_order' => 13,
    ],
    [
        'id' => 'general-projection',
        'category' => 'Projects',
        'question' => 'What is a "General Projection" vs. a project delivery plan?',
        'answer' => 'A <strong>project delivery plan</strong> is tied to a specific project and models the actual delivery route, timeline, and costs for that project. A <strong>General Projection</strong> is an unattached scenario &mdash; useful for "what if" planning, comparing routes, or estimating costs before a project is fully set up. You can create general projections from the Project Planning page.',
        'is_featured' => false,
        'sort_order' => 14,
    ],
    [
        'id' => 'plan-set-badge',
        'category' => 'Projects',
        'question' => 'What does the "Plan Set" / "In Progress" / "Add Plan" badge mean?',
        'answer' => 'These badges appear on the Project Overview and indicate your delivery planning status. <strong>Add Plan</strong> means no delivery schedule has been created yet. <strong>In Progress</strong> means you\'ve started a delivery projection but it\'s not complete (missing dates, stops, or legs). <strong>Plan Set</strong> means a complete delivery schedule exists with all dates and routes defined.',
        'is_featured' => false,
        'sort_order' => 15,
    ],
    [
        'id' => 'project-reports',
        'category' => 'Projects',
        'question' => 'How do I view reports for my project?',
        'answer' => 'From the Project Overview page, use the <strong>Reports</strong> dropdown menu. Available reports include: Cost Analysis (financial breakdown), Sustainability (domestic content reporting), Manufacturers (supplier details), Exceptions (damage/issues log), and Export Data (download raw data). Each report can be filtered by date range and wattage.',
        'is_featured' => false,
        'sort_order' => 16,
    ],

    // ── Modules & Palletization ──────────────────────────────────────────────
    [
        'id' => 'what-is-module-batch',
        'category' => 'Modules & Palletization',
        'question' => 'What is a "Module Batch"?',
        'answer' => 'A Module Batch is a group of solar modules from a single manufacturer with the same specifications that you track together in the portal. For example, "500 modules at 400W from Manufacturer X" would be one batch. A project can have multiple batches from different manufacturers or with different wattages.',
        'is_featured' => false,
        'sort_order' => 20,
    ],
    [
        'id' => 'unassigned-modules',
        'category' => 'Modules & Palletization',
        'question' => 'What does "Unassigned" mean for modules?',
        'answer' => 'Unassigned modules are batches that have been added to the system but are <strong>not</strong> yet allocated to any specific project. Think of them as available inventory that hasn\'t been assigned a destination. You can assign them to a project later by editing the batch.',
        'is_featured' => false,
        'sort_order' => 21,
    ],
    [
        'id' => 'what-is-palletization',
        'category' => 'Modules & Palletization',
        'question' => 'What is palletization and why is it required?',
        'answer' => 'Palletization is the process of organizing individual modules onto shipping pallets. You need to palletize before you can create shipments because the logistics system tracks and ships by the pallet (not individual panels). During palletization, you define how many modules fit on each pallet based on physical dimensions and weight limits.',
        'is_featured' => false,
        'sort_order' => 22,
    ],
    [
        'id' => 'how-to-palletize',
        'category' => 'Modules & Palletization',
        'question' => 'How do I palletize modules?',
        'answer' => '(1) Go to <strong>Modules &gt; Manage Modules</strong>. (2) Click on the batch you want to palletize. (3) On the Module Overview page, find the wattage section. (4) Enter the number of modules per pallet. (5) Click <strong>"Generate Pallets."</strong> The system will create the appropriate number of pallets. You can also undo palletization if you need to reconfigure.',
        'is_featured' => false,
        'sort_order' => 23,
    ],
    [
        'id' => 'domestic-content',
        'category' => 'Modules & Palletization',
        'question' => 'What is "Domestic Content %" and should I track it?',
        'answer' => 'Domestic Content % measures how much of each module\'s value comes from US-manufactured components. Under the Inflation Reduction Act (IRA), solar projects using modules with high domestic content may qualify for additional federal tax credits (up to 10% bonus). If your project may apply for these credits, enable Domestic Content tracking when adding module batches.',
        'is_featured' => false,
        'sort_order' => 24,
    ],
    [
        'id' => 'palletization-status-badges',
        'category' => 'Modules & Palletization',
        'question' => 'What do the palletization status badges mean?',
        'answer' => '<strong style="color:#c62828;">Not Palletized</strong> means no pallets have been generated for this batch yet &mdash; modules can\'t be shipped. <strong style="color:#e65100;">Partially Palletized</strong> means some modules have been palletized but not all. <strong style="color:#2e7d32;">Fully Palletized</strong> means all modules in the batch are on pallets and ready for shipment.',
        'is_featured' => false,
        'sort_order' => 25,
    ],
    [
        'id' => 'module-movements',
        'category' => 'Modules & Palletization',
        'question' => 'What are "Module Movements"?',
        'answer' => 'Module Movements is a tracking feature that shows the location history of your modules as they move through the supply chain. It tracks status transitions like: At Manufacturer &rarr; In Transit &rarr; In Warehouse &rarr; In Transit to Project &rarr; Delivered. Use it to see where any module or pallet currently is and where it\'s been.',
        'is_featured' => false,
        'sort_order' => 26,
    ],

    // ── Shipments ────────────────────────────────────────────────────────────
    [
        'id' => 'how-to-create-shipment',
        'category' => 'Shipments',
        'question' => 'How do I create a shipment?',
        'answer' => 'Go to <strong>Shipments &gt; Create Shipment</strong>. Select the pallets you want to ship (they must be palletized first), choose the destination (project site or warehouse), enter shipment details (carrier, dates, costs), and click Create. The system will generate a BOL number for tracking. After creating, you\'ll want to schedule the delivery appointment.',
        'is_featured' => true,
        'sort_order' => 30,
    ],
    [
        'id' => 'single-vs-multi-shipment',
        'category' => 'Shipments',
        'question' => 'What is the difference between "Single" and "Multi" shipment mode?',
        'answer' => '<strong>Single</strong> mode creates one shipment with one BOL number for all selected pallets &mdash; use this when everything fits on one truck. <strong>Multi</strong> mode splits your selected pallets across multiple trucks, each getting its own BOL number &mdash; use this when you have more pallets than can fit on a single truck (check the "Pallets per Truck" field to set the split).',
        'is_featured' => false,
        'sort_order' => 31,
    ],
    [
        'id' => 'what-is-bol',
        'category' => 'Shipments',
        'question' => 'What is a BOL (Bill of Lading)?',
        'answer' => 'A Bill of Lading (BOL) is the official shipping document that serves as a receipt and contract between the shipper and the carrier. It lists what\'s being shipped, where it\'s going, and who\'s responsible. Every shipment in the portal gets a unique BOL number for tracking. You\'ll reference this number when receiving shipments and scheduling deliveries.',
        'is_featured' => false,
        'sort_order' => 32,
    ],
    [
        'id' => 'master-house-bol',
        'category' => 'Shipments',
        'question' => 'What are Master BOL and House BOL?',
        'answer' => 'These apply to <strong>overseas/container shipments only</strong>. The Master BOL covers the entire ocean container and is issued by the shipping line. The House BOL covers your specific portion within that container (if sharing a container with other shippers). For domestic shipments, you only need a regular BOL.',
        'is_featured' => false,
        'sort_order' => 33,
    ],
    [
        'id' => 'shipment-cost-fields',
        'category' => 'Shipments',
        'question' => 'What is the difference between Freight Cost, Customer Cost, and Accessorial Cost?',
        'answer' => '<strong>Freight Cost</strong> is the base transportation charge from the carrier to move the shipment. <strong>Customer Cost</strong> is the amount passed through to the customer &mdash; it may differ from freight cost if rates are negotiated or margins apply. <strong>Accessorial Cost</strong> covers additional charges beyond base freight: things like driver detention (waiting time), lumper fees (unloading help), fuel surcharges, lift-gate charges, or other extras.',
        'is_featured' => false,
        'sort_order' => 34,
    ],
    [
        'id' => 'overseas-vs-domestic',
        'category' => 'Shipments',
        'question' => 'When should I use overseas/container shipping vs. domestic trucking?',
        'answer' => 'Use <strong>overseas/container flow</strong> when modules are being shipped internationally by ocean freight (typically from overseas manufacturers). This adds Port of Entry, Container Number, and Master/House BOL fields. Use <strong>domestic trucking</strong> for shipments within the country by truck.',
        'is_featured' => false,
        'sort_order' => 35,
    ],
    [
        'id' => 'after-creating-shipment',
        'category' => 'Shipments',
        'question' => 'What should I do after creating a shipment?',
        'answer' => 'After creating a shipment: (1) Upload the BOL document in the Documents section. (2) If the destination is a warehouse, wait for arrival and then "Receive" the shipment on the warehouse page. (3) If the destination is a project site, schedule a delivery appointment on the Scheduling page. (4) Notify any relevant parties if needed.',
        'is_featured' => false,
        'sort_order' => 36,
    ],

    // ── Warehousing & Receiving ──────────────────────────────────────────────
    [
        'id' => 'what-is-warehousing',
        'category' => 'Warehousing & Receiving',
        'question' => 'What is warehousing and why would I use it?',
        'answer' => 'Warehousing is temporary storage of solar modules between manufacturing and final delivery to your project site. You might use warehousing when: (1) Modules arrive before the project site is ready. (2) You want to consolidate shipments from multiple manufacturers before delivering. (3) You\'re receiving overseas containers that need customs clearance at a port. Warehouse facilities charge entry, exit, and monthly storage fees.',
        'is_featured' => false,
        'sort_order' => 40,
    ],
    [
        'id' => 'port-vs-warehouse',
        'category' => 'Warehousing & Receiving',
        'question' => 'What is the difference between a Port and a Warehouse?',
        'answer' => 'A <strong>Port</strong> receives overseas container shipments and handles customs clearance. It has additional functionality for container tracking, customs status, and drayage (short-haul trucking to an inland warehouse). A <strong>Warehouse</strong> is a standard storage facility for domestic shipments. Both store pallets, but ports deal with containers and customs first.',
        'is_featured' => false,
        'sort_order' => 41,
    ],
    [
        'id' => 'how-to-receive-shipment',
        'category' => 'Warehousing & Receiving',
        'question' => 'How do I "receive" a shipment at a warehouse?',
        'answer' => 'When a shipment arrives at your warehouse: (1) Go to the warehouse\'s inventory page. (2) Find the shipment in the "Inbound Transit" section. (3) Click <strong>"Receive"</strong> to confirm arrival. (4) Verify quantities match expectations. (5) Report any damage or exceptions. (6) Upload Proof of Delivery (POD) if available. Receiving updates the pallet status from "In Transit" to "In Warehouse" and makes them available for outbound shipping.',
        'is_featured' => false,
        'sort_order' => 42,
    ],
    [
        'id' => 'warehouse-storage-costs',
        'category' => 'Warehousing & Receiving',
        'question' => 'How are warehouse storage costs calculated?',
        'answer' => 'Warehouse costs have three components: <strong>Entry fees</strong> are charged per pallet when goods first arrive. <strong>Exit fees</strong> are charged per pallet when goods leave. <strong>Monthly storage fees</strong> are charged per pallet per month for as long as modules remain in storage. You can view the fee schedule by clicking "Cost Structure" on the warehouse page.',
        'is_featured' => false,
        'sort_order' => 43,
    ],
    [
        'id' => 'cleared-customs',
        'category' => 'Warehousing & Receiving',
        'question' => 'What does "Cleared Customs" mean?',
        'answer' => 'Cleared Customs is a port-specific status meaning that your shipment has passed government customs inspection and all import duties/fees have been processed. After clearing customs, modules can be moved from the port to an inland warehouse via drayage or shipped directly to the project site.',
        'is_featured' => false,
        'sort_order' => 44,
    ],
    [
        'id' => 'what-is-drayage',
        'category' => 'Warehousing & Receiving',
        'question' => 'What is "Drayage"?',
        'answer' => 'Drayage is short-distance trucking, typically from a port facility to a nearby inland warehouse. After overseas containers clear customs at a port, drayage moves them to a warehouse where modules can be deconsolidated (taken out of the container) and stored as individual pallets until delivery.',
        'is_featured' => false,
        'sort_order' => 45,
    ],
    [
        'id' => 'whats-in-storage',
        'category' => 'Warehousing & Receiving',
        'question' => 'How do I see what\'s currently in storage?',
        'answer' => 'You have two options: (1) Go to <strong>Warehousing &gt; Warehousing Overview</strong> to see aggregate inventory across all your warehouses. (2) Click into any specific warehouse to see its detailed inventory, inbound/outbound history, and cost breakdown. You can also check individual project pages to see which modules are in storage for that project.',
        'is_featured' => false,
        'sort_order' => 46,
    ],

    // ── Scheduling & Deliveries ──────────────────────────────────────────────
    [
        'id' => 'how-to-schedule-delivery',
        'category' => 'Scheduling & Deliveries',
        'question' => 'How do I schedule a delivery to my project site?',
        'answer' => 'Go to the <strong>Scheduling</strong> page for your project. Select the delivery (shipment) you want to schedule from the dropdown, pick a date and time for the appointment, add any reference numbers or notes, and save. The appointment will appear on the calendar view. Make sure to check the site\'s operating hours before selecting a time slot.',
        'is_featured' => false,
        'sort_order' => 50,
    ],
    [
        'id' => 'operating-hours',
        'category' => 'Scheduling & Deliveries',
        'question' => 'What are "operating hours" and where do I find them?',
        'answer' => 'Operating hours define when your project site can accept deliveries (e.g., Monday&ndash;Friday 8 AM &ndash; 5 PM). These are set when the project is created and determine valid appointment time slots. Your site manager or project admin sets these. If you\'re not sure what the hours are, check with your project team or ask Sunny.',
        'is_featured' => false,
        'sort_order' => 51,
    ],
    [
        'id' => 'report-damage',
        'category' => 'Scheduling & Deliveries',
        'question' => 'How do I report damage found during a delivery?',
        'answer' => 'When editing a delivery appointment, set <strong>"Has Damages: Yes."</strong> This reveals per-pallet damage fields where you can record: expected quantity, actual quantity received, and number of damaged units. Upload photos of the damage. This creates an exception report that can be used for warranty claims. Report damage as soon as possible after discovery.',
        'is_featured' => false,
        'sort_order' => 52,
    ],
    [
        'id' => 'safety-incident',
        'category' => 'Scheduling & Deliveries',
        'question' => 'How do I report a safety incident during delivery?',
        'answer' => 'When editing a delivery appointment, set <strong>"Has Safety Incident: Yes."</strong> Add notes describing the incident. Check "Report Driver: Yes" if the driver was involved. Examples of incidents: near-miss events, equipment damage, unsafe driver behavior, environmental spills, injury or injury risk during unloading.',
        'is_featured' => false,
        'sort_order' => 53,
    ],
    [
        'id' => 'what-is-pod',
        'category' => 'Scheduling & Deliveries',
        'question' => 'What is "Proof of Delivery" (POD)?',
        'answer' => 'Proof of Delivery is documentation confirming a shipment was received at its destination. Typically a signed delivery receipt, a driver\'s signature photo, or a stamped BOL. Upload your POD when editing the delivery appointment &mdash; this creates an official record that the delivery was completed.',
        'is_featured' => false,
        'sort_order' => 54,
    ],
    [
        'id' => 'mark-delivered',
        'category' => 'Scheduling & Deliveries',
        'question' => 'What happens when I mark a delivery as "Delivered"?',
        'answer' => 'Marking a delivery as complete updates all pallet statuses in that shipment to "Delivered to Project." This may trigger payment milestones if your contract has delivery-based payments. <strong>This change is permanent</strong> and cannot be undone without admin assistance, so make sure everything checks out before confirming.',
        'is_featured' => false,
        'sort_order' => 55,
    ],
    [
        'id' => 'delivery-statuses',
        'category' => 'Scheduling & Deliveries',
        'question' => 'What do the different delivery statuses mean?',
        'answer' => '<strong>On Water</strong> = modules are on an ocean vessel (overseas only). <strong>In Transit to Warehouse</strong> = truck heading to a warehouse/port. <strong>In Transit to Project</strong> = truck heading to the project site. <strong>At Warehouse / In Storage</strong> = modules are stored at a facility. <strong>Delivered to Project</strong> = modules have arrived and been confirmed. <strong>Cleared Customs</strong> = port-only, modules passed customs inspection.',
        'is_featured' => false,
        'sort_order' => 56,
    ],
    [
        'id' => 'reschedule-delivery',
        'category' => 'Scheduling & Deliveries',
        'question' => 'How do I reschedule a delivery?',
        'answer' => 'Find the appointment on the scheduling calendar, click to edit, and change the date/time. Be aware that rescheduling may require coordination with the carrier and project site team. The system will warn you if the new time conflicts with existing appointments or falls outside operating hours.',
        'is_featured' => false,
        'sort_order' => 57,
    ],

    // ── Documents ────────────────────────────────────────────────────────────
    [
        'id' => 'document-types',
        'category' => 'Documents',
        'question' => 'What types of documents can I upload?',
        'answer' => 'The portal supports 8 document categories: <strong>Site</strong> (Delivery SOP, Site Map, Safety Document, Permit), <strong>Invoices</strong> (Solterra, Freight, Module invoices), <strong>Shipments</strong> (Arrival Notice, Customs Document, POD), <strong>Warehousing</strong> (Warehouse POD, Inventory Report, Photos, Quote), <strong>Modules</strong> (Invoice, Flash Test Data, Spec Sheets), <strong>Photos</strong> (Project, Warehouse, Damage), <strong>Exception Reports</strong> (Damage Photo, Warranty Document, Safety Incident, POD), and <strong>Other</strong> (General).',
        'is_featured' => false,
        'sort_order' => 60,
    ],
    [
        'id' => 'project-vs-global-docs',
        'category' => 'Documents',
        'question' => 'What\'s the difference between "Project Documents" and "Global Documents"?',
        'answer' => '<strong>Project Documents</strong> shows documents organized by project &mdash; click a project to see all its files. Best for finding docs related to a specific project. <strong>Global Documents</strong> shows ALL your documents across all projects with powerful filters (by type, sub-type, project, date range). Best for finding a specific document when you know its type but not which project it belongs to.',
        'is_featured' => false,
        'sort_order' => 61,
    ],
    [
        'id' => 'how-to-upload-document',
        'category' => 'Documents',
        'question' => 'How do I upload a document?',
        'answer' => 'Go to <strong>Global Documents</strong> and click the "Upload" button. Select the project it belongs to, choose the document type and sub-type, optionally link it to a BOL/shipment number, add any notes, then drag-and-drop your file or click "Browse" to select it. Supported formats include PDF, Word, Excel, images, and more.',
        'is_featured' => false,
        'sort_order' => 62,
    ],
    [
        'id' => 'find-specific-document',
        'category' => 'Documents',
        'question' => 'How do I find a specific document I uploaded before?',
        'answer' => 'Go to <strong>Global Documents</strong> and use the filters: select the project, document type, and/or date range, then click "Apply Filters." You can also go to Project Documents and click the specific project to see all its files. If you remember the BOL number, filter by that in Global Documents.',
        'is_featured' => false,
        'sort_order' => 63,
    ],
    [
        'id' => 'what-to-upload-per-shipment',
        'category' => 'Documents',
        'question' => 'What should I always upload for each shipment?',
        'answer' => 'At minimum, upload these for every shipment: (1) <strong>BOL</strong> (Bill of Lading) &mdash; the shipping contract/receipt. (2) <strong>POD</strong> (Proof of Delivery) &mdash; confirmation of receipt at destination. (3) <strong>Damage photos</strong> &mdash; if any damage occurred. (4) <strong>Flash Test Data</strong> &mdash; if available from the manufacturer. Optional but helpful: freight invoices, customs documents (for overseas), and warehouse receipts.',
        'is_featured' => false,
        'sort_order' => 64,
    ],

    // ── Billing & Milestones ─────────────────────────────────────────────────
    [
        'id' => 'what-are-milestones',
        'category' => 'Billing & Milestones',
        'question' => 'What are payment milestones?',
        'answer' => 'Payment milestones are contractually defined trigger points where payments are due. For example, your contract might say "30% due when shipping is initiated, 50% due on delivery, 20% due on project completion." The portal tracks which milestones have been triggered based on actual logistics events and calculates the cumulative payment amount.',
        'is_featured' => false,
        'sort_order' => 70,
    ],
    [
        'id' => 'how-milestones-triggered',
        'category' => 'Billing & Milestones',
        'question' => 'How are milestones triggered?',
        'answer' => 'Milestones are automatically triggered when specific logistics events occur. Common triggers include: <strong>PO Execution</strong> (when the purchase order date is set), <strong>Shipping</strong> (when a shipment is created), <strong>Customs Clearance</strong> (when modules clear customs at a port), <strong>Delivery to Project</strong> (when modules arrive at the site). The specific triggers depend on your contract terms configured by your account manager.',
        'is_featured' => false,
        'sort_order' => 71,
    ],
    [
        'id' => 'milestone-payment-status',
        'category' => 'Billing & Milestones',
        'question' => 'Where can I see my milestone payment status?',
        'answer' => 'Go to any project\'s Overview page and look at the Financial section. You\'ll see the Milestone Summary Card showing completion percentage, contract value, triggered amount, and remaining balance. The Milestone Timeline shows each payment event chronologically with running totals.',
        'is_featured' => false,
        'sort_order' => 72,
    ],
    [
        'id' => 'cost-per-watt',
        'category' => 'Billing & Milestones',
        'question' => 'What is "Cost per Watt" and how is it calculated?',
        'answer' => 'Cost per Watt ($/W) is the standard industry metric for comparing solar module pricing. It\'s calculated by dividing the total module cost by the total wattage. Example: $200,000 for 500,000 watts (500 kW) = $0.40/W. Lower $/W is generally better. The dashboard shows your portfolio-wide average.',
        'is_featured' => false,
        'sort_order' => 73,
    ],
    [
        'id' => 'projection-costs',
        'category' => 'Billing & Milestones',
        'question' => 'How are delivery projection costs calculated?',
        'answer' => 'Delivery projections estimate three cost categories: <strong>Freight</strong> (transportation costs per leg based on distance and volume), <strong>Warehousing</strong> (entry, exit, and storage fees at each stop based on duration and pallet count), and <strong>Milestones</strong> (payment triggers based on contract terms). The Weekly Cost Projections table breaks these down by week so you can forecast cash flow.',
        'is_featured' => false,
        'sort_order' => 74,
    ],

    // ── Account & Access ─────────────────────────────────────────────────────
    [
        'id' => 'customer-admin-permissions',
        'category' => 'Account & Access',
        'question' => 'What can I do as a Customer Admin?',
        'answer' => 'As a Customer Admin you can: create and manage projects, add and palletize module batches, create shipments, receive inventory at warehouses, schedule deliveries, upload documents, view cost analysis and reports, manage delivery projections, and access the Sunny AI assistant. You can see all data for your account/organization. You cannot add manufacturers or warehouses directly (you can request them), and you cannot access other customers\' data.',
        'is_featured' => false,
        'sort_order' => 80,
    ],
    [
        'id' => 'request-manufacturer-warehouse',
        'category' => 'Account & Access',
        'question' => 'How do I request a new manufacturer or warehouse?',
        'answer' => 'To request a new manufacturer, go to <strong>Manufacturers &gt; Request Manufacturers</strong>. To request a new warehouse, contact your Solterra account manager. Provide the facility name, address, and any relevant details. Your account manager will set it up and it will appear in your portal within 1&ndash;2 business days.',
        'is_featured' => false,
        'sort_order' => 81,
    ],
    [
        'id' => 'notification-preferences',
        'category' => 'Account & Access',
        'question' => 'How do I manage my notification preferences?',
        'answer' => 'Go to <strong>Profile &gt; Profile Settings</strong>. You can toggle on/off in-app and email notifications for each event type: document uploads, project updates, delivery status changes, warranty claims, freight estimates, warehouse estimates, and manufacturer requests. By default, in-app notifications are enabled and emails are disabled.',
        'is_featured' => false,
        'sort_order' => 82,
    ],
    [
        'id' => 'multiple-users',
        'category' => 'Account & Access',
        'question' => 'Can other people in my organization access the portal?',
        'answer' => 'Yes. Your organization can have multiple portal users. Contact your Solterra account manager to add new users. Users can be assigned different roles with different permission levels. As a Customer Admin, you have the highest level of access for your organization\'s data.',
        'is_featured' => false,
        'sort_order' => 83,
    ],
    [
        'id' => 'change-password',
        'category' => 'Account & Access',
        'question' => 'How do I change my password or profile information?',
        'answer' => 'Go to <strong>Profile &gt; Profile Settings</strong> in the top-right menu. You can update your name, email, phone number, and other profile details. To change your password, use the password change form on the settings page. If you\'ve forgotten your password, use the "Forgot Password" link on the login page.',
        'is_featured' => false,
        'sort_order' => 84,
    ],

    // ── Troubleshooting ──────────────────────────────────────────────────────
    [
        'id' => 'cant-find-project',
        'category' => 'Troubleshooting',
        'question' => 'I can\'t find my project on the dashboard.',
        'answer' => 'Check if you have a health filter active &mdash; look for a yellow filter banner above the project grid and click "Clear Filter." If you have many projects, try switching to Table view for easier scanning, or use Sunny to search: "Show me the status of [project name]." If the project still doesn\'t appear, it may need to be checked by your account manager.',
        'is_featured' => false,
        'sort_order' => 90,
    ],
    [
        'id' => 'no-pallets-to-ship',
        'category' => 'Troubleshooting',
        'question' => 'I\'m trying to create a shipment but I don\'t see any pallets to select.',
        'answer' => 'Pallets must exist before you can create a shipment. Check that: (1) Module batches have been added (Modules page). (2) Modules have been palletized (Module Overview page &mdash; look for "Generate Pallets"). (3) The pallets haven\'t already been shipped (check status column). If pallets show "Delivered to Project" or "In Transit," they\'re already in use.',
        'is_featured' => false,
        'sort_order' => 91,
    ],
    [
        'id' => 'shipment-costs-wrong',
        'category' => 'Troubleshooting',
        'question' => 'My shipment costs don\'t look right.',
        'answer' => 'Shipment costs can appear unexpected for a few reasons: (1) For mixed-wattage shipments, costs are proportionally distributed by wattage. (2) Accessorial costs (detention, lumper, fuel surcharges) may have been added separately. (3) Customer Cost and Freight Cost are different fields &mdash; make sure you\'re looking at the right one. Contact your account manager if the numbers still don\'t match your expectations.',
        'is_featured' => false,
        'sort_order' => 92,
    ],
    [
        'id' => 'quantities-dont-match',
        'category' => 'Troubleshooting',
        'question' => 'I received a shipment but the quantities don\'t match.',
        'answer' => 'When receiving inventory, record the actual quantities in the receiving form. If quantities are short, mark the discrepancy. If modules are damaged, use the damage reporting feature to document expected vs. actual vs. damaged counts, and upload photos. This creates an exception report for your records and any warranty claims.',
        'is_featured' => false,
        'sort_order' => 93,
    ],
    [
        'id' => 'cant-find-document',
        'category' => 'Troubleshooting',
        'question' => 'I uploaded a document but can\'t find it.',
        'answer' => 'Go to <strong>Global Documents</strong> and try these filters: (1) Select the project you uploaded it for. (2) Select the document type you chose during upload. (3) Set the date range to when you uploaded it. If you still can\'t find it, try clearing all filters and sorting by most recent. The document may have been tagged with a different type than expected.',
        'is_featured' => false,
        'sort_order' => 94,
    ],
    [
        'id' => 'cant-add-manufacturer',
        'category' => 'Troubleshooting',
        'question' => 'Why can\'t I add a manufacturer or warehouse?',
        'answer' => 'Customer Admins can <strong>request</strong> new manufacturers and warehouses, but cannot add them directly. This is because manufacturers and warehouses require address verification, fee configuration, and other setup that your Solterra account manager handles. Use the Request feature or contact your account manager.',
        'is_featured' => false,
        'sort_order' => 95,
    ],
    [
        'id' => 'sunny-not-responding',
        'category' => 'Troubleshooting',
        'question' => 'The Sunny chat isn\'t responding.',
        'answer' => 'Try refreshing the page first. If Sunny still isn\'t responding: (1) Check your internet connection. (2) Make sure you\'re logged in (Sunny requires authentication). (3) Try starting a new conversation using the settings panel. If the issue persists, use the contact form below to report a bug.',
        'is_featured' => false,
        'sort_order' => 96,
    ],
    [
        'id' => 'data-looks-wrong',
        'category' => 'Troubleshooting',
        'question' => 'What do I do if I see data that looks wrong?',
        'answer' => 'Don\'t try to fix it yourself &mdash; take a screenshot and contact your account manager or use the support form on this page. Include: which page you were on, what data looks incorrect, and what you expected to see. Common causes include: data entry errors during batch creation, cost fields entered in wrong units, or timing issues with in-transit shipments.',
        'is_featured' => false,
        'sort_order' => 97,
    ],
];

// Build category counts
$category_counts = [];
foreach ($faq_categories as $cat) {
    $category_counts[$cat] = 0;
}
foreach ($faq_items as $item) {
    if (isset($category_counts[$item['category']])) {
        $category_counts[$item['category']]++;
    }
}

$total_faq = count($faq_items);
$featured_items = array_filter($faq_items, fn($i) => $i['is_featured']);
$featured_count = count($featured_items);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Questions & Support</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Header Section */
        .global-documents-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 40px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }

        .global-documents-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }

        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 24px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 24px;
        }

        .header-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 32px;
            box-shadow: 0 12px 24px rgba(72, 140, 154, 0.3);
        }

        .header-info h1 {
            font-size: 2.5em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 8px 0;
            line-height: 1.2;
        }

        .header-subtitle {
            color: #6c757d;
            font-size: 1.1em;
            font-weight: 500;
            margin: 0;
        }

        /* Contact Info Cards */
        .contact-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .info-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 28px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
            border: 1px solid #e9ecef;
            text-align: center;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .info-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
        }

        .info-card-icon {
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, rgba(72, 140, 154, 0.1) 0%, rgba(72, 140, 154, 0.05) 100%);
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
        }

        .info-card-icon i {
            font-size: 1.4em;
            color: #488C9A;
        }

        .info-card h3 {
            margin: 0 0 8px 0;
            color: #293E4C;
            font-size: 1.05em;
            font-weight: 600;
        }

        .info-card p {
            margin: 0;
            color: #6c757d;
            font-size: 0.95em;
            line-height: 1.5;
        }

        .info-card a {
            color: #488C9A;
            text-decoration: none;
            font-weight: 500;
        }

        .info-card a:hover {
            color: #3A6E7F;
            text-decoration: underline;
        }

        /* ─── FAQ Section ──────────────────────────────────────────────────── */
        .faq-section {
            margin-bottom: 48px;
        }

        .faq-section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
        }

        .faq-section-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .faq-section-title h2 {
            font-size: 1.6em;
            font-weight: 700;
            color: #293E4C;
            margin: 0;
        }

        .faq-count-badge {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            font-size: 0.75em;
            font-weight: 600;
            padding: 4px 12px;
            border-radius: 20px;
        }

        /* Search */
        .faq-search-wrapper {
            position: relative;
            margin-bottom: 20px;
        }

        .faq-search-wrapper i {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: #8e99a4;
            font-size: 1em;
            pointer-events: none;
        }

        .faq-search-input {
            width: 100%;
            padding: 14px 18px 14px 48px;
            border: 1.5px solid #dde2e6;
            border-radius: 14px;
            font-size: 1em;
            font-family: 'Poppins', sans-serif;
            color: #293E4C;
            background: #f8f9fa;
            transition: border-color 0.2s, background 0.2s, box-shadow 0.2s;
            box-sizing: border-box;
        }

        .faq-search-input:focus {
            outline: none;
            border-color: #488C9A;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(72, 140, 154, 0.12);
        }

        .faq-search-clear {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #8e99a4;
            cursor: pointer;
            font-size: 1em;
            padding: 4px;
            display: none;
        }

        .faq-search-clear:hover { color: #293E4C; }

        /* Category Chips */
        .faq-category-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 24px;
        }

        .faq-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 24px;
            border: 1.5px solid #dde2e6;
            background: #fff;
            color: #495057;
            font-size: 0.85em;
            font-weight: 500;
            font-family: 'Poppins', sans-serif;
            cursor: pointer;
            transition: all 0.2s ease;
            user-select: none;
        }

        .faq-chip:hover {
            border-color: #488C9A;
            color: #488C9A;
            background: rgba(72, 140, 154, 0.04);
        }

        .faq-chip.active {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: #fff;
            border-color: transparent;
        }

        .faq-chip .chip-count {
            font-size: 0.85em;
            opacity: 0.7;
        }

        .faq-chip.active .chip-count { opacity: 0.9; }

        /* Toggle bar */
        .faq-toggle-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            padding: 12px 20px;
            background: #f8f9fa;
            border-radius: 12px;
            border: 1px solid #e9ecef;
        }

        .faq-showing-text {
            color: #6c757d;
            font-size: 0.9em;
            font-weight: 500;
        }

        .faq-toggle-btn {
            background: none;
            border: none;
            color: #488C9A;
            font-weight: 600;
            font-size: 0.9em;
            font-family: 'Poppins', sans-serif;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 8px;
            transition: background 0.15s;
        }

        .faq-toggle-btn:hover { background: rgba(72, 140, 154, 0.08); }

        /* No results */
        .faq-no-results {
            text-align: center;
            padding: 48px 20px;
            color: #8e99a4;
            display: none;
        }

        .faq-no-results i {
            font-size: 2.5em;
            margin-bottom: 16px;
            display: block;
            opacity: 0.4;
        }

        .faq-no-results p {
            font-size: 1em;
            margin: 0 0 6px;
            font-weight: 500;
        }

        .faq-no-results span { font-size: 0.88em; }

        /* Accordion items */
        .faq-accordion { list-style: none; margin: 0; padding: 0; }

        .faq-accordion-item {
            background: #fff;
            border: 1px solid #e9ecef;
            border-radius: 14px;
            margin-bottom: 10px;
            overflow: hidden;
            transition: box-shadow 0.2s, border-color 0.2s;
        }

        .faq-accordion-item:hover {
            border-color: rgba(72, 140, 154, 0.25);
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
        }

        .faq-accordion-item.open {
            border-color: rgba(72, 140, 154, 0.35);
            box-shadow: 0 4px 20px rgba(72, 140, 154, 0.1);
        }

        .faq-accordion-item.highlight {
            animation: faqHighlight 2s ease;
        }

        @keyframes faqHighlight {
            0%, 15% { background: rgba(72, 140, 154, 0.1); border-color: #488C9A; }
            100% { background: #fff; }
        }

        .faq-question-btn {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 18px 22px;
            border: none;
            background: transparent;
            text-align: left;
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
            transition: background 0.15s;
        }

        .faq-question-btn:hover { background: rgba(72, 140, 154, 0.03); }

        .faq-q-icon {
            flex-shrink: 0;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: linear-gradient(135deg, rgba(72, 140, 154, 0.1) 0%, rgba(72, 140, 154, 0.05) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #488C9A;
            font-weight: 700;
            font-size: 0.85em;
        }

        .faq-question-text {
            flex: 1;
            font-size: 0.95em;
            font-weight: 600;
            color: #293E4C;
            line-height: 1.4;
        }

        .faq-category-label {
            display: none;
            font-size: 0.72em;
            font-weight: 500;
            color: #488C9A;
            background: rgba(72, 140, 154, 0.08);
            padding: 3px 10px;
            border-radius: 12px;
            white-space: nowrap;
        }

        .faq-accordion-item[data-show-cat="true"] .faq-category-label {
            display: inline-block;
        }

        .faq-chevron {
            flex-shrink: 0;
            color: #b0b8c1;
            font-size: 0.8em;
            transition: transform 0.25s ease;
        }

        .faq-accordion-item.open .faq-chevron { transform: rotate(180deg); }

        .faq-answer-wrapper {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.35s ease;
        }

        .faq-answer {
            padding: 0 22px 20px 68px;
            color: #495057;
            font-size: 0.92em;
            line-height: 1.7;
        }

        .faq-answer a { color: #488C9A; text-decoration: none; font-weight: 500; }
        .faq-answer a:hover { text-decoration: underline; }

        .faq-answer-footer {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-top: 14px;
            padding-top: 12px;
            border-top: 1px solid #f0f2f5;
        }

        .faq-anchor-link {
            font-size: 0.8em;
            color: #8e99a4;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .faq-anchor-link:hover { color: #488C9A; }

        /* Still need help divider */
        .still-need-help {
            text-align: center;
            padding: 40px 20px;
            margin-bottom: 40px;
            background: linear-gradient(135deg, rgba(72, 140, 154, 0.04) 0%, rgba(41, 62, 76, 0.04) 100%);
            border-radius: 20px;
            border: 1px dashed rgba(72, 140, 154, 0.2);
        }

        .still-need-help h3 {
            margin: 0 0 8px;
            color: #293E4C;
            font-size: 1.3em;
            font-weight: 700;
        }

        .still-need-help p {
            margin: 0;
            color: #6c757d;
            font-size: 0.95em;
        }

        /* Contact Form Section */
        .contact-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            align-items: start;
        }

        @media (max-width: 900px) {
            .contact-section {
                grid-template-columns: 1fr;
            }
        }

        .contact-form-card {
            background: #ffffff;
            border-radius: 20px;
            padding: 36px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
        }

        .contact-form-card h2 {
            font-size: 1.5em;
            font-weight: 700;
            color: #293E4C;
            margin: 0 0 6px 0;
        }

        .contact-form-card .form-desc {
            color: #6c757d;
            font-size: 0.95em;
            margin: 0 0 28px 0;
            line-height: 1.5;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            color: #293E4C;
            margin-bottom: 6px;
            font-size: 0.9em;
        }

        .form-group label .required-star {
            color: #e74c3c;
            margin-left: 2px;
        }

        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 1.5px solid #dde2e6;
            border-radius: 10px;
            font-size: 0.95em;
            font-family: 'Poppins', sans-serif;
            color: #293E4C;
            background: #f8f9fa;
            transition: border-color 0.2s ease, background 0.2s ease, box-shadow 0.2s ease;
            box-sizing: border-box;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #488C9A;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(72, 140, 154, 0.12);
        }

        .form-group input.prefilled {
            background: #eef6f7;
            border-color: #b8d8de;
        }

        .form-group input.prefilled:focus {
            background: #ffffff;
            border-color: #488C9A;
        }

        .form-group textarea {
            min-height: 140px;
            resize: vertical;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        @media (max-width: 600px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }

        .form-group .input-hint {
            font-size: 0.8em;
            color: #8e99a4;
            margin-top: 4px;
        }

        .submit-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 14px 32px;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1em;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            box-shadow: 0 4px 16px rgba(72, 140, 154, 0.3);
            width: 100%;
            justify-content: center;
            margin-top: 8px;
        }

        .submit-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 24px rgba(72, 140, 154, 0.4);
        }

        .submit-btn:active {
            transform: translateY(0);
        }

        /* Sidebar */
        .contact-sidebar {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        .sidebar-card {
            background: #ffffff;
            border-radius: 20px;
            padding: 32px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
        }

        .sidebar-card h3 {
            font-size: 1.15em;
            font-weight: 700;
            color: #293E4C;
            margin: 0 0 16px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sidebar-card h3 i {
            color: #488C9A;
            font-size: 0.95em;
        }

        .sunny-cta-card {
            text-align: center;
        }

        .sunny-cta-card .sunny-icon {
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, #FFD54F 0%, #FFB300 100%);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5em;
            margin-bottom: 14px;
            box-shadow: 0 4px 12px rgba(255, 179, 0, 0.3);
        }

        .sunny-cta-card p {
            color: #6c757d;
            font-size: 0.9em;
            line-height: 1.5;
            margin: 0 0 16px;
        }

        .sunny-cta-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: linear-gradient(135deg, #FFD54F 0%, #FFB300 100%);
            color: #293E4C;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.9em;
            font-family: 'Poppins', sans-serif;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            box-shadow: 0 4px 12px rgba(255, 179, 0, 0.25);
        }

        .sunny-cta-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(255, 179, 0, 0.35);
        }

        .response-time-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, rgba(72, 140, 154, 0.1) 0%, rgba(72, 140, 154, 0.05) 100%);
            padding: 12px 20px;
            border-radius: 12px;
            color: #3A6E7F;
            font-weight: 600;
            font-size: 0.9em;
            margin-top: 8px;
        }

        .response-time-badge i { font-size: 1.1em; }

        /* Alert Messages */
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.95em;
            font-weight: 500;
        }

        .alert i {
            font-size: 1.2em;
            flex-shrink: 0;
        }

        .alert-success {
            background: linear-gradient(135deg, #d4edda 0%, #e8f5e9 100%);
            color: #1b5e20;
            border: 1px solid #a5d6a7;
        }

        .alert-error {
            background: linear-gradient(135deg, #f8d7da 0%, #fde8ea 100%);
            color: #b71c1c;
            border: 1px solid #ef9a9a;
        }

        /* Subject select */
        .form-group select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236c757d' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 16px center;
            padding-right: 40px;
            cursor: pointer;
        }

        /* Responsive */
        @media (max-width: 700px) {
            .faq-answer { padding-left: 22px; }
            .faq-section-header { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
    <main>
        <?php
            require_once 'components/breadcrumbs.php';
            echo slp_render_breadcrumbs(['current_label' => 'Questions & Support']);
        ?>
        <div class="global-documents-header">
            <div class="header-content">
                <div class="header-left">
                    <div class="header-icon">
                        <i class="fas fa-headset"></i>
                    </div>
                    <div class="header-info">
                        <h1>Questions & Support</h1>
                        <p class="header-subtitle">Browse answers, search for help, or contact our team</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="contact-info-grid">
            <div class="info-card">
                <div class="info-card-icon">
                    <i class="fas fa-envelope"></i>
                </div>
                <h3>Email Us</h3>
                <p><a href="mailto:info@solterrasol.com">info@solterrasol.com</a></p>
            </div>
            <div class="info-card">
                <div class="info-card-icon">
                    <i class="fas fa-phone"></i>
                </div>
                <h3>Call Us</h3>
                <p><a href="tel:9196378842">(919) 637-8842</a></p>
            </div>
            <div class="info-card">
                <div class="info-card-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <h3>Business Hours</h3>
                <p>Monday - Friday<br>9:00 AM - 5:00 PM EST</p>
            </div>
        </div>

        <!-- ─── FAQ Section ────────────────────────────────────────────── -->
        <div class="faq-section" id="faq">
            <div class="faq-section-header">
                <div class="faq-section-title">
                    <h2><i class="fas fa-book-open" style="color: #488C9A; margin-right: 8px; font-size: 0.85em;"></i>Frequently Asked Questions</h2>
                    <span class="faq-count-badge"><?php echo $total_faq; ?> answers</span>
                </div>
            </div>

            <div class="faq-search-wrapper">
                <i class="fas fa-search"></i>
                <input type="text" class="faq-search-input" id="faqSearch" placeholder="Search questions and answers..." autocomplete="off">
                <button class="faq-search-clear" id="faqSearchClear" title="Clear search"><i class="fas fa-times"></i></button>
            </div>

            <div class="faq-category-chips" id="faqChips">
                <button class="faq-chip active" data-category="all">All <span class="chip-count">(<?php echo $total_faq; ?>)</span></button>
                <?php foreach ($faq_categories as $cat): ?>
                    <?php if ($category_counts[$cat] > 0): ?>
                        <button class="faq-chip" data-category="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?> <span class="chip-count">(<?php echo $category_counts[$cat]; ?>)</span></button>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <div class="faq-toggle-bar" id="faqToggleBar">
                <span class="faq-showing-text" id="faqShowingText">Showing top <?php echo $featured_count; ?> featured questions</span>
                <button class="faq-toggle-btn" id="faqToggleBtn">
                    Show all <?php echo $total_faq; ?> <i class="fas fa-chevron-down"></i>
                </button>
            </div>

            <div class="faq-no-results" id="faqNoResults">
                <i class="fas fa-search"></i>
                <p>No questions match your search</p>
                <span>Try different keywords or browse by category</span>
            </div>

            <ul class="faq-accordion" id="faqAccordion">
                <?php foreach ($faq_items as $item): ?>
                    <li class="faq-accordion-item"
                        id="faq-<?php echo htmlspecialchars($item['id']); ?>"
                        data-category="<?php echo htmlspecialchars($item['category']); ?>"
                        data-featured="<?php echo $item['is_featured'] ? '1' : '0'; ?>"
                        data-question="<?php echo htmlspecialchars(strtolower($item['question'])); ?>"
                        data-answer="<?php echo htmlspecialchars(strtolower(strip_tags($item['answer']))); ?>">
                        <button class="faq-question-btn" aria-expanded="false">
                            <span class="faq-q-icon">Q</span>
                            <span class="faq-question-text"><?php echo htmlspecialchars($item['question']); ?></span>
                            <span class="faq-category-label"><?php echo htmlspecialchars($item['category']); ?></span>
                            <i class="fas fa-chevron-down faq-chevron"></i>
                        </button>
                        <div class="faq-answer-wrapper">
                            <div class="faq-answer">
                                <?php echo $item['answer']; ?>
                                <div class="faq-answer-footer">
                                    <a class="faq-anchor-link" href="#faq-<?php echo htmlspecialchars($item['id']); ?>" title="Copy link to this question"><i class="fas fa-link"></i> Link</a>
                                </div>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- ─── Still Need Help Divider ────────────────────────────────── -->
        <div class="still-need-help">
            <h3><i class="fas fa-life-ring" style="color: #488C9A; margin-right: 8px;"></i>Still need help?</h3>
            <p>Send us a message below or ask Sunny, your AI assistant, for instant answers on any page.</p>
        </div>

        <!-- ─── Contact Form ───────────────────────────────────────────── -->
        <div class="contact-section">
            <div class="contact-form-card">
                <h2><i class="fas fa-paper-plane" style="color: #488C9A; margin-right: 10px; font-size: 0.85em;"></i>Send Us a Message</h2>
                <p class="form-desc">Fill out the form below and our team will respond as soon as possible.</p>

                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="questions" id="contactForm">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="name">Full Name <span class="required-star">*</span></label>
                            <input type="text" id="name" name="name"
                                   value="<?php echo htmlspecialchars($name); ?>"
                                   placeholder="Your full name"
                                   class="<?php echo (!empty($prefill_name) && $name === $prefill_name) ? 'prefilled' : ''; ?>"
                                   required>
                            <?php if (!empty($prefill_name) && $name === $prefill_name): ?>
                                <div class="input-hint"><i class="fas fa-check" style="color: #488C9A; margin-right: 4px;"></i>Auto-filled from your account</div>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label for="email">Email Address <span class="required-star">*</span></label>
                            <input type="email" id="email" name="email"
                                   value="<?php echo htmlspecialchars($email); ?>"
                                   placeholder="you@company.com"
                                   class="<?php echo (!empty($prefill_email) && $email === $prefill_email) ? 'prefilled' : ''; ?>"
                                   required>
                            <?php if (!empty($prefill_email) && $email === $prefill_email): ?>
                                <div class="input-hint"><i class="fas fa-check" style="color: #488C9A; margin-right: 4px;"></i>Auto-filled from your account</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="subject">Subject <span class="required-star">*</span></label>
                        <select id="subject" name="subject" required>
                            <option value="" disabled <?php echo empty($subject) ? 'selected' : ''; ?>>Select a topic...</option>
                            <option value="General Inquiry" <?php echo ($subject === 'General Inquiry') ? 'selected' : ''; ?>>General Inquiry</option>
                            <option value="Delivery Question" <?php echo ($subject === 'Delivery Question') ? 'selected' : ''; ?>>Delivery Question</option>
                            <option value="Module/Batch Issue" <?php echo ($subject === 'Module/Batch Issue') ? 'selected' : ''; ?>>Module / Batch Issue</option>
                            <option value="Account & Access" <?php echo ($subject === 'Account & Access') ? 'selected' : ''; ?>>Account & Access</option>
                            <option value="Billing & Invoicing" <?php echo ($subject === 'Billing & Invoicing') ? 'selected' : ''; ?>>Billing & Invoicing</option>
                            <option value="Bug Report" <?php echo ($subject === 'Bug Report') ? 'selected' : ''; ?>>Bug Report</option>
                            <option value="Feature Request" <?php echo ($subject === 'Feature Request') ? 'selected' : ''; ?>>Feature Request</option>
                            <option value="Other" <?php echo ($subject === 'Other') ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="message">Message <span class="required-star">*</span></label>
                        <textarea id="message" name="message" placeholder="Describe your question or issue in detail..." required><?php echo htmlspecialchars($message); ?></textarea>
                    </div>

                    <button type="submit" class="submit-btn">
                        <i class="fas fa-paper-plane"></i>
                        Send Message
                    </button>
                </form>
            </div>

            <div class="contact-sidebar">
                <!-- Sunny CTA card -->
                <div class="sidebar-card sunny-cta-card">
                    <h3><i class="fas fa-robot"></i> Ask Sunny</h3>
                    <div class="sunny-icon">
                        <i class="fas fa-sun" style="color: #293E4C;"></i>
                    </div>
                    <p>Sunny is your AI logistics assistant available on every page. Get instant answers about your projects, shipments, and more.</p>
                    <button class="sunny-cta-btn" onclick="if(window.sunnyChat) window.sunnyChat.toggleChat(); else document.getElementById('sunny-chat-button')?.click();">
                        <i class="fas fa-comments"></i> Chat with Sunny
                    </button>
                </div>

                <div class="sidebar-card">
                    <h3><i class="fas fa-clock"></i> Response Time</h3>
                    <p style="color: #6c757d; font-size: 0.9em; margin: 0 0 12px 0;">We typically respond to inquiries within one business day.</p>
                    <div class="response-time-badge">
                        <i class="fas fa-bolt"></i>
                        Average response: &lt; 24 hours
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('faqSearch');
        const searchClear = document.getElementById('faqSearchClear');
        const chips = document.querySelectorAll('.faq-chip');
        const accordion = document.getElementById('faqAccordion');
        const items = accordion.querySelectorAll('.faq-accordion-item');
        const toggleBar = document.getElementById('faqToggleBar');
        const toggleBtn = document.getElementById('faqToggleBtn');
        const showingText = document.getElementById('faqShowingText');
        const noResults = document.getElementById('faqNoResults');

        let showAll = false;
        let activeCategory = 'all';
        let searchTerm = '';

        // ── Accordion toggle ────────────────────────────────────────────
        items.forEach(function(item) {
            const btn = item.querySelector('.faq-question-btn');
            btn.addEventListener('click', function() {
                const isOpen = item.classList.contains('open');
                // Close all others
                items.forEach(function(other) {
                    if (other !== item && other.classList.contains('open')) {
                        other.classList.remove('open');
                        other.querySelector('.faq-question-btn').setAttribute('aria-expanded', 'false');
                        other.querySelector('.faq-answer-wrapper').style.maxHeight = '0';
                    }
                });
                if (isOpen) {
                    item.classList.remove('open');
                    btn.setAttribute('aria-expanded', 'false');
                    item.querySelector('.faq-answer-wrapper').style.maxHeight = '0';
                } else {
                    item.classList.add('open');
                    btn.setAttribute('aria-expanded', 'true');
                    const wrapper = item.querySelector('.faq-answer-wrapper');
                    wrapper.style.maxHeight = wrapper.scrollHeight + 'px';
                }
            });
        });

        // ── Filtering logic ─────────────────────────────────────────────
        function applyFilters() {
            const term = searchTerm.toLowerCase().trim();
            const isSearching = term.length > 0;
            let visibleCount = 0;

            items.forEach(function(item) {
                const cat = item.getAttribute('data-category');
                const isFeatured = item.getAttribute('data-featured') === '1';
                const qText = item.getAttribute('data-question');
                const aText = item.getAttribute('data-answer');

                // Category match
                let catMatch = activeCategory === 'all' || cat === activeCategory;
                // Search match
                let searchMatch = !isSearching || qText.indexOf(term) !== -1 || aText.indexOf(term) !== -1;
                // Featured filter (only when not searching and no specific category)
                let featuredMatch = isSearching || showAll || activeCategory !== 'all' || isFeatured;

                const visible = catMatch && searchMatch && featuredMatch;
                item.style.display = visible ? '' : 'none';
                // Show category label when viewing "all"
                item.setAttribute('data-show-cat', activeCategory === 'all' && (isSearching || showAll) ? 'true' : 'false');
                if (visible) visibleCount++;
            });

            // Toggle bar visibility
            if (isSearching || activeCategory !== 'all') {
                toggleBar.style.display = 'none';
            } else {
                toggleBar.style.display = '';
                if (showAll) {
                    showingText.textContent = 'Showing all ' + <?php echo $total_faq; ?> + ' questions';
                    toggleBtn.innerHTML = 'Show featured only <i class="fas fa-chevron-up"></i>';
                } else {
                    showingText.textContent = 'Showing top <?php echo $featured_count; ?> featured questions';
                    toggleBtn.innerHTML = 'Show all <?php echo $total_faq; ?> <i class="fas fa-chevron-down"></i>';
                }
            }

            // No results
            noResults.style.display = visibleCount === 0 ? 'block' : 'none';

            // Update search clear button
            searchClear.style.display = term.length > 0 ? 'block' : 'none';
        }

        // ── Search ──────────────────────────────────────────────────────
        searchInput.addEventListener('input', function() {
            searchTerm = this.value;
            applyFilters();
        });

        searchClear.addEventListener('click', function() {
            searchInput.value = '';
            searchTerm = '';
            searchInput.focus();
            applyFilters();
        });

        // ── Category chips ──────────────────────────────────────────────
        chips.forEach(function(chip) {
            chip.addEventListener('click', function() {
                chips.forEach(function(c) { c.classList.remove('active'); });
                chip.classList.add('active');
                activeCategory = chip.getAttribute('data-category');
                applyFilters();
            });
        });

        // ── Show all / featured toggle ──────────────────────────────────
        toggleBtn.addEventListener('click', function() {
            showAll = !showAll;
            applyFilters();
        });

        // ── Deep link on page load ──────────────────────────────────────
        function handleHash() {
            var hash = window.location.hash;
            if (hash && hash.startsWith('#faq-')) {
                var target = document.getElementById(hash.substring(1));
                if (target) {
                    // Make sure item is visible
                    showAll = true;
                    activeCategory = 'all';
                    searchTerm = '';
                    searchInput.value = '';
                    chips.forEach(function(c) { c.classList.remove('active'); });
                    chips[0].classList.add('active');
                    applyFilters();

                    // Open it
                    target.classList.add('open');
                    target.querySelector('.faq-question-btn').setAttribute('aria-expanded', 'true');
                    var wrapper = target.querySelector('.faq-answer-wrapper');
                    wrapper.style.maxHeight = wrapper.scrollHeight + 'px';

                    // Highlight & scroll
                    target.classList.add('highlight');
                    setTimeout(function() {
                        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }, 100);
                }
            }
        }

        // ── Anchor link click → update URL ──────────────────────────────
        document.querySelectorAll('.faq-anchor-link').forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                var href = this.getAttribute('href');
                history.replaceState(null, '', href);
                // Copy to clipboard
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(window.location.origin + window.location.pathname + href);
                }
            });
        });

        // Initial state
        applyFilters();
        handleHash();
        window.addEventListener('hashchange', handleHash);
    });
    </script>
</body>
</html>
