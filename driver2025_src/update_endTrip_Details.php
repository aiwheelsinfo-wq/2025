<?php
header('Content-Type: application/json');
ini_set('display_errors', 0);
error_reporting(E_ALL);
include 'db_connect.php';

date_default_timezone_set('Asia/Kolkata');
$current_date = date('Y-m-d');
$current_time = date('H:i:s');

// Read POST data
$booking_id   = $_POST['booking_id'] ?? null;
$status       = $_POST['status'] ?? null;
$closing_km   = isset($_POST['closing_km']) ? intval($_POST['closing_km']) : null;

// Parse date & time with IST fallback
if (!empty($_POST['closing_date'])) {
    $parsed_date = date('Y-m-d', strtotime($_POST['closing_date']));
    $closing_date = ($parsed_date && $parsed_date !== '1970-01-01') ? $parsed_date : $current_date;
} else {
    $closing_date = $current_date;
}

if (!empty($_POST['closing_time'])) {
    $parsed_time = date('H:i:s', strtotime($_POST['closing_time']));
    $closing_time = ($parsed_time && $parsed_time !== '00:00:00') ? $parsed_time : $current_time;
} else {
    $closing_time = $current_time;
}

$total_amount = isset($_POST['totalAmount']) ? floatval($_POST['totalAmount']) : null;
$vendor_amount = isset($_POST['vendor_amount']) ? floatval($_POST['vendor_amount']) :null;
$agni_amount = isset($_POST['agni_amount']) ? floatval($_POST['agni_amount']) :null;
$trip_type = $_POST['trip_type'] ?? null;
$toll_charge = isset($_POST['toll_charge']) ? floatval($_POST['toll_charge']) : null;
$parking_charge = isset($_POST['parking_charge']) ? floatval($_POST['parking_charge']) : null;
$permit_charge = isset($_POST['permit_charge']) ? floatval($_POST['permit_charge']) : null;
$base_charge = isset($_POST['base_charge']) ? floatval($_POST['base_charge']) : null;

// Fix: Retrieve existing agent_commission if not sent in the POST request
$agent_commission = null;
if (isset($_POST['agent_commission'])) {
    $agent_commission = floatval($_POST['agent_commission']);
} else if ($booking_id !== null) {
    $db_result = $conn->query("SELECT agent_commission FROM bookings WHERE id = '" . mysqli_real_escape_string($conn, $booking_id) . "'");
    if ($db_result && $row = $db_result->fetch_assoc()) {
        $agent_commission = $row['agent_commission'];
    }
}

// Log incoming data
@file_put_contents("debug_log.txt", print_r($_POST, true), FILE_APPEND);

// Fetch next_invoice_no from invoice_no_generator
$result = $conn->query("SELECT next_invoice_no FROM invoice_no_generator LIMIT 1");

if ($result && $row = $result->fetch_assoc()) {
    $next_invoice_no = $row['next_invoice_no'];
}

$invoice_date = date('Y-m-d'); 

// Validation
if($trip_type == 'Round-Trip' || $trip_type == 'Local-Duty' ){
    // Prepare and bind - updated to support agent_commission
    $stmt = $conn->prepare("UPDATE bookings SET booking_status = ?, closing_km = ?, closing_date = ?, closing_time = ?, total_amount = ?, vendor_amount = ?, agni_amount = ?, agent_commission = ?, invoice_no = ?, invoice_date = ?, toll_charge=?, parking_charge=?, permit_charge =? WHERE id = ?");
    $stmt->bind_param("sissddddssddds", $status, $closing_km, $closing_date, $closing_time, $total_amount, $vendor_amount, $agni_amount, $agent_commission, $next_invoice_no, $invoice_date, $toll_charge, $parking_charge, $permit_charge, $booking_id);

    // Execute
    if ($stmt->execute()) {
        preg_match('/\d+/', $next_invoice_no, $matches);
        $current_number = isset($matches[0]) ? (int)$matches[0] : 0;
        $prefix = preg_replace('/\d/', '', $next_invoice_no);
        $next_number = $current_number + 1;
        $next_invoice_no_updated = $prefix . str_pad($next_number, 3, '0', STR_PAD_LEFT);

        $update_stmt = $conn->prepare("UPDATE invoice_no_generator SET next_invoice_no = ?");
        $update_stmt->bind_param("s", $next_invoice_no_updated);
        $update_stmt->execute();
        $update_stmt->close();

        echo json_encode(['success' => true, 'message' => 'Booking updated successfully']);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update booking',
            'error' => $stmt->error
        ]);
    }
}

