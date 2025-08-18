<?php
// Quick debug script to test the document API
session_name("logistics_session");
session_start();

if (!isset($_SESSION['user_id'])) {
    die("Please log in first");
}

// Test the API directly
$project_id = $_GET['project_id'] ?? 1; // Use your test project ID
$document_type = $_GET['type'] ?? 'modules';

echo "<h2>Debug Document API</h2>";
echo "<p>Testing project_id: $project_id, document_type: $document_type</p>";

// Direct API call
$url = "get_project_documents.php?project_id=$project_id&document_type=$document_type&limit=5";
echo "<p>API URL: <a href='$url' target='_blank'>$url</a></p>";

// Test if we can access the API
$context = stream_context_create([
    'http' => [
        'header' => "Cookie: " . $_SERVER['HTTP_COOKIE'] . "\r\n"
    ]
]);

$response = @file_get_contents("http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . "/" . $url, false, $context);

if ($response === false) {
    echo "<p style='color: red;'>❌ Failed to call API</p>";
} else {
    echo "<p style='color: green;'>✅ API Response:</p>";
    echo "<pre style='background: #f5f5f5; padding: 10px; border-radius: 5px;'>";
    echo htmlspecialchars($response);
    echo "</pre>";
    
    $data = json_decode($response, true);
    if ($data && $data['success']) {
        echo "<p style='color: green;'>✅ JSON is valid</p>";
        echo "<p>Documents found: " . count($data['documents']) . "</p>";
        echo "<p>Total count: " . $data['total_count'] . "</p>";
    } else {
        echo "<p style='color: red;'>❌ JSON parse failed or success = false</p>";
    }
}

// Test database connection
require_once 'config.php';
$conn = getDBConnection();
if ($conn) {
    echo "<p style='color: green;'>✅ Database connected</p>";
    
    // Test if table exists
    $result = $conn->query("SHOW TABLES LIKE 'project_documents'");
    if ($result && $result->num_rows > 0) {
        echo "<p style='color: green;'>✅ project_documents table exists</p>";
        
        // Test query
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM project_documents WHERE project_id = ?");
        $stmt->bind_param("i", $project_id);
        $stmt->execute();
        $count_result = $stmt->get_result();
        $count = $count_result->fetch_assoc()['count'];
        echo "<p>Documents in database for project $project_id: $count</p>";
        $stmt->close();
    } else {
        echo "<p style='color: red;'>❌ project_documents table does not exist</p>";
    }
    $conn->close();
} else {
    echo "<p style='color: red;'>❌ Database connection failed</p>";
}
?>
