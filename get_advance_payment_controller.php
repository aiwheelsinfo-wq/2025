<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

include "db_connect.php";
if ($conn->connect_error) {
    die(json_encode(["status" => "error", "message" => "Connection failed: " . $conn->connect_error]));
}


$sql = "SELECT id, free FROM advance_payment_controller LIMIT 1";
$result = $conn->query($sql);

if ($result && $row = $result->fetch_assoc()) {
    echo json_encode(["status" => "success", "data" => $row]);
} else {
    echo json_encode(["status" => "success", "data" => null]);
}

$conn->close();
?>