if($trip_type == 'One-way' ){
    // Prepare and bind
    $stmt = $conn->prepare("UPDATE bookings SET booking_status = ?, closing_km = ?, closing_date = ?, closing_time = ? ,invoice_no = ?, invoice_date = ?, toll_charge = ?, parking_charge =? , permit_charge =?, total_amount = ?, vendor_amount =?, agni_amount =? ,base_charge=? WHERE id = ?");
    $stmt->bind_param("sissssddddddss", $status, $closing_km, $closing_date, $closing_time,$next_invoice_no , $invoice_date, $toll_charge, $parking_charge, $permit_charge, $total_amount, $vendor_amount, $agni_amount, $base_charge,$booking_id);

    // Execute
    if ($stmt->execute()) {
        preg_match('/\d+/', $next_invoice_no, $matches);
        $current_number = isset($matches[0]) ? (int)$matches[0] : 0;
        $prefix = preg_replace('/\d/', '', $next_invoice_no);
        $next_number = $current_number + 1;
        $next_invoice_no_updated = $prefix . str_pad($next_number, 3, '0', STR_PAD_LEFT);

        $update_stmt = $conn->prepare("UPDATE invoice_no_generator SET next_invoice_no = ?");
        $update_stmt->bind_param("s", $next_invoice_no_updated);
        $update_stmt->execute();
        $update_stmt->close();

        echo json_encode(['success' => true, 'message' => 'Booking updated successfully']);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update booking',
            'error' => $stmt->error
        ]);
    }
}

if ($trip_type == 'Local-taxi' || stripos($trip_type, 'local') !== false) {
    // 1. Fetch dynamic commission percentage from local_taxi_global_settings
    $companySharePercent = 10.00;
    $gStmt = $conn->query("SELECT company_share_value, company_share_active FROM local_taxi_global_settings WHERE id = 1 LIMIT 1");
    if ($gStmt && $gRow = $gStmt->fetch_assoc()) {
        if (!empty($gRow['company_share_active'])) {
            $companySharePercent = (float)($gRow['company_share_value'] ?? 10.00);
        }
    }

    // 2. Calculate dynamic commission amount
    $commissionAmount = round(($total_amount * ($companySharePercent / 100.0)), 2);
    $agni_amount = $commissionAmount;
    $vendor_amount = max(0, $total_amount - $commissionAmount);

    // Prepare and bind - update total_amount, vendor_amount, and agni_amount
    $stmt = $conn->prepare("UPDATE bookings SET booking_status = ?, closing_km = ?, closing_date = ?, closing_time = ?, total_amount = ?, vendor_amount = ?, agni_amount = ?, invoice_no = ?, invoice_date = ?, toll_charge =?, parking_charge =?, permit_charge =? WHERE id = ?");
    $stmt->bind_param("sissdddssddds", $status, $closing_km, $closing_date, $closing_time, $total_amount, $vendor_amount, $agni_amount, $next_invoice_no, $invoice_date, $toll_charge, $parking_charge, $permit_charge, $booking_id);

    // Execute
    if ($stmt->execute()) {
        // 3. Deduct commission from Vendor Prepaid Wallet
        $vPhone = '';
        $bQ = $conn->query("SELECT vendor_id, driver_id FROM bookings WHERE id = '" . mysqli_real_escape_string($conn, $booking_id) . "' LIMIT 1");
        if ($bQ && $brow = $bQ->fetch_assoc()) {
            $vPhone = !empty($brow['vendor_id']) ? $brow['vendor_id'] : ($brow['driver_id'] ?? '');
        }

        if (!empty($vPhone) && $commissionAmount > 0) {
            $balBefore = 0.00;
            $wQ = $conn->query("SELECT wallet_balance FROM drivers WHERE phone_number = '" . mysqli_real_escape_string($conn, $vPhone) . "' LIMIT 1");
            if ($wQ && $wrow = $wQ->fetch_assoc()) {
                $balBefore = (float)$wrow['wallet_balance'];
            }
            $balAfter = $balBefore - $commissionAmount;

            // Deduct from drivers and vendors
            $safePhone = mysqli_real_escape_string($conn, $vPhone);
            $conn->query("UPDATE drivers SET wallet_balance = wallet_balance - $commissionAmount WHERE phone_number = '$safePhone'");
            $conn->query("UPDATE vendors SET wallet_balance = wallet_balance - $commissionAmount WHERE phone_number = '$safePhone'");

            // Record transaction ledger
            $desc = "Commission (" . number_format($companySharePercent, 1) . "%) for Local Taxi Trip #" . $booking_id;
            $tType = 'trip_commission_deduct';
            $logStmt = $conn->prepare("INSERT INTO vendor_wallet_transactions (vendor_phone, booking_id, transaction_type, amount, balance_before, balance_after, description) VALUES (?, ?, ?, ?, ?, ?, ?)");
            if ($logStmt) {
                $logStmt->bind_param("sisddds", $vPhone, $booking_id, $tType, $commissionAmount, $balBefore, $balAfter, $desc);
                $logStmt->execute();
                $logStmt->close();
            }
        }

        preg_match('/\d+/', $next_invoice_no, $matches);
        $current_number = isset($matches[0]) ? (int)$matches[0] : 0;
        $prefix = preg_replace('/\d/', '', $next_invoice_no);
        $next_number = $current_number + 1;
        $next_invoice_no_updated = $prefix . str_pad($next_number, 3, '0', STR_PAD_LEFT);

        $update_stmt = $conn->prepare("UPDATE invoice_no_generator SET next_invoice_no = ?");
        $update_stmt->bind_param("s", $next_invoice_no_updated);
        $update_stmt->execute();
        $update_stmt->close();

        echo json_encode([
            'success' => true,
            'message' => 'Booking updated successfully',
            'commission_deducted' => $commissionAmount,
            'commission_percent' => $companySharePercent
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update booking',
            'error' => $stmt->error
        ]);
    }
}

if (isset($stmt) && $stmt) {
    $stmt->close();
}
$conn->close();
?>
