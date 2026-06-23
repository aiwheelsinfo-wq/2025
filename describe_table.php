<?php
header("Content-Type: application/json");
include 'db_connect.php';

$tables = ['drivers', 'bookings'];
$schema = [];

foreach ($tables as $table) {
    $result = $conn->query("DESCRIBE $table");
    if ($result) {
        $fields = [];
        while ($row = $result->fetch_assoc()) {
            $fields[] = $row;
        }
        $schema[$table] = $fields;
    } else {
        $schema[$table] = "Error: " . $conn->error;
    }
}

echo json_encode($schema);
$conn->close();
?>
