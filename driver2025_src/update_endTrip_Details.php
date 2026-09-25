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
if (strcasecmp($trip_type, 'Round-Trip') === 0 || strcasecmp($trip_type, 'Round-trip') === 0 || strcasecmp($trip_type, 'Round trip') === 0) {
    // 1. Fetch dynamic commission settings from round_trip_global_settings
    $companySharePercent = 10.00;
    $companyShareType = 'percent';
    $companyShareActive = 1;
    try {
        $gStmt = $conn->query("SELECT company_share_active, company_share_type, company_share_value FROM round_trip_global_settings WHERE id = 1 LIMIT 1");
        if ($gStmt && $gRow = $gStmt->fetch_assoc()) {
            $companyShareActive = intval($gRow['company_share_active'] ?? 1);
            $companyShareType = $gRow['company_share_type'] ?? 'percent';
            $companySharePercent = (float)($gRow['company_share_value'] ?? 10.00);
        }
    } catch (Throwable $e) {}

    // 2. Calculate dynamic commission amount + 5% GST
    $commissionAmount = 0.00;
    if ($companyShareActive) {
        if ($companyShareType === 'flat') {
            $commissionAmount = round(min($total_amount, $companySharePercent), 2);
        } else {
            $commissionAmount = round(($total_amount * ($companySharePercent / 100.0)), 2);
        }
    }
    $gstAmount = round(($total_amount * 0.05), 2);
    $totalDeductionAmount = round($commissionAmount + $gstAmount, 2);

    $agni_amount = $totalDeductionAmount;
    $vendor_amount = max(0, $total_amount - $totalDeductionAmount);

    // Prepare and bind - updated to support agent_commission
    $stmt = $conn->prepare("UPDATE bookings SET booking_status = ?, closing_km = ?, closing_date = ?, closing_time = ?, total_amount = ?, vendor_amount = ?, agni_amount = ?, agent_commission = ?, invoice_no = ?, invoice_date = ?, toll_charge=?, parking_charge=?, permit_charge =? WHERE id = ?");
    $stmt->bind_param("sissddddssddds", $status, $closing_km, $closing_date, $closing_time, $total_amount, $vendor_amount, $agni_amount, $agent_commission, $next_invoice_no, $invoice_date, $toll_charge, $parking_charge, $permit_charge, $booking_id);

    // Execute
    if ($stmt->execute()) {
        // Deduct commission and GST from Vendor/Driver Prepaid Wallet
        $vPhone = '';
        $bQ = $conn->query("SELECT vender_id, driver_id FROM bookings WHERE id = '" . mysqli_real_escape_string($conn, $booking_id) . "' LIMIT 1");
        if ($bQ && $brow = $bQ->fetch_assoc()) {
            $vPhone = !empty($brow['vender_id']) ? $brow['vender_id'] : ($brow['driver_id'] ?? '');
        }

        if (!empty($vPhone) && $totalDeductionAmount > 0) {
            $balBefore = 0.00;
            $wQ = $conn->query("SELECT wallet_balance FROM drivers WHERE phone_number = '" . mysqli_real_escape_string($conn, $vPhone) . "' LIMIT 1");
            if ($wQ && $wrow = $wQ->fetch_assoc()) {
                $balBefore = (float)$wrow['wallet_balance'];
            } else {
                $vwQ = $conn->query("SELECT wallet_balance FROM vendors WHERE phone_number = '" . mysqli_real_escape_string($conn, $vPhone) . "' LIMIT 1");
                if ($vwQ && $vwrow = $vwQ->fetch_assoc()) {
                    $balBefore = (float)$vwrow['wallet_balance'];
                }
            }
            $balAfter = $balBefore - $totalDeductionAmount;

            // Deduct from drivers and vendors
            $safePhone = mysqli_real_escape_string($conn, $vPhone);
            $conn->query("UPDATE drivers SET wallet_balance = wallet_balance - $totalDeductionAmount WHERE phone_number = '$safePhone'");
            $conn->query("UPDATE vendors SET wallet_balance = wallet_balance - $totalDeductionAmount WHERE phone_number = '$safePhone'");

            // Record transaction ledger
            $shareDesc = ($companyShareType === 'flat') ? "₹" . number_format($companySharePercent, 2) : number_format($companySharePercent, 1) . "%";
            $desc = "Platform Commission (" . $shareDesc . ": ₹" . number_format($commissionAmount, 0) . ") + 5% GST (₹" . number_format($gstAmount, 0) . ") for Round-Trip Trip #" . $booking_id;
            $tType = 'trip_commission_deduct';
            $logStmt = $conn->prepare("INSERT INTO vendor_wallet_transactions (vendor_phone, booking_id, transaction_type, amount, balance_before, balance_after, description) VALUES (?, ?, ?, ?, ?, ?, ?)");
            if ($logStmt) {
                $bIdInt = (int)$booking_id;
                $logStmt->bind_param("sisddds", $vPhone, $bIdInt, $tType, $totalDeductionAmount, $balBefore, $balAfter, $desc);
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
            'gst_deducted' => $gstAmount,
            'total_wallet_deducted' => $totalDeductionAmount
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update booking',
            'error' => $stmt->error
        ]);
    }
} else if (strcasecmp($trip_type, 'Local-Duty') === 0 || strcasecmp($trip_type, 'Local Duty') === 0 || strcasecmp($trip_type, 'Local-duty') === 0) {
    // 1. Calculate dynamic Commission on Pre-Tax Base Amount + 5% GST
    $companySharePercent = 5.00;
    $companyShareActive = 1;
    $companyShareType = 'percent';
    try {
        $gStmt = $conn->query("SELECT company_share_value, company_share_active, company_share_type FROM local_duty_global_settings WHERE id = 1 LIMIT 1");
        if ($gStmt && $gRow = $gStmt->fetch_assoc()) {
            $companyShareActive = (int)($gRow['company_share_active'] ?? 1);
            $companySharePercent = (float)($gRow['company_share_value'] ?? 5.00);
            $companyShareType = $gRow['company_share_type'] ?? 'percent';
        }
    } catch (Throwable $e) {}

    // Pre-tax base fare (exclude toll, parking, permit which are pass-through)
    $preTaxWithExtra = ($total_amount - $toll_charge - $parking_charge - $permit_charge);
    $preTaxBase = ($preTaxWithExtra > 0) ? round($preTaxWithExtra / 1.05, 2) : 0.0;
    $gstAmount = max(0.0, round($preTaxWithExtra - $preTaxBase, 2));

    $commissionAmount = 0.0;
    if ($companyShareActive) {
        if ($companyShareType === 'flat') {
            $commissionAmount = round($companySharePercent, 2);
        } else {
            $commissionAmount = round(($preTaxBase * ($companySharePercent / 100.0)), 2);
        }
    }

    $totalDeductionAmount = round($commissionAmount + $gstAmount, 2);
    $agni_amount = $totalDeductionAmount;
    $vendor_amount = max(0, round(($preTaxBase - $commissionAmount) + $toll_charge + $parking_charge + $permit_charge, 2));

    // End OTP validation for Local-Duty (prevents driver from completing trip without customer present)
    $enteredEndOtp = trim($_POST['end_otp'] ?? $_POST['otp'] ?? '');
    if (!empty($enteredEndOtp) && !empty($booking_id)) {
        $chkOtpQ = $conn->query("SELECT end_otp, otp FROM bookings WHERE id = '" . mysqli_real_escape_string($conn, $booking_id) . "' LIMIT 1");
        if ($chkOtpQ && $cRow = $chkOtpQ->fetch_assoc()) {
            $expectedOtp = trim(!empty($cRow['end_otp']) ? $cRow['end_otp'] : $cRow['otp']);
            if (!empty($expectedOtp) && $enteredEndOtp !== $expectedOtp) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Invalid End OTP. Please enter the correct 4-digit OTP provided by the customer at drop-off.'
                ]);
                exit;
            }
        }
    }

    // Prepare and bind
    $stmt = $conn->prepare("UPDATE bookings SET booking_status = ?, closing_km = ?, closing_date = ?, closing_time = ?, total_amount = ?, vendor_amount = ?, agni_amount = ?, agent_commission = ?, invoice_no = ?, invoice_date = ?, toll_charge = ?, parking_charge = ?, permit_charge = ?, gps_end_time = NOW() WHERE id = ?");
    $stmt->bind_param("sissddddssddds", $status, $closing_km, $closing_date, $closing_time, $total_amount, $vendor_amount, $agni_amount, $agent_commission, $next_invoice_no, $invoice_date, $toll_charge, $parking_charge, $permit_charge, $booking_id);

    if ($stmt->execute()) {
        // Deduct commission and GST from Vendor/Driver Prepaid Wallet
        $vPhone = '';
        $bQ = $conn->query("SELECT vender_id, driver_id FROM bookings WHERE id = '" . mysqli_real_escape_string($conn, $booking_id) . "' LIMIT 1");
        if ($bQ && $brow = $bQ->fetch_assoc()) {
            $vPhone = !empty($brow['vender_id']) ? $brow['vender_id'] : ($brow['driver_id'] ?? '');
        }

        if (!empty($vPhone) && $totalDeductionAmount > 0) {
            $balBefore = 0.00;
            $wQ = $conn->query("SELECT wallet_balance FROM drivers WHERE phone_number = '" . mysqli_real_escape_string($conn, $vPhone) . "' LIMIT 1");
            if ($wQ && $wrow = $wQ->fetch_assoc()) {
                $balBefore = (float)$wrow['wallet_balance'];
            } else {
                $vwQ = $conn->query("SELECT wallet_balance FROM vendors WHERE phone_number = '" . mysqli_real_escape_string($conn, $vPhone) . "' LIMIT 1");
                if ($vwQ && $vwrow = $vwQ->fetch_assoc()) {
                    $balBefore = (float)$vwrow['wallet_balance'];
                }
            }
            $balAfter = $balBefore - $totalDeductionAmount;

            // Deduct from drivers and vendors
            $safePhone = mysqli_real_escape_string($conn, $vPhone);
            $conn->query("UPDATE drivers SET wallet_balance = wallet_balance - $totalDeductionAmount WHERE phone_number = '$safePhone'");
            $conn->query("UPDATE vendors SET wallet_balance = wallet_balance - $totalDeductionAmount WHERE phone_number = '$safePhone'");

            // Record transaction ledger
            $desc = "Platform Commission (" . number_format($companySharePercent, 1) . "%: ₹" . number_format($commissionAmount, 0) . ") + 5% GST (₹" . number_format($gstAmount, 0) . ") for Local-Duty Trip #" . $booking_id;
            $tType = 'trip_commission_deduct';
            $logStmt = $conn->prepare("INSERT INTO vendor_wallet_transactions (vendor_phone, booking_id, transaction_type, amount, balance_before, balance_after, description) VALUES (?, ?, ?, ?, ?, ?, ?)");
            if ($logStmt) {
                $bIdInt = (int)$booking_id;
                $logStmt->bind_param("sisddds", $vPhone, $bIdInt, $tType, $totalDeductionAmount, $balBefore, $balAfter, $desc);
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
            'gst_deducted' => $gstAmount,
            'total_wallet_deducted' => $totalDeductionAmount
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update booking',
            'error' => $stmt->error
        ]);
    }
} else if (strcasecmp($trip_type, 'One-way') === 0) {
    // 1. Fetch dynamic commission settings from one_way_global_settings
    $companyShareActive = 1;
    $companyShareType = 'percentage';
    $companyShareValue = 15.00;
    $companyShareBasis = 'subtotal';
    $gStmt = $conn->query("SELECT company_share_active, company_share_type, company_share_value, company_share_basis FROM one_way_global_settings WHERE id = 1 LIMIT 1");
    if ($gStmt && $gRow = $gStmt->fetch_assoc()) {
        $companyShareActive = intval($gRow['company_share_active'] ?? 1);
        $companyShareType = $gRow['company_share_type'] ?? 'percentage';
        $companyShareValue = floatval($gRow['company_share_value'] ?? 15.00);
        $companyShareBasis = $gRow['company_share_basis'] ?? 'subtotal';
    }

    // 2. Fetch booking details to check vehicle override or fallback amounts
    $bCarQ = $conn->query("SELECT car_type, agni_amount, vendor_amount, total_amount, base_charge FROM bookings WHERE id = '" . mysqli_real_escape_string($conn, $booking_id) . "' LIMIT 1");
    $carType = '';
    if ($bCarQ && $bCarRow = $bCarQ->fetch_assoc()) {
        $carType = $bCarRow['car_type'] ?? '';
        if ($total_amount === null || $total_amount <= 0) {
            $total_amount = floatval($bCarRow['total_amount'] ?? 0);
        }
        if ($base_charge === null || $base_charge <= 0) {
            $base_charge = floatval($bCarRow['base_charge'] ?? 0);
        }
    }

    // Check vehicle rule override
    if (!empty($carType)) {
        $safeCar = mysqli_real_escape_string($conn, $carType);
        $vRuleQ = $conn->query("SELECT company_share_percent FROM one_way_vehicle_rules WHERE car_type_label = '$safeCar' OR car_type_id = '$safeCar' LIMIT 1");
        if ($vRuleQ && $vRow = $vRuleQ->fetch_assoc()) {
            $vehOverride = floatval($vRow['company_share_percent'] ?? 0.0);
            if ($vehOverride > 0) {
                $companyShareValue = $vehOverride;
                $companyShareType = 'percentage';
            }
        }
    }

    // 3. Calculate dynamic commission amount + 5% GST (One-Way total_amount is already GST inclusive)
    $basisAmount = ($total_amount > 0) ? $total_amount : (($base_charge > 0) ? $base_charge : 0);
    $base_charge = $basisAmount;

    $preTaxSubtotal = round($basisAmount / 1.05, 2);
    $gstAmount = round($basisAmount - $preTaxSubtotal, 2);

    $commissionBasis = ($companyShareBasis === 'base_km') ? $preTaxSubtotal : $preTaxSubtotal;
    $commissionAmount = 0.00;
    if ($companyShareActive) {
        if ($companyShareType === 'fixed') {
            $commissionAmount = round(min($commissionBasis, $companyShareValue), 2);
        } else {
            $commissionAmount = round($commissionBasis * ($companyShareValue / 100.0), 2);
        }
    }

    $totalDeductionAmount = round($commissionAmount + $gstAmount, 2);
    $agni_amount = $totalDeductionAmount;
    $vendor_amount = max(0, round($basisAmount - $totalDeductionAmount, 2));

    // Prepare and bind
    $stmt = $conn->prepare("UPDATE bookings SET booking_status = ?, closing_km = ?, closing_date = ?, closing_time = ? ,invoice_no = ?, invoice_date = ?, toll_charge = ?, parking_charge =? , permit_charge =?, total_amount = ?, vendor_amount =?, agni_amount =? ,base_charge=? WHERE id = ?");
    $stmt->bind_param("sissssddddddss", $status, $closing_km, $closing_date, $closing_time,$next_invoice_no , $invoice_date, $toll_charge, $parking_charge, $permit_charge, $total_amount, $vendor_amount, $agni_amount, $base_charge,$booking_id);

    // Execute
    if ($stmt->execute()) {
        // 4. Deduct commission and GST from Vendor/Driver Prepaid Wallet
        $vPhone = '';
        $bQ = $conn->query("SELECT vender_id, driver_id FROM bookings WHERE id = '" . mysqli_real_escape_string($conn, $booking_id) . "' LIMIT 1");
        if ($bQ && $brow = $bQ->fetch_assoc()) {
            $vPhone = !empty($brow['vender_id']) ? $brow['vender_id'] : ($brow['driver_id'] ?? '');
        }

        if (!empty($vPhone) && $totalDeductionAmount > 0) {
            $balBefore = 0.00;
            $wQ = $conn->query("SELECT wallet_balance FROM drivers WHERE phone_number = '" . mysqli_real_escape_string($conn, $vPhone) . "' LIMIT 1");
            if ($wQ && $wrow = $wQ->fetch_assoc()) {
                $balBefore = (float)$wrow['wallet_balance'];
            } else {
                $vwQ = $conn->query("SELECT wallet_balance FROM vendors WHERE phone_number = '" . mysqli_real_escape_string($conn, $vPhone) . "' LIMIT 1");
                if ($vwQ && $vwrow = $vwQ->fetch_assoc()) {
                    $balBefore = (float)$vwrow['wallet_balance'];
                }
            }
            $balAfter = $balBefore - $totalDeductionAmount;

            // Deduct from drivers and vendors
            $safePhone = mysqli_real_escape_string($conn, $vPhone);
            $conn->query("UPDATE drivers SET wallet_balance = wallet_balance - $totalDeductionAmount WHERE phone_number = '$safePhone'");
            $conn->query("UPDATE vendors SET wallet_balance = wallet_balance - $totalDeductionAmount WHERE phone_number = '$safePhone'");

            // Record transaction ledger
            $shareDesc = ($companyShareType === 'fixed') ? "₹" . number_format($companyShareValue, 2) : number_format($companyShareValue, 1) . "%";
            $desc = "Platform Commission (" . $shareDesc . ": ₹" . number_format($commissionAmount, 0) . ") + 5% GST (₹" . number_format($gstAmount, 0) . ") for One-Way Trip #" . $booking_id;
            $tType = 'trip_commission_deduct';
            $logStmt = $conn->prepare("INSERT INTO vendor_wallet_transactions (vendor_phone, booking_id, transaction_type, amount, balance_before, balance_after, description) VALUES (?, ?, ?, ?, ?, ?, ?)");
            if ($logStmt) {
                $bIdInt = (int)$booking_id;
                $logStmt->bind_param("sisddds", $vPhone, $bIdInt, $tType, $totalDeductionAmount, $balBefore, $balAfter, $desc);
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
            'commission_value' => $companyShareValue,
            'commission_type' => $companyShareType
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update booking',
            'error' => $stmt->error
        ]);
    }
} else if (strcasecmp($trip_type, 'Local-taxi') === 0 || strcasecmp($trip_type, 'Local taxi') === 0) {
    // 1. Fetch dynamic commission and GST from local_taxi_global_settings
    $companySharePercent = 10.00;
    $gstActive = 1;
    $gstRate = 5.00;
    $gStmt = $conn->query("SELECT company_share_value, company_share_active, gst_active, gst_rate FROM local_taxi_global_settings WHERE id = 1 LIMIT 1");
    if ($gStmt && $gRow = $gStmt->fetch_assoc()) {
        if (!empty($gRow['company_share_active'])) {
            $companySharePercent = (float)($gRow['company_share_value'] ?? 10.00);
        }
        $gstActive = (int)($gRow['gst_active'] ?? 1);
        $gstRate = (float)($gRow['gst_rate'] ?? 5.00);
    }

    $preTaxWithExtra = ($total_amount - $toll_charge - $parking_charge - $permit_charge);
    $preTaxBase = ($gstActive && $gstRate > 0 && $preTaxWithExtra > 0) ? round($preTaxWithExtra / (1 + ($gstRate / 100.0)), 2) : $preTaxWithExtra;
    $gstAmount = max(0.0, round($preTaxWithExtra - $preTaxBase, 2));

    $commissionAmount = round(($preTaxBase * ($companySharePercent / 100.0)), 2);
    $totalDeductionAmount = round($commissionAmount + $gstAmount, 2);

    $agni_amount = $totalDeductionAmount;
    $vendor_amount = max(0, round(($preTaxBase - $commissionAmount) + $toll_charge + $parking_charge + $permit_charge, 2));

    // Prepare and bind - update total_amount, vendor_amount, and agni_amount
    $stmt = $conn->prepare("UPDATE bookings SET booking_status = ?, closing_km = ?, closing_date = ?, closing_time = ?, total_amount = ?, vendor_amount = ?, agni_amount = ?, invoice_no = ?, invoice_date = ?, toll_charge =?, parking_charge =?, permit_charge =? WHERE id = ?");
    $stmt->bind_param("sissdddssddds", $status, $closing_km, $closing_date, $closing_time, $total_amount, $vendor_amount, $agni_amount, $next_invoice_no, $invoice_date, $toll_charge, $parking_charge, $permit_charge, $booking_id);

    // Execute
    if ($stmt->execute()) {
        // 3. Deduct commission and GST from Vendor/Driver Prepaid Wallet
        $vPhone = '';
        $bQ = $conn->query("SELECT vender_id, driver_id FROM bookings WHERE id = '" . mysqli_real_escape_string($conn, $booking_id) . "' LIMIT 1");
        if ($bQ && $brow = $bQ->fetch_assoc()) {
            $vPhone = !empty($brow['vender_id']) ? $brow['vender_id'] : ($brow['driver_id'] ?? '');
        }

        if (!empty($vPhone) && $totalDeductionAmount > 0) {
            $balBefore = 0.00;
            $wQ = $conn->query("SELECT wallet_balance FROM drivers WHERE phone_number = '" . mysqli_real_escape_string($conn, $vPhone) . "' LIMIT 1");
            if ($wQ && $wrow = $wQ->fetch_assoc()) {
                $balBefore = (float)$wrow['wallet_balance'];
            }
            $balAfter = $balBefore - $totalDeductionAmount;

            // Deduct from drivers and vendors
            $safePhone = mysqli_real_escape_string($conn, $vPhone);
            $conn->query("UPDATE drivers SET wallet_balance = wallet_balance - $totalDeductionAmount WHERE phone_number = '$safePhone'");
            $conn->query("UPDATE vendors SET wallet_balance = wallet_balance - $totalDeductionAmount WHERE phone_number = '$safePhone'");

            // Record transaction ledger
            $desc = "Platform Commission (" . number_format($companySharePercent, 1) . "%: ₹" . number_format($commissionAmount, 0) . ") + 5% GST (₹" . number_format($gstAmount, 0) . ") for Local Taxi Trip #" . $booking_id;
            $tType = 'trip_commission_deduct';
            $logStmt = $conn->prepare("INSERT INTO vendor_wallet_transactions (vendor_phone, booking_id, transaction_type, amount, balance_before, balance_after, description) VALUES (?, ?, ?, ?, ?, ?, ?)");
            if ($logStmt) {
                $bIdInt = (int)$booking_id;
                $logStmt->bind_param("sisddds", $vPhone, $bIdInt, $tType, $totalDeductionAmount, $balBefore, $balAfter, $desc);
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
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid or unsupported trip type: ' . $trip_type
    ]);
}

if (isset($stmt) && $stmt) {
    $stmt->close();
}
$conn->close();
?>
