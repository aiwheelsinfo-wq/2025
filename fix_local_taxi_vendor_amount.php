<?php
/**
 * ONE-TIME FIX: Correct vendor_amount for existing Local Taxi bookings.
 * 
 * Problem: Old Local Taxi bookings were saved with:
 *   vendor_amount = total_amount * 0.80  (wrong - 20% commission was deducted)
 *   agni_amount   = total_amount * 0.20  (wrong - should be 0)
 *
 * Fix: For ALL Local Taxi bookings, set:
 *   vendor_amount = total_amount  (vendor keeps 100%)
 *   agni_amount   = 0             (no commission)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json; charset=utf-8');

include 'db_connect.php';

if (!isset($conn) || $conn->connect_error) {
    echo json_encode(["status" => "error", "message" => "DB connection failed: " . $conn->connect_error]);
    exit;
}

// --- Step 1: Preview how many rows will be affected ---
$preview_sql = "SELECT id, trip_type, total_amount, vendor_amount, agni_amount 
                FROM bookings 
                WHERE LOWER(trip_type) LIKE '%local%' AND LOWER(trip_type) LIKE '%taxi%'
                  AND (vendor_amount != total_amount OR agni_amount != 0)";
$preview_result = $conn->query($preview_sql);

$affected_rows = [];
while ($row = $preview_result->fetch_assoc()) {
    $affected_rows[] = $row;
}

if (count($affected_rows) === 0) {
    echo json_encode([
        "status"  => "already_correct",
        "message" => "No records need fixing. All Local Taxi bookings already have vendor_amount = total_amount.",
    ]);
    $conn->close();
    exit;
}

// --- Step 2: Run the UPDATE ---
$update_sql = "UPDATE bookings 
               SET vendor_amount = total_amount,
                   agni_amount   = 0
               WHERE LOWER(trip_type) LIKE '%local%' AND LOWER(trip_type) LIKE '%taxi%'";

if ($conn->query($update_sql)) {
    $rows_updated = $conn->affected_rows;
    echo json_encode([
        "status"        => "success",
        "message"       => "Fixed $rows_updated Local Taxi booking(s). vendor_amount is now equal to total_amount, agni_amount = 0.",
        "rows_updated"  => $rows_updated,
        "fixed_records" => $affected_rows,   // shows what was fixed
    ]);
} else {
    echo json_encode([
        "status"  => "error",
        "message" => "Update failed: " . $conn->error,
    ]);
}

$conn->close();
?>
