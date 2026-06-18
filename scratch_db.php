<?php
include 'db_connect.php';

echo "=== TRIP COST TABLE (Local-taxi) ===\n";
$res = $conn->query("SELECT * FROM tripCostTable WHERE tripType = 'Local-taxi'");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        print_r($row);
    }
} else {
    echo "Query error: " . $conn->error . "\n";
}

echo "\n=== LOCAL TAXI FARE CHART ===\n";
$res2 = $conn->query("SELECT * FROM local_texi_fare_chart");
if ($res2) {
    while ($row = $res2->fetch_assoc()) {
        print_r($row);
    }
} else {
    echo "Query error: " . $conn->error . "\n";
}

echo "\n=== ALL DISTINCT TRIP TYPES ===\n";
$res3 = $conn->query("SELECT DISTINCT tripType FROM tripCostTable");
if ($res3) {
    while ($row = $res3->fetch_assoc()) {
        print_r($row);
    }
} else {
    echo "Query error: " . $conn->error . "\n";
}
?>
