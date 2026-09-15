<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Load DB connection
if (file_exists(__DIR__ . '/db_connect.php')) {
    require_once __DIR__ . '/db_connect.php';
} else {
    require_once __DIR__ . '/../2025/db_connect.php';
}

date_default_timezone_set('Asia/Kolkata');

// Auto self-heal schema
try {
    $conn->query("ALTER TABLE drivers ADD COLUMN wallet_balance DECIMAL(10, 2) NOT NULL DEFAULT 0.00");
} catch (Throwable $e) {}

try {
    $conn->query("ALTER TABLE vendors ADD COLUMN wallet_balance DECIMAL(10, 2) NOT NULL DEFAULT 0.00");
} catch (Throwable $e) {}

try {
    $conn->query("ALTER TABLE local_taxi_global_settings ADD COLUMN min_wallet_balance DECIMAL(10, 2) NOT NULL DEFAULT 0.00");
} catch (Throwable $e) {}

try {
    $conn->query("CREATE TABLE IF NOT EXISTS vendor_wallet_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vendor_phone VARCHAR(20) NOT NULL,
        booking_id INT DEFAULT NULL,
        transaction_type ENUM('trip_commission_deduct', 'wallet_recharge', 'admin_adjustment', 'refund') NOT NULL,
        amount DECIMAL(10, 2) NOT NULL,
        balance_before DECIMAL(10, 2) NOT NULL,
        balance_after DECIMAL(10, 2) NOT NULL,
        description VARCHAR(255) NOT NULL,
        reference_id VARCHAR(100) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_vendor_phone (vendor_phone),
        INDEX idx_created_at (created_at),
        INDEX idx_booking_id (booking_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Throwable $e) {}

$rawPayload = file_get_contents('php://input');
$jsonData = json_decode($rawPayload, true) ?? [];
$action = $_GET['action'] ?? $_POST['action'] ?? $jsonData['action'] ?? 'get_wallet';

// Helper: Get global local taxi settings
function getLocalTaxiSettings($conn) {
    $minBalance = 0.00;
    $commissionRate = 10.00;
    $res = $conn->query("SELECT min_wallet_balance, company_share_value FROM local_taxi_global_settings WHERE id = 1 LIMIT 1");
    if ($res && $row = $res->fetch_assoc()) {
        $minBalance = (float)($row['min_wallet_balance'] ?? 0.00);
        $commissionRate = (float)($row['company_share_value'] ?? 10.00);
    }
    return [
        'min_wallet_balance' => $minBalance,
        'commission_rate' => $commissionRate
    ];
}

// Helper: Fetch vendor current wallet balance
function getVendorWalletBalance($conn, $phone) {
    $phone = trim($phone);
    $bal = 0.00;
    $stmt = $conn->prepare("SELECT wallet_balance FROM drivers WHERE phone_number = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $phone);
        $stmt->execute();
        $stmt->bind_result($bal);
        if ($stmt->fetch()) {
            $stmt->close();
            return (float)$bal;
        }
        $stmt->close();
    }

    $vbal = 0.00;
    $vstmt = $conn->prepare("SELECT wallet_balance FROM vendors WHERE phone_number = ? LIMIT 1");
    if ($vstmt) {
        $vstmt->bind_param("s", $phone);
        $vstmt->execute();
        $vstmt->bind_result($vbal);
        if ($vstmt->fetch()) {
            $vstmt->close();
            return (float)$vbal;
        }
        $vstmt->close();
    }
    return 0.00;
}

switch ($action) {
    // ==========================================
    // 1. GET WALLET DETAILS & TRANSACTIONS
    // ==========================================
    case 'get_wallet':
        $phone = trim($_GET['phone_number'] ?? $_POST['phone_number'] ?? $jsonData['phone_number'] ?? '');
        $settings = getLocalTaxiSettings($conn);
        $minBalance = $settings['min_wallet_balance'];
        $commissionRate = $settings['commission_rate'];

        if (empty($phone)) {
            echo json_encode([
                "status" => "success",
                "min_wallet_balance" => $minBalance,
                "commission_rate" => $commissionRate,
                "message" => "Global wallet settings"
            ]);
            exit;
        }

        $balance = getVendorWalletBalance($conn, $phone);
        $isEligible = ($balance >= $minBalance);

        // Fetch transaction history
        $txStmt = $conn->prepare("SELECT id, booking_id, transaction_type, amount, balance_before, balance_after, description, reference_id, created_at FROM vendor_wallet_transactions WHERE vendor_phone = ? ORDER BY id DESC LIMIT 50");
        $txList = [];
        if ($txStmt) {
            $txStmt->bind_param("s", $phone);
            $txStmt->execute();
            $res = $txStmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $txList[] = [
                    "id" => (int)$row["id"],
                    "booking_id" => $row["booking_id"] ? (int)$row["booking_id"] : null,
                    "transaction_type" => $row["transaction_type"],
                    "amount" => (float)$row["amount"],
                    "balance_before" => (float)$row["balance_before"],
                    "balance_after" => (float)$row["balance_after"],
                    "description" => $row["description"],
                    "reference_id" => $row["reference_id"],
                    "created_at" => $row["created_at"]
                ];
            }
            $txStmt->close();
        }

        echo json_encode([
            "status" => "success",
            "phone_number" => $phone,
            "wallet_balance" => $balance,
            "min_wallet_balance" => $minBalance,
            "commission_rate" => $commissionRate,
            "is_eligible_for_local_taxi" => $isEligible,
            "transactions" => $txList
        ]);
        exit;

    // ==========================================
    // 2. RECHARGE WALLET (Razorpay / Instant Credit)
    // ==========================================
    case 'recharge_wallet':
        $phone = trim($_POST['phone_number'] ?? $jsonData['phone_number'] ?? '');
        $amount = (float)($_POST['amount'] ?? $jsonData['amount'] ?? 0);
        $payment_id = trim($_POST['payment_id'] ?? $jsonData['payment_id'] ?? '');
        $description = trim($_POST['description'] ?? $jsonData['description'] ?? 'Wallet recharge');

        if (empty($phone) || $amount <= 0) {
            echo json_encode(["status" => "error", "message" => "Valid phone number and positive recharge amount are required."]);
            exit;
        }

        // Prevent duplicate payment reference processing
        if (!empty($payment_id)) {
            $chkDup = $conn->prepare("SELECT id FROM vendor_wallet_transactions WHERE reference_id = ? AND transaction_type = 'wallet_recharge' LIMIT 1");
            if ($chkDup) {
                $chkDup->bind_param("s", $payment_id);
                $chkDup->execute();
                $chkDup->store_result();
                if ($chkDup->num_rows > 0) {
                    $chkDup->close();
                    $bal = getVendorWalletBalance($conn, $phone);
                    echo json_encode(["status" => "success", "message" => "Payment already credited.", "wallet_balance" => $bal]);
                    exit;
                }
                $chkDup->close();
            }
        }

        $balanceBefore = getVendorWalletBalance($conn, $phone);
        $balanceAfter = $balanceBefore + $amount;

        // Update drivers table
        $u1 = $conn->prepare("UPDATE drivers SET wallet_balance = wallet_balance + ? WHERE phone_number = ?");
        if ($u1) {
            $u1->bind_param("ds", $amount, $phone);
            $u1->execute();
            $u1->close();
        }

        // Update vendors table
        $u2 = $conn->prepare("UPDATE vendors SET wallet_balance = wallet_balance + ? WHERE phone_number = ?");
        if ($u2) {
            $u2->bind_param("ds", $amount, $phone);
            $u2->execute();
            $u2->close();
        }

        // Log transaction
        $tType = 'wallet_recharge';
        $logStmt = $conn->prepare("INSERT INTO vendor_wallet_transactions (vendor_phone, transaction_type, amount, balance_before, balance_after, description, reference_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if ($logStmt) {
            $logStmt->bind_param("ssdddss", $phone, $tType, $amount, $balanceBefore, $balanceAfter, $description, $payment_id);
            $logStmt->execute();
            $logStmt->close();
        }

        echo json_encode([
            "status" => "success",
            "message" => "Wallet recharged successfully with ₹" . number_format($amount, 2),
            "wallet_balance" => $balanceAfter,
            "recharged_amount" => $amount
        ]);
        exit;

    // ==========================================
    // 3. ADMIN MANUAL ADJUSTMENT
    // ==========================================
    case 'admin_adjust':
        $phone = trim($_POST['phone_number'] ?? $jsonData['phone_number'] ?? '');
        $amount = (float)($_POST['amount'] ?? $jsonData['amount'] ?? 0);
        $reason = trim($_POST['reason'] ?? $jsonData['reason'] ?? 'Admin adjustment');

        if (empty($phone) || $amount == 0) {
            echo json_encode(["status" => "error", "message" => "Phone number and non-zero amount are required."]);
            exit;
        }

        $balanceBefore = getVendorWalletBalance($conn, $phone);
        $balanceAfter = $balanceBefore + $amount;

        // Update drivers & vendors
        $u1 = $conn->prepare("UPDATE drivers SET wallet_balance = wallet_balance + ? WHERE phone_number = ?");
        if ($u1) {
            $u1->bind_param("ds", $amount, $phone);
            $u1->execute();
            $u1->close();
        }
        $u2 = $conn->prepare("UPDATE vendors SET wallet_balance = wallet_balance + ? WHERE phone_number = ?");
        if ($u2) {
            $u2->bind_param("ds", $amount, $phone);
            $u2->execute();
            $u2->close();
        }

        $tType = 'admin_adjustment';
        $logStmt = $conn->prepare("INSERT INTO vendor_wallet_transactions (vendor_phone, transaction_type, amount, balance_before, balance_after, description) VALUES (?, ?, ?, ?, ?, ?)");
        if ($logStmt) {
            $logStmt->bind_param("ssddds", $phone, $tType, $amount, $balanceBefore, $balanceAfter, $reason);
            $logStmt->execute();
            $logStmt->close();
        }

        echo json_encode([
            "status" => "success",
            "message" => "Balance adjusted successfully.",
            "wallet_balance" => $balanceAfter,
            "adjusted_amount" => $amount
        ]);
        exit;

    // ==========================================
    // 4. GET GLOBAL WALLET SETTINGS
    // ==========================================
    case 'get_wallet_settings':
        $settings = getLocalTaxiSettings($conn);
        echo json_encode([
            "status" => "success",
            "min_wallet_balance" => (float)$settings['min_wallet_balance'],
            "commission_rate" => (float)$settings['commission_rate']
        ]);
        exit;

    // ==========================================
    // 5. UPDATE MINIMUM WALLET BALANCE (Admin)
    // ==========================================
    case 'update_min_wallet_balance':
        $minBalance = isset($_POST['min_wallet_balance']) 
            ? (float)$_POST['min_wallet_balance'] 
            : (isset($jsonData['min_wallet_balance']) ? (float)$jsonData['min_wallet_balance'] : null);

        if ($minBalance === null || $minBalance < 0) {
            echo json_encode(["status" => "error", "message" => "Valid non-negative minimum wallet balance is required."]);
            exit;
        }

        $uStmt = $conn->prepare("UPDATE local_taxi_global_settings SET min_wallet_balance = ? WHERE id = 1");
        if ($uStmt) {
            $uStmt->bind_param("d", $minBalance);
            $uStmt->execute();
            $uStmt->close();
        }

        echo json_encode([
            "status" => "success",
            "message" => "Minimum required wallet balance updated successfully to ₹" . number_format($minBalance, 2),
            "min_wallet_balance" => $minBalance
        ]);
        exit;

    default:
        echo json_encode(["status" => "error", "message" => "Unknown action."]);
        exit;
}
