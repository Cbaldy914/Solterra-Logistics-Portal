<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login");
    exit();
}

// Include TCPDF library
require_once('tcpdf/tcpdf');

// Initialize variables
$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Retrieve form data and sanitize inputs
    $ship_from_name = htmlspecialchars(trim($_POST['ship_from_name']));
    $ship_from_address = htmlspecialchars(trim($_POST['ship_from_address']));
    $ship_to_name = htmlspecialchars(trim($_POST['ship_to_name']));
    $ship_to_address = htmlspecialchars(trim($_POST['ship_to_address']));
    $carrier_name = htmlspecialchars(trim($_POST['carrier_name']));
    $special_instructions = htmlspecialchars(trim($_POST['special_instructions']));

    // Load information (assuming multiple items can be added)
    $load_info = [];
    if (isset($_POST['item_qty'])) {
        foreach ($_POST['item_qty'] as $index => $qty) {
            $load_info[] = [
                'qty' => htmlspecialchars(trim($qty)),
                'type' => htmlspecialchars(trim($_POST['item_type'][$index])),
                'weight' => htmlspecialchars(trim($_POST['item_weight'][$index])),
                'description' => htmlspecialchars(trim($_POST['item_description'][$index])),
            ];
        }
    }

    // Generate the BOL PDF
    // Create new PDF document
    $pdf = new TCPDF('P', PDF_UNIT, 'LETTER', true, 'UTF-8', false);

    // Set document information
    $pdf->SetCreator('Solterra Solutions');
    $pdf->SetAuthor('Solterra Solutions');
    $pdf->SetTitle('Bill of Lading');
    $pdf->SetSubject('Bill of Lading');

    // Set default header data
    $pdf->SetHeaderData('', 0, 'Solterra Solutions', 'Bill of Lading', [0,64,255], [0,64,128]);
    $pdf->setFooterData([0,64,0], [0,64,128]);

    // Set header and footer fonts
    $pdf->setHeaderFont(['helvetica', '', 10]);
    $pdf->setFooterFont(['helvetica', '', 8]);

    // Set default monospaced font
    $pdf->SetDefaultMonospacedFont('helvetica');

    // Set margins
    $pdf->SetMargins(15, 27, 15);
    $pdf->SetHeaderMargin(5);
    $pdf->SetFooterMargin(10);

    // Set auto page breaks
    $pdf->SetAutoPageBreak(TRUE, 25);

    // Set image scale factor
    $pdf->setImageScale(1.25);

    // Add a page
    $pdf->AddPage();

    // Set font
    $pdf->SetFont('helvetica', '', 12);

    // Build the HTML content
    $html = '<h1 style="text-align:center;">Bill of Lading</h1>';

    // Ship From and Ship To Information
    $html .= '
    <table cellpadding="5">
        <tr>
            <td style="border:1px solid #000;">
                <strong>Ship From:</strong><br>' . nl2br($ship_from_name) . '<br>' . nl2br($ship_from_address) . '
            </td>
            <td style="border:1px solid #000;">
                <strong>Ship To:</strong><br>' . nl2br($ship_to_name) . '<br>' . nl2br($ship_to_address) . '
            </td>
        </tr>
    </table><br>';

    // Carrier and Special Instructions
    $html .= '
    <table cellpadding="5">
        <tr>
            <td style="border:1px solid #000;">
                <strong>Carrier Name:</strong><br>' . nl2br($carrier_name) . '
            </td>
            <td style="border:1px solid #000;">
                <strong>Special Instructions:</strong><br>' . nl2br($special_instructions) . '
            </td>
        </tr>
    </table><br>';

    // Load Information Table
    $html .= '
    <table cellpadding="5" border="1">
        <thead>
            <tr style="background-color:#f2f2f2;">
                <th align="center"><strong>Quantity</strong></th>
                <th align="center"><strong>Type</strong></th>
                <th align="center"><strong>Weight</strong></th>
                <th align="center"><strong>Description</strong></th>
            </tr>
        </thead>
        <tbody>';

    foreach ($load_info as $item) {
        $html .= '
            <tr>
                <td align="center">' . $item['qty'] . '</td>
                <td align="center">' . $item['type'] . '</td>
                <td align="center">' . $item['weight'] . '</td>
                <td>' . $item['description'] . '</td>
            </tr>';
    }

    $html .= '</tbody></table>';

    // Output the HTML content
    $pdf->writeHTML($html, true, false, true, false, '');

    // Generate filename
    $filename = 'BOL_' . time() . '.pdf';

    // Output PDF to browser
    $pdf->Output($filename, 'I');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Generate Bill of Lading</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <style>
        /* Add any additional styles here */
        body {
            font-family: 'Poppins', sans-serif;
            margin: 20px;
        }
        h1 {
            text-align: center;
        }
        form {
            max-width: 800px;
            margin: 0 auto;
        }
        label {
            display: block;
            margin-top: 15px;
            font-weight: bold;
        }
        input[type="text"],
        textarea {
            width: 100%;
            padding: 8px;
            margin-top: 5px;
            border-radius: 4px;
            border: 1px solid #ccc;
        }
        .load-info {
            margin-top: 20px;
        }
        .load-info h3 {
            margin-bottom: 10px;
        }
        .load-item {
            border: 1px solid #ccc;
            padding: 10px;
            margin-bottom: 10px;
        }
        .add-item-button {
            background-color: #488C9A;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            font-size: 1em;
            cursor: pointer;
            font-weight: bold;
        }
        .add-item-button:hover {
            background-color: #293E4C;
        }
        button[type="submit"] {
            background-color: #488C9A;
            color: white;
            padding: 10px 20px;
            margin-top: 20px;
            border: none;
            border-radius: 4px;
            font-size: 1em;
            cursor: pointer;
            font-weight: bold;
        }
        button[type="submit"]:hover {
            background-color: #293E4C;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <h1>Generate Bill of Lading</h1>
    <?php
    if (!empty($error_message)) {
        echo '<p class="error-message">' . htmlspecialchars($error_message) . '</p>';
    }
    if (!empty($success_message)) {
        echo '<p class="success-message">' . htmlspecialchars($success_message) . '</p>';
    }
    ?>
    <form action="" method="post">
        <label for="ship_from_name">Ship From Name:</label>
        <input type="text" name="ship_from_name" id="ship_from_name" required>

        <label for="ship_from_address">Ship From Address:</label>
        <textarea name="ship_from_address" id="ship_from_address" rows="3" required></textarea>

        <label for="ship_to_name">Ship To Name:</label>
        <input type="text" name="ship_to_name" id="ship_to_name" required>

        <label for="ship_to_address">Ship To Address:</label>
        <textarea name="ship_to_address" id="ship_to_address" rows="3" required></textarea>

        <label for="carrier_name">Carrier Name:</label>
        <input type="text" name="carrier_name" id="carrier_name" required>

        <label for="special_instructions">Special Instructions:</label>
        <textarea name="special_instructions" id="special_instructions" rows="3"></textarea>

        <div class="load-info">
            <h3>Load Information</h3>
            <div id="load-items">
                <div class="load-item">
                    <label>Quantity:</label>
                    <input type="text" name="item_qty[]" required>
                    <label>Type:</label>
                    <input type="text" name="item_type[]" required>
                    <label>Weight:</label>
                    <input type="text" name="item_weight[]" required>
                    <label>Description:</label>
                    <textarea name="item_description[]" rows="2" required></textarea>
                </div>
            </div>
            <button type="button" class="add-item-button" onclick="addLoadItem()">Add Another Item</button>
        </div>

        <button type="submit">Generate BOL PDF</button>
    </form>
</main>

<script>
    function addLoadItem() {
        var loadItems = document.getElementById('load-items');
        var newItem = document.createElement('div');
        newItem.className = 'load-item';
        newItem.innerHTML = `
            <label>Quantity:</label>
            <input type="text" name="item_qty[]" required>
            <label>Type:</label>
            <input type="text" name="item_type[]" required>
            <label>Weight:</label>
            <input type="text" name="item_weight[]" required>
            <label>Description:</label>
            <textarea name="item_description[]" rows="2" required></textarea>
        `;
        loadItems.appendChild(newItem);
    }
</script>
</body>
</html>
