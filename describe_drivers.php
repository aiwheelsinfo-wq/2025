<?php
header("Content-Type: text/plain");
include 'db_connect.php';

$res = $conn->query("SELECT userType, status, COUNT(*) as cnt FROM drivers GROUP BY userType, status");
while ($row = $res->fetch_assoc()) {
    echo "userType: " . $row['userType'] . " | status: " . $row['status'] . " | count: " . $row['cnt'] . "\n";
}
$conn->close();
?>
