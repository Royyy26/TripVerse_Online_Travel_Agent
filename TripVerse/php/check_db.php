<?php
require 'connect.php';

echo "<h2>Database Diagnostic</h2>";

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
echo "✅ Database connected successfully<br><br>";

// Check if user table exists
$table_check = $conn->query("SHOW TABLES LIKE 'user'");
if ($table_check && $table_check->num_rows > 0) {
    echo "✅ 'user' table exists<br>";
} else {
    echo "❌ 'user' table does not exist<br>";
    exit;
}

// Check user table structure
echo "<h3>User Table Structure:</h3>";
$columns = $conn->query("DESCRIBE user");
if ($columns) {
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    while ($col = $columns->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($col['Field']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Key']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Default']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Extra']) . "</td>";
        echo "</tr>";
    }
    echo "</table><br>";
}

// Test the exact query from dashboard.php
echo "<h3>Testing Dashboard Query:</h3>";
$test_query = "SELECT 
            username,
            email,
            first_name,
            last_name,
            no_hp,
            gender,
            profile_picture
          FROM user
          WHERE id_user = ?";

$stmt = $conn->prepare($test_query);
if ($stmt === false) {
    echo "❌ Query preparation failed: " . $conn->error . "<br>";
    echo "Query: " . htmlspecialchars($test_query) . "<br>";
} else {
    echo "✅ Query prepared successfully<br>";
    $stmt->close();
}

// Check for admin users
echo "<h3>Admin Users:</h3>";
$admins = $conn->query("SELECT id_user, username, email, role FROM user WHERE role = 'admin'");
if ($admins && $admins->num_rows > 0) {
    echo "Found " . $admins->num_rows . " admin user(s):<br>";
    while ($admin = $admins->fetch_assoc()) {
        echo "- ID: " . htmlspecialchars($admin['id_user']) . 
             ", Email: " . htmlspecialchars($admin['email']) . 
             ", Username: " . htmlspecialchars($admin['username']) . "<br>";
    }
} else {
    echo "❌ No admin users found<br>";
}

$conn->close();
?>
