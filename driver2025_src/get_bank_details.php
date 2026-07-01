<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

include 'db_connect.php';

$phone = $_GET['phone_number'] ?? '';

if (empty($phone)) {
    echo json_encode(["status" => "error", "message" => "Phone number is required"]);
    exit;
}

$stmt = $conn->prepare("SELECT bank_account_no, bank_ifsc, bank_holder_name, upi_id FROM users WHERE phone_number = ?");
$stmt->bind_param("s", $phone);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

if ($res) {
    echo json_encode([
        "status" => "success",
        "data" => [
            "bank_account_no" => $res['bank_account_no'] ?? "",
            "bank_ifsc" => $res['bank_ifsc'] ?? "",
            "bank_holder_name" => $res['bank_holder_name'] ?? "",
            "upi_id" => $res['upi_id'] ?? ""
        ]
    ]);
} else {
    echo json_encode(["status" => "error", "message" => "Vendor not found in users table"]);
}
?>
