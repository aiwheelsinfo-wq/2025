<?php
include 'db_connect.php';

echo "=== Drivers Table Columns ===\n";
$result = $conn->query("SHOW COLUMNS FROM drivers");
while ($row = $result->fetch_assoc()) {
    echo $row['Field'] . " (" . $row['Type'] . ")\n";
}

echo "\n=== Drivers Data (Top 5 rows) ===\n";
$result = $conn->query("SELECT phone_number, full_name, vehicle_id, vehicle_name, vehicle_type FROM drivers LIMIT 5");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        print_r($row);
    }
} else {
    echo "Error querying data: " . $conn->error . "\n";
}
?>
