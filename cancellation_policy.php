<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

include "db_connect.php";

$sql = "SELECT * FROM cancellation_policy ORDER BY id DESC LIMIT 1";
$result = $conn->query($sql);

if ($result && $row = $result->fetch_assoc()) {
    // Cast fields to correct data types
    $policy = [
        "cancellation_enabled" => (int)$row["cancellation_enabled"],
        "free_cancellation_hours" => (int)$row["free_cancellation_hours"],
        "refund_above_48h" => (float)$row["refund_above_48h"],
        "refund_24_48h" => (float)$row["refund_24_48h"],
        "refund_12_24h" => (float)$row["refund_12_24h"],
        "refund_6_12h" => (float)$row["refund_6_12h"],
        "refund_below_6h" => (float)$row["refund_below_6h"],
        "vendor_comp_above_24h" => (float)$row["vendor_comp_above_24h"],
        "vendor_comp_6_24h" => (float)$row["vendor_comp_6_24h"],
        "vendor_comp_below_6h" => (float)$row["vendor_comp_below_6h"],
        "auto_refund" => (int)$row["auto_refund"],
        "manual_approval" => (int)$row["manual_approval"]
    ];
    echo json_encode(["status" => "success", "data" => $policy]);
} else {
    echo json_encode(["status" => "error", "message" => "Policy not configured"]);
}

$conn->close();
?>
