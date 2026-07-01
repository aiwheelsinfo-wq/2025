<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

include 'db_connect.php';

$phone = $_POST['phone_number'] ?? '';
$bank_account_no = trim($_POST['bank_account_no'] ?? '');
$bank_ifsc = trim($_POST['bank_ifsc'] ?? '');
$bank_holder_name = trim($_POST['bank_holder_name'] ?? '');
$upi_id = trim($_POST['upi_id'] ?? '');

if (empty($phone)) {
    echo json_encode(["status" => "error", "message" => "Phone number is required"]);
    exit;
}

// Reset Razorpay fund account ID so it is re-registered upon payout with the new details
$stmt = $conn->prepare("UPDATE users SET bank_account_no = ?, bank_ifsc = ?, bank_holder_name = ?, upi_id = ?, razorpay_fund_account_id = NULL WHERE phone_number = ?");
$stmt->bind_param("sssss", $bank_account_no, $bank_ifsc, $bank_holder_name, $upi_id, $phone);

if ($stmt->execute()) {
    echo json_encode(["status" => "success", "message" => "Bank details updated successfully"]);
} else {
    echo json_encode(["status" => "error", "message" => "Failed to update database: " . $conn->error]);
}
$stmt->close();
$conn->close();
?>
