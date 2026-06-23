<?php
header("Content-Type: text/plain");
include 'db_connect.php';

// Check recent vendor tokens (any userType)
$q = $conn->query("SELECT driver_id, full_name, phone_number, userType, status, fcm_token, timestamp 
    FROM drivers 
    WHERE fcm_token IS NOT NULL AND fcm_token != '' 
    AND timestamp >= NOW() - INTERVAL 24 HOUR 
    ORDER BY timestamp DESC LIMIT 10");

echo "=== Vendors with FCM updated in last 24 hours ===\n";
while ($row = $q->fetch_assoc()) {
    echo "ID: " . $row['driver_id'] . 
         " | Name: " . $row['full_name'] . 
         " | Phone: " . $row['phone_number'] . 
         " | Type: " . $row['userType'] . 
         " | Status: " . $row['status'] . 
         " | Token: " . substr($row['fcm_token'], 0, 30) . "..." .
         " | Updated: " . $row['timestamp'] . "\n";
}

$conn->close();
?>
