<?php
include 'db_connect.php';
header('Content-Type: application/json');

$response = [];

// 1. Show tripCostTable rows
$result = $conn->query("SELECT * FROM tripCostTable");
if ($result) {
    $response['trip_cost_table'] = [];
    while ($row = $result->fetch_assoc()) {
        $response['trip_cost_table'][] = $row;
    }
} else {
    $response['trip_cost_table_error'] = $conn->error;
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>
