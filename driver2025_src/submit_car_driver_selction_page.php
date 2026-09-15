<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

include 'db_connect.php'; // Include database connection

$inputData = file_get_contents("php://input");
$data = json_decode($inputData, true);

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $driver_id = isset($data['driver_id']) ? mysqli_real_escape_string($conn, $data['driver_id']) : NULL;
    $vehicle_id = isset($data['vehicle_id']) ? mysqli_real_escape_string($conn, $data['vehicle_id']) : NULL;
    $booking_id = isset($data['booking_id']) ? mysqli_real_escape_string($conn, $data['booking_id']) : '';
    $vender_id = isset($data['vender_id']) ? mysqli_real_escape_string($conn, $data['vender_id']) : '';

    if (empty($booking_id)) {
        echo json_encode(["success" => false, "message" => "Invalid booking ID"]);
        exit;
    }

    // 1️⃣ Get the date and trip_type of the selected booking
    $sqlDate = "SELECT date, trip_type FROM bookings WHERE id = ?";
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
    $trip_type = $bookingRow['trip_type'] ?? '';

    // 🔒 Wallet Balance Validation for Local Taxi and One-Way rides
    if (stripos($trip_type, 'Local') !== false || stripos($trip_type, 'taxi') !== false || stripos($trip_type, 'One-way') !== false || stripos($trip_type, 'One-Way') !== false) {
        $minWalletBalance = 0.00;
        
        if (stripos($trip_type, 'Local') !== false || stripos($trip_type, 'taxi') !== false) {
            $setStmt = $conn->query("SELECT min_wallet_balance FROM local_taxi_global_settings WHERE id = 1 LIMIT 1");
            if ($setStmt && $sRow = $setStmt->fetch_assoc()) {
                $minWalletBalance = (float)($sRow['min_wallet_balance'] ?? 0.00);
            }
        } else {
            $setStmt = $conn->query("SELECT min_wallet_balance FROM one_way_global_settings WHERE id = 1 LIMIT 1");
            if ($setStmt && $sRow = $setStmt->fetch_assoc()) {
                $minWalletBalance = (float)($sRow['min_wallet_balance'] ?? 0.00);
            }
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
            $rideTypeLabel = (stripos($trip_type, 'Local') !== false || stripos($trip_type, 'taxi') !== false) ? "Local Taxi" : "One-Way";
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

    // 2️⃣ Check for driver or vehicle conflict within past 2 and next 2 days relative to selected booking date
    $sqlConflict = "
        SELECT id, driver_id, vehicle_id, date, booking_status
        FROM bookings
        WHERE date BETWEEN DATE_SUB(?, INTERVAL 2 DAY) AND DATE_ADD(?, INTERVAL 2 DAY)
        AND id != ?
        AND booking_status != 'Completed'
        AND booking_status != 'Cancelled'
        AND booking_status != 'Customer Cancelled'
        AND (driver_id = ? OR vehicle_id = ?)
    ";

    $stmt = $conn->prepare($sqlConflict);
    $stmt->bind_param("ssiss", $selectedBookingDate, $selectedBookingDate, $booking_id, $driver_id, $vehicle_id);
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
        if ($driverConflict) $messages[] = "Driver already booked for this date.";
        if ($vehicleConflict) $messages[] = "Vehicle already booked for this date.";

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
            if (file_exists(__DIR__ . '/../notification_helper.php')) {
                require_once __DIR__ . '/../notification_helper.php';
            } else {
                require_once __DIR__ . '/../2025/notification_helper.php';
            }
            sendAcceptWhatsAppNotification($booking_id, $conn);
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
}

$conn->close();
?>
