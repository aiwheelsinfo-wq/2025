<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', 0);

include 'db_connect.php'; // Include database connection

try {
    $inputData = file_get_contents("php://input");
    $data = json_decode($inputData, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $driver_id = isset($data['driver_id']) ? mysqli_real_escape_string($conn, $data['driver_id']) : NULL;
    $vehicle_id = isset($data['vehicle_id']) ? mysqli_real_escape_string($conn, $data['vehicle_id']) : NULL;
    $booking_id = isset($data['booking_id']) ? mysqli_real_escape_string($conn, (string)$data['booking_id']) : '';
    $vender_id = isset($data['vender_id']) ? mysqli_real_escape_string($conn, (string)$data['vender_id']) : '';

    if (empty($booking_id)) {
        echo json_encode(["success" => false, "message" => "Invalid booking ID"]);
        exit;
    }

    // 1️⃣ Get the date, return_date, and trip_type of the selected booking
    $sqlDate = "SELECT date, return_date, trip_type FROM bookings WHERE id = ?";
    $stmt = $conn->prepare($sqlDate);
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $bookingRow = $result->fetch_assoc();
    $stmt->close();

    if (!$bookingRow) {
        echo json_encode(["success" => false, "message" => "Booking not found"]);
        exit;
    }

    $selectedBookingDate = $bookingRow['date'];
    $selectedReturnDate = (!empty($bookingRow['return_date']) && $bookingRow['return_date'] != '1970-01-01' && $bookingRow['return_date'] != '0000-00-00' && $bookingRow['return_date'] >= $selectedBookingDate) 
        ? $bookingRow['return_date'] 
        : $selectedBookingDate;
    $trip_type = $bookingRow['trip_type'] ?? '';

    // 🔒 Wallet Balance Validation for Local Taxi, Local-Duty, One-Way, and Round-Trip rides
    $isLocalDuty = (stripos($trip_type, 'duty') !== false);
    $isLocalTaxi = !$isLocalDuty && (stripos($trip_type, 'Local') !== false || stripos($trip_type, 'taxi') !== false);
    $isOneWay = (stripos($trip_type, 'One-way') !== false || stripos($trip_type, 'one way') !== false || stripos($trip_type, 'oneway') !== false);
    $isRoundTrip = (stripos($trip_type, 'Round') !== false || stripos($trip_type, 'round') !== false);

    if ($isLocalDuty || $isLocalTaxi || $isOneWay || $isRoundTrip) {
        $minWalletBalance = 0.00;
        
        try {
            if ($isRoundTrip) {
                $setStmt = $conn->query("SELECT min_wallet_balance FROM round_trip_global_settings WHERE id = 1 LIMIT 1");
                if ($setStmt && $sRow = $setStmt->fetch_assoc() && (float)($sRow['min_wallet_balance'] ?? 0) > 0) {
                    $minWalletBalance = (float)$sRow['min_wallet_balance'];
                } else {
                    $minWalletBalance = 1000.00;
                }
            } else if ($isOneWay) {
                $setStmt = $conn->query("SELECT min_wallet_balance FROM one_way_global_settings WHERE id = 1 LIMIT 1");
                if ($setStmt && $sRow = $setStmt->fetch_assoc() && (float)($sRow['min_wallet_balance'] ?? 0) > 0) {
                    $minWalletBalance = (float)$sRow['min_wallet_balance'];
                } else {
                    $fbStmt = $conn->query("SELECT min_wallet_balance FROM local_taxi_global_settings WHERE id = 1 LIMIT 1");
                    if ($fbStmt && $fbRow = $fbStmt->fetch_assoc()) {
                        $minWalletBalance = (float)($fbRow['min_wallet_balance'] ?? 0.00);
                    }
                }
            } else if ($isLocalDuty) {
                $setStmt = $conn->query("SELECT min_wallet_balance FROM local_duty_global_settings WHERE id = 1 LIMIT 1");
                if ($setStmt && $sRow = $setStmt->fetch_assoc() && (float)($sRow['min_wallet_balance'] ?? 0) > 0) {
                    $minWalletBalance = (float)$sRow['min_wallet_balance'];
                } else {
                    $fbStmt = $conn->query("SELECT min_wallet_balance FROM local_taxi_global_settings WHERE id = 1 LIMIT 1");
                    if ($fbStmt && $fbRow = $fbStmt->fetch_assoc()) {
                        $minWalletBalance = (float)($fbRow['min_wallet_balance'] ?? 0.00);
                    }
                }
            } else {
                $setStmt = $conn->query("SELECT min_wallet_balance FROM local_taxi_global_settings WHERE id = 1 LIMIT 1");
                if ($setStmt && $sRow = $setStmt->fetch_assoc()) {
                    $minWalletBalance = (float)($sRow['min_wallet_balance'] ?? 0.00);
                }
            }
        } catch (Throwable $e) {
            $minWalletBalance = 0.00;
        }

        $vendorWalletBal = 0.00;
        $wStmt = $conn->prepare("SELECT wallet_balance FROM drivers WHERE phone_number = ? LIMIT 1");
        if ($wStmt) {
            $wStmt->bind_param("s", $vender_id);
            $wStmt->execute();
            $wStmt->bind_result($wbal);
            if ($wStmt->fetch()) {
                $vendorWalletBal = (float)$wbal;
            }
            $wStmt->close();
        }

        if ($vendorWalletBal <= 0) {
            $vwStmt = $conn->prepare("SELECT wallet_balance FROM vendors WHERE phone_number = ? LIMIT 1");
            if ($vwStmt) {
                $vwStmt->bind_param("s", $vender_id);
                $vwStmt->execute();
                $vwStmt->bind_result($vwbal);
                if ($vwStmt->fetch() && (float)$vwbal > 0) {
                    $vendorWalletBal = (float)$vwbal;
                }
                $vwStmt->close();
            }
        }

        if ($vendorWalletBal <= $minWalletBalance) {
            $rideTypeLabel = $isRoundTrip ? "Round-Trip" : ($isLocalDuty ? "Local-Duty" : ($isLocalTaxi ? "Local Taxi" : "One-Way"));
            echo json_encode([
                "success" => false,
                "status" => "low_wallet_balance",
                "wallet_balance" => $vendorWalletBal,
                "min_required" => $minWalletBalance,
                "message" => "Insufficient wallet balance (₹" . number_format($vendorWalletBal, 2) . "). Minimum ₹" . number_format($minWalletBalance, 2) . " required to accept $rideTypeLabel trips. Please recharge your wallet."
            ]);
            exit;
        }
    }

    // 2️⃣ Check for driver or vehicle conflict overlapping with this booking's date range
    $sqlConflict = "
        SELECT id, driver_id, vehicle_id, date, return_date, booking_status
        FROM bookings
        WHERE id != ?
        AND booking_status NOT IN ('Completed', 'Cancelled', 'Customer Cancelled')
        AND (driver_id = ? OR vehicle_id = ?)
        AND (
            date <= ?
            AND (CASE WHEN return_date IS NOT NULL AND return_date != '1970-01-01' AND return_date != '0000-00-00' AND return_date >= date THEN return_date ELSE date END) >= ?
        )
    ";

    $stmt = $conn->prepare($sqlConflict);
    $stmt->bind_param("issss", $booking_id, $driver_id, $vehicle_id, $selectedReturnDate, $selectedBookingDate);
    $stmt->execute();
    $result = $stmt->get_result();
    $conflicts = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!empty($conflicts)) {
        $driverConflict = false;
        $vehicleConflict = false;

        foreach ($conflicts as $conflict) {
            if ($conflict['driver_id'] == $driver_id) $driverConflict = true;
            if ($conflict['vehicle_id'] == $vehicle_id) $vehicleConflict = true;
        }

        $messages = [];
        if ($driverConflict) $messages[] = "Driver already booked for an overlapping trip on this date.";
        if ($vehicleConflict) $messages[] = "Vehicle already booked for an overlapping trip on this date.";

        echo json_encode([
            "success" => false,
            "message" => implode(" ", $messages),
            "conflicts" => $conflicts
        ]);
        exit;
    }

    // 3️⃣ Update booking since no conflict exists
    $sqlUpdate = "
        UPDATE bookings 
        SET driver_id = ?, vehicle_id = ?, vender_id = ?, booking_status = 'Accepted' 
        WHERE id = ?
    ";
    $stmt = $conn->prepare($sqlUpdate);
    $stmt->bind_param("sssi", $driver_id, $vehicle_id, $vender_id, $booking_id);

    if ($stmt->execute()) {
        // Send WhatsApp confirmation notification to customer
        try {
            if (file_exists(__DIR__ . '/notification_helper.php')) {
                require_once __DIR__ . '/notification_helper.php';
            } elseif (file_exists(__DIR__ . '/../2025/notification_helper.php')) {
                require_once __DIR__ . '/../2025/notification_helper.php';
            }
            if (function_exists('sendAcceptWhatsAppNotification')) {
                sendAcceptWhatsAppNotification($booking_id, $conn);
            }
        } catch (Throwable $e) {
            error_log("WhatsApp Accept Notification error: " . $e->getMessage());
        }

        echo json_encode([
            "success" => true,
            "message" => "Booking updated successfully"
        ]);
    } else {
        echo json_encode([
            "success" => false,
            "message" => "Error updating booking"
        ]);
    }

    $stmt->close();
} catch (Throwable $e) {
    echo json_encode([
        "success" => false,
        "message" => "Server error: " . $e->getMessage()
    ]);
}

$conn->close();
?>
