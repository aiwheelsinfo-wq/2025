<?php
header("Content-Type: text/plain");
include 'db_connect.php';

// Total active with FCM
$q1 = $conn->query("SELECT COUNT(*) as cnt FROM drivers WHERE status = 'active' AND fcm_token IS NOT NULL AND fcm_token != ''");
$r1 = $q1->fetch_assoc();
echo "Active with FCM: " . $r1['cnt'] . "\n";

// Active with FCM updated in last 24 hours
$q2 = $conn->query("SELECT COUNT(*) as cnt FROM drivers WHERE status = 'active' AND fcm_token IS NOT NULL AND fcm_token != '' AND timestamp >= NOW() - INTERVAL 1 DAY");
$r2 = $q2->fetch_assoc();
echo "Active with FCM updated in last 24h: " . $r2['cnt'] . "\n";

// Active with FCM updated in last 1 hour
$q3 = $conn->query("SELECT COUNT(*) as cnt FROM drivers WHERE status = 'active' AND fcm_token IS NOT NULL AND fcm_token != '' AND timestamp >= NOW() - INTERVAL 1 HOUR");
$r3 = $q3->fetch_assoc();
echo "Active with FCM updated in last 1h: " . $r3['cnt'] . "\n";

$conn->close();
?>
