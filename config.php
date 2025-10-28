<?php
// ================================
// Accofinda Database Configuration
// ================================

// Database credentials
$host = "localhost";         // Server host (change if remote)
$dbname = "accofinda";    // Database name
$username = "rashidultimate";          // MySQL username
$password = "#RashidQN13#";              // MySQL password

// Set default timezone
date_default_timezone_set("Africa/Nairobi");

// Create connection using MySQLi (Object-Oriented)
$conn = new mysqli($host, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}

// Optional: Force UTF-8 encoding
$conn->set_charset("utf8mb4");

// ================================
// Now $conn is ready for queries
// ================================
?>
