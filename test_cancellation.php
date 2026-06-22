<?php
include "db_connect.php";

$policyResult = $conn->query("SELECT * FROM cancellation_policy ORDER BY id DESC LIMIT 1");
$policy = $policyResult ? $policyResult->fetch_assoc() : null;

if (!$policy) {
    echo json_encode(["status" => "error", "message" => "Policy not configured"]);
    exit;
}

$test_hours = [72, 50, 48, 47.9, 30, 24, 23.9, 18, 12, 11.9, 8, 6, 5.9, 3, 1];
$results = [];

foreach ($test_hours as $diff_hours) {
    $refund_percentage = 0.00;
    if ($diff_hours >= 48) {
        $refund_percentage = (float)$policy['refund_above_48h'];
    } elseif ($diff_hours >= 24) {
        $refund_percentage = (float)$policy['refund_24_48h'];
    } elseif ($diff_hours >= 12) {
        $refund_percentage = (float)$policy['refund_12_24h'];
    } elseif ($diff_hours >= 6) {
        $refund_percentage = (float)$policy['refund_6_12h'];
    } else {
        $refund_percentage = (float)$policy['refund_below_6h'];
    }
    $results["hours_{$diff_hours}"] = [
        "hours" => $diff_hours,
        "refund_percent" => $refund_percentage
    ];
}

echo json_encode([
    "policy" => $policy,
    "results" => $results
], JSON_PRETTY_PRINT);
?>
