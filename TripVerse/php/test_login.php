<?php
session_start();
require 'connect.php';

echo "<h2>Login Test Page</h2>";

// Test database connection
echo "<h3>Database Connection:</h3>";
if ($conn) {
    echo "✅ Database connected successfully<br>";
} else {
    echo "❌ Database connection failed<br>";
    exit;
}

// Check if owner role exists
echo "<h3>Role Check:</h3>";
$role_check = $conn->query("SHOW COLUMNS FROM user LIKE 'role'");
if ($role_check && $row = $role_check->fetch_assoc()) {
    echo "✅ Role column exists: " . htmlspecialchars($row['Type']) . "<br>";
    if (strpos($row['Type'], "'owner'") !== false) {
        echo "✅ Owner role is available<br>";
    } else {
        echo "❌ Owner role not found in enum<br>";
    }
} else {
    echo "❌ Role column not found<br>";
}

// Check existing owner accounts
echo "<h3>Owner Accounts:</h3>";
$owners = $conn->query("SELECT id_user, username, email, role FROM user WHERE role = 'owner'");
if ($owners && $owners->num_rows > 0) {
    echo "✅ Found " . $owners->num_rows . " owner account(s):<br>";
    while ($owner = $owners->fetch_assoc()) {
        echo "- ID: " . htmlspecialchars($owner['id_user']) . 
             ", Email: " . htmlspecialchars($owner['email']) . 
             ", Username: " . htmlspecialchars($owner['username']) . "<br>";
    }
} else {
    echo "❌ No owner accounts found<br>";
    echo "<strong>Solution:</strong> Run the SQL script 'add_owner_account.sql' to create owner accounts<br>";
}

// Test login form
echo "<h3>Test Login:</h3>";
echo '<form method="post" action="index.php">';
echo 'Email: <input type="email" name="email" value="owner@tripverse.com" required><br><br>';
echo 'Password: <input type="password" name="password" value="owner123" required><br><br>';
echo '<input type="submit" name="signIn" value="Test Login">';
echo '</form>';

$conn->close();
?>
