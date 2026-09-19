<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Include DB Connection
if (file_exists(__DIR__ . '/db_connect.php')) {
    require_once __DIR__ . '/db_connect.php';
} else {
    require_once __DIR__ . '/../admin_dashboard/db_connect.php';
}

date_default_timezone_set('Asia/Kolkata');

// ── Self-healing Schema Setup ────────────────────────────────────────────────
try {
    $conn->query("CREATE TABLE IF NOT EXISTS agents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        phone_number VARCHAR(20) NOT NULL UNIQUE,
        full_name VARCHAR(255) NOT NULL,
        agency_name VARCHAR(255) NOT NULL,
        address TEXT NOT NULL,
        gst_number VARCHAR(50) DEFAULT NULL,
        pan_doc VARCHAR(255) DEFAULT NULL,
        profile_photo VARCHAR(255) DEFAULT NULL,
        status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
        rejection_reason TEXT DEFAULT NULL,
        wallet_balance DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_phone (phone_number),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Throwable $e) {}

try {
    $conn->query("CREATE TABLE IF NOT EXISTS agent_wallet_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        agent_phone VARCHAR(20) NOT NULL,
        transaction_type ENUM('wallet_recharge', 'trip_booking_advance', 'commission_payout', 'admin_adjustment', 'refund') NOT NULL,
        amount DECIMAL(10, 2) NOT NULL,
        balance_before DECIMAL(10, 2) NOT NULL,
        balance_after DECIMAL(10, 2) NOT NULL,
        description VARCHAR(255) NOT NULL,
        reference_id VARCHAR(100) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_agent_phone (agent_phone),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Throwable $e) {}

// Upload folder setup
$uploadDir = __DIR__ . '/uploads/agents/';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

// Extract inputs
$rawInput = file_get_contents("php://input");
$jsonData = json_decode($rawInput, true) ?? [];

$action = $_GET['action'] ?? $_POST['action'] ?? $jsonData['action'] ?? '';

switch ($action) {
    // ==========================================
    // 1. CHECK AGENT STATUS
    // ==========================================
    case 'check_status':
        $phone = trim($_GET['phone_number'] ?? $_POST['phone_number'] ?? $jsonData['phone_number'] ?? '');
        if (empty($phone)) {
            echo json_encode(["status" => "error", "message" => "Phone number is required."]);
            exit;
        }

        $stmt = $conn->prepare("SELECT id, phone_number, full_name, agency_name, address, gst_number, pan_doc, profile_photo, status, rejection_reason, wallet_balance, created_at FROM agents WHERE phone_number = ? LIMIT 1");
        if (!$stmt) {
            echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
            exit;
        }

        $stmt->bind_param("s", $phone);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($row = $res->fetch_assoc()) {
            echo json_encode([
                "status" => "success",
                "registered" => true,
                "agent" => [
                    "id" => (int)$row['id'],
                    "phone_number" => $row['phone_number'],
                    "full_name" => $row['full_name'],
                    "agency_name" => $row['agency_name'],
                    "address" => $row['address'],
                    "gst_number" => $row['gst_number'],
                    "pan_doc" => $row['pan_doc'],
                    "profile_photo" => $row['profile_photo'],
                    "status" => $row['status'],
                    "rejection_reason" => $row['rejection_reason'],
                    "wallet_balance" => (float)$row['wallet_balance'],
                    "created_at" => $row['created_at']
                ]
            ]);
        } else {
            echo json_encode([
                "status" => "success",
                "registered" => false,
                "message" => "Agent not registered yet."
            ]);
        }
        $stmt->close();
        exit;

    // ==========================================
    // 2. SUBMIT AGENT REGISTRATION / RE-APPLICATION
    // ==========================================
    case 'register_agent':
        $phone = trim($_POST['phone_number'] ?? $jsonData['phone_number'] ?? '');
        $fullName = trim($_POST['full_name'] ?? $jsonData['full_name'] ?? '');
        $agencyName = trim($_POST['agency_name'] ?? $jsonData['agency_name'] ?? '');
        $address = trim($_POST['address'] ?? $jsonData['address'] ?? '');
        $gstNumber = trim($_POST['gst_number'] ?? $jsonData['gst_number'] ?? '');

        if (empty($phone) || empty($fullName) || empty($agencyName) || empty($address)) {
            echo json_encode([
                "status" => "error",
                "message" => "Full name, agency name, phone number, and address are required."
            ]);
            exit;
        }

        // Handle File Uploads (PAN / Business Doc & Profile Photo)
        $panDocUrl = null;
        $profilePhotoUrl = null;

        // Existing file paths if re-submitting
        $chk = $conn->prepare("SELECT pan_doc, profile_photo FROM agents WHERE phone_number = ? LIMIT 1");
        if ($chk) {
            $chk->bind_param("s", $phone);
            $chk->execute();
            $cRes = $chk->get_result();
            if ($cRow = $cRes->fetch_assoc()) {
                $panDocUrl = $cRow['pan_doc'];
                $profilePhotoUrl = $cRow['profile_photo'];
            }
            $chk->close();
        }

        if (isset($_FILES['pan_doc']) && $_FILES['pan_doc']['error'] === UPLOAD_ERR_OK) {
            $tmp = $_FILES['pan_doc']['tmp_name'];
            $ext = strtolower(pathinfo($_FILES['pan_doc']['name'], PATHINFO_EXTENSION));
            $allowedExts = ['jpg', 'jpeg', 'png', 'pdf', 'webp'];
            if (in_array($ext, $allowedExts)) {
                $fileName = 'pan_' . $phone . '_' . time() . '.' . $ext;
                if (move_uploaded_file($tmp, $uploadDir . $fileName)) {
                    $panDocUrl = 'uploads/agents/' . $fileName;
                }
            }
        }

        if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
            $tmp = $_FILES['profile_photo']['tmp_name'];
            $ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
            $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
            if (in_array($ext, $allowedExts)) {
                $fileName = 'profile_' . $phone . '_' . time() . '.' . $ext;
                if (move_uploaded_file($tmp, $uploadDir . $fileName)) {
                    $profilePhotoUrl = 'uploads/agents/' . $fileName;
                }
            }
        }

        // Upsert into agents table with status 'pending'
        $stmt = $conn->prepare("INSERT INTO agents (phone_number, full_name, agency_name, address, gst_number, pan_doc, profile_photo, status, rejection_reason) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NULL)
            ON DUPLICATE KEY UPDATE 
                full_name = VALUES(full_name),
                agency_name = VALUES(agency_name),
                address = VALUES(address),
                gst_number = VALUES(gst_number),
                pan_doc = COALESCE(VALUES(pan_doc), pan_doc),
                profile_photo = COALESCE(VALUES(profile_photo), profile_photo),
                status = 'pending',
                rejection_reason = NULL,
                updated_at = NOW()");

        if (!$stmt) {
            echo json_encode(["status" => "error", "message" => "Database preparation failed: " . $conn->error]);
            exit;
        }

        $stmt->bind_param("sssssss", $phone, $fullName, $agencyName, $address, $gstNumber, $panDocUrl, $profilePhotoUrl);

        if ($stmt->execute()) {
            echo json_encode([
                "status" => "success",
                "message" => "Application submitted successfully! Your account is now pending admin approval.",
                "agent" => [
                    "phone_number" => $phone,
                    "full_name" => $fullName,
                    "agency_name" => $agencyName,
                    "status" => "pending"
                ]
            ]);
        } else {
            echo json_encode(["status" => "error", "message" => "Failed to save application: " . $stmt->error]);
        }
        $stmt->close();
        exit;

    // ==========================================
    // 3. GET AGENT WALLET & TRANSACTIONS
    // ==========================================
    case 'get_wallet':
        $phone = trim($_GET['phone_number'] ?? $_POST['phone_number'] ?? $jsonData['phone_number'] ?? '');
        if (empty($phone)) {
            echo json_encode(["status" => "error", "message" => "Phone number is required."]);
            exit;
        }

        $stmt = $conn->prepare("SELECT id, status, wallet_balance FROM agents WHERE phone_number = ? LIMIT 1");
        if (!$stmt) {
            echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
            exit;
        }
        $stmt->bind_param("s", $phone);
        $stmt->execute();
        $res = $stmt->get_result();
        $agent = $res->fetch_assoc();
        $stmt->close();

        if (!$agent) {
            echo json_encode(["status" => "error", "message" => "Agent not found."]);
            exit;
        }

        if ($agent['status'] !== 'approved') {
            echo json_encode([
                "status" => "locked",
                "agent_status" => $agent['status'],
                "message" => "Agent account is not yet approved by admin."
            ]);
            exit;
        }

        // Fetch transactions
        $txStmt = $conn->prepare("SELECT id, transaction_type, amount, balance_before, balance_after, description, reference_id, created_at FROM agent_wallet_transactions WHERE agent_phone = ? ORDER BY id DESC LIMIT 50");
        $txList = [];
        if ($txStmt) {
            $txStmt->bind_param("s", $phone);
            $txStmt->execute();
            $txRes = $txStmt->get_result();
            while ($tx = $txRes->fetch_assoc()) {
                $txList[] = [
                    "id" => (int)$tx['id'],
                    "transaction_type" => $tx['transaction_type'],
                    "amount" => (float)$tx['amount'],
                    "balance_before" => (float)$tx['balance_before'],
                    "balance_after" => (float)$tx['balance_after'],
                    "description" => $tx['description'],
                    "reference_id" => $tx['reference_id'],
                    "created_at" => $tx['created_at']
                ];
            }
            $txStmt->close();
        }

        echo json_encode([
            "status" => "success",
            "phone_number" => $phone,
            "wallet_balance" => (float)$agent['wallet_balance'],
            "transactions" => $txList
        ]);
        exit;

    // ==========================================
    // 4. RECHARGE WALLET (RAZORPAY)
    // ==========================================
    case 'recharge_wallet':
        $phone = trim($_POST['phone_number'] ?? $jsonData['phone_number'] ?? '');
        $amount = (float)($_POST['amount'] ?? $jsonData['amount'] ?? 0);
        $paymentId = trim($_POST['payment_id'] ?? $jsonData['payment_id'] ?? '');
        $description = trim($_POST['description'] ?? $jsonData['description'] ?? 'Agent Wallet Recharge via Razorpay');

        if (empty($phone) || $amount <= 0) {
            echo json_encode(["status" => "error", "message" => "Valid phone number and positive recharge amount are required."]);
            exit;
        }

        // Verify agent is approved
        $stmt = $conn->prepare("SELECT id, status, wallet_balance FROM agents WHERE phone_number = ? LIMIT 1");
        $stmt->bind_param("s", $phone);
        $stmt->execute();
        $res = $stmt->get_result();
        $agent = $res->fetch_assoc();
        $stmt->close();

        if (!$agent || $agent['status'] !== 'approved') {
            echo json_encode(["status" => "error", "message" => "Only approved agents can add money."]);
            exit;
        }

        // Duplicate reference prevention
        if (!empty($paymentId)) {
            $chkDup = $conn->prepare("SELECT id FROM agent_wallet_transactions WHERE reference_id = ? AND transaction_type = 'wallet_recharge' LIMIT 1");
            if ($chkDup) {
                $chkDup->bind_param("s", $paymentId);
                $chkDup->execute();
                $chkDup->store_result();
                if ($chkDup->num_rows > 0) {
                    $chkDup->close();
                    echo json_encode([
                        "status" => "success",
                        "message" => "Payment already processed.",
                        "wallet_balance" => (float)$agent['wallet_balance']
                    ]);
                    exit;
                }
                $chkDup->close();
            }
        }

        $balanceBefore = (float)$agent['wallet_balance'];
        $balanceAfter = $balanceBefore + $amount;

        // Update balance
        $upd = $conn->prepare("UPDATE agents SET wallet_balance = wallet_balance + ? WHERE phone_number = ?");
        $upd->bind_param("ds", $amount, $phone);
        $upd->execute();
        $upd->close();

        // Record transaction
        $ins = $conn->prepare("INSERT INTO agent_wallet_transactions (agent_phone, transaction_type, amount, balance_before, balance_after, description, reference_id) VALUES (?, 'wallet_recharge', ?, ?, ?, ?, ?)");
        $ins->bind_param("sdddss", $phone, $amount, $balanceBefore, $balanceAfter, $description, $paymentId);
        $ins->execute();
        $ins->close();

        echo json_encode([
            "status" => "success",
            "message" => "Wallet recharged successfully!",
            "wallet_balance" => $balanceAfter
        ]);
        exit;

    default:
        echo json_encode(["status" => "error", "message" => "Invalid or missing action."]);
        exit;
}
?>
