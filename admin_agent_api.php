<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (file_exists(__DIR__ . '/db_connect.php')) {
    require_once __DIR__ . '/db_connect.php';
} else {
    require_once __DIR__ . '/../admin_dashboard/db_connect.php';
}

date_default_timezone_set('Asia/Kolkata');

$rawInput = file_get_contents("php://input");
$jsonData = json_decode($rawInput, true) ?? [];

$action = $_GET['action'] ?? $_POST['action'] ?? $jsonData['action'] ?? 'get_agents';

switch ($action) {
    // ==========================================
    // 1. GET ALL AGENTS WITH STATS & FILTERS
    // ==========================================
    case 'get_agents':
        $statusFilter = trim($_GET['status'] ?? $jsonData['status'] ?? 'all');
        $search = trim($_GET['search'] ?? $jsonData['search'] ?? '');

        // Summary counts
        $stats = [
            'total' => 0,
            'pending' => 0,
            'approved' => 0,
            'rejected' => 0,
            'total_wallet' => 0.00
        ];

        $statRes = $conn->query("SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
            SUM(wallet_balance) as total_wallet
        FROM agents");

        if ($statRes && $sRow = $statRes->fetch_assoc()) {
            $stats['total'] = (int)($sRow['total'] ?? 0);
            $stats['pending'] = (int)($sRow['pending'] ?? 0);
            $stats['approved'] = (int)($sRow['approved'] ?? 0);
            $stats['rejected'] = (int)($sRow['rejected'] ?? 0);
            $stats['total_wallet'] = (float)($sRow['total_wallet'] ?? 0.00);
        }

        // Build filtered query
        $whereClauses = [];
        $params = [];
        $types = "";

        if ($statusFilter !== 'all' && in_array($statusFilter, ['pending', 'approved', 'rejected'])) {
            $whereClauses[] = "status = ?";
            $params[] = $statusFilter;
            $types .= "s";
        }

        if (!empty($search)) {
            $whereClauses[] = "(full_name LIKE ? OR phone_number LIKE ? OR agency_name LIKE ? OR gst_number LIKE ?)";
            $searchTerm = "%" . $search . "%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $types .= "ssss";
        }

        $sql = "SELECT id, phone_number, full_name, agency_name, address, gst_number, pan_doc, profile_photo, status, rejection_reason, wallet_balance, created_at, updated_at FROM agents";
        if (!empty($whereClauses)) {
            $sql .= " WHERE " . implode(" AND ", $whereClauses);
        }
        $sql .= " ORDER BY CASE WHEN status = 'pending' THEN 0 WHEN status = 'approved' THEN 1 ELSE 2 END, id DESC";

        $agents = [];
        if (!empty($params)) {
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $agents[] = formatAgentRow($row);
                }
                $stmt->close();
            }
        } else {
            $res = $conn->query($sql);
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $agents[] = formatAgentRow($row);
                }
            }
        }

        echo json_encode([
            "status" => "success",
            "stats" => $stats,
            "agents" => $agents
        ]);
        exit;

    // ==========================================
    // 2. APPROVE AGENT
    // ==========================================
    case 'approve_agent':
        $agentId = (int)($_POST['id'] ?? $jsonData['id'] ?? 0);
        $phone = trim($_POST['phone_number'] ?? $jsonData['phone_number'] ?? '');

        if ($agentId <= 0 && empty($phone)) {
            echo json_encode(["status" => "error", "message" => "Agent ID or phone number is required."]);
            exit;
        }

        $stmt = $conn->prepare("UPDATE agents SET status = 'approved', rejection_reason = NULL, updated_at = NOW() WHERE " . ($agentId > 0 ? "id = ?" : "phone_number = ?"));
        if ($agentId > 0) {
            $stmt->bind_param("i", $agentId);
        } else {
            $stmt->bind_param("s", $phone);
        }

        if ($stmt->execute()) {
            echo json_encode(["status" => "success", "message" => "Agent successfully approved! Access and wallet unlocked."]);
        } else {
            echo json_encode(["status" => "error", "message" => "Failed to approve agent: " . $stmt->error]);
        }
        $stmt->close();
        exit;

    // ==========================================
    // 3. REJECT AGENT
    // ==========================================
    case 'reject_agent':
        $agentId = (int)($_POST['id'] ?? $jsonData['id'] ?? 0);
        $phone = trim($_POST['phone_number'] ?? $jsonData['phone_number'] ?? '');
        $reason = trim($_POST['reason'] ?? $jsonData['reason'] ?? 'Incomplete or invalid documents.');

        if ($agentId <= 0 && empty($phone)) {
            echo json_encode(["status" => "error", "message" => "Agent ID or phone number is required."]);
            exit;
        }

        $stmt = $conn->prepare("UPDATE agents SET status = 'rejected', rejection_reason = ?, updated_at = NOW() WHERE " . ($agentId > 0 ? "id = ?" : "phone_number = ?"));
        if ($agentId > 0) {
            $stmt->bind_param("si", $reason, $agentId);
        } else {
            $stmt->bind_param("ss", $reason, $phone);
        }

        if ($stmt->execute()) {
            echo json_encode(["status" => "success", "message" => "Agent application marked as rejected."]);
        } else {
            echo json_encode(["status" => "error", "message" => "Failed to reject agent: " . $stmt->error]);
        }
        $stmt->close();
        exit;

    // ==========================================
    // 4. ADMIN ADJUST WALLET
    // ==========================================
    case 'adjust_wallet':
        $phone = trim($_POST['phone_number'] ?? $jsonData['phone_number'] ?? '');
        $amount = (float)($_POST['amount'] ?? $jsonData['amount'] ?? 0);
        $reason = trim($_POST['reason'] ?? $jsonData['reason'] ?? 'Admin wallet adjustment');

        if (empty($phone) || $amount == 0) {
            echo json_encode(["status" => "error", "message" => "Phone number and non-zero adjustment amount are required."]);
            exit;
        }

        $stmt = $conn->prepare("SELECT wallet_balance, status FROM agents WHERE phone_number = ? LIMIT 1");
        $stmt->bind_param("s", $phone);
        $stmt->execute();
        $res = $stmt->get_result();
        $agent = $res->fetch_assoc();
        $stmt->close();

        if (!$agent) {
            echo json_encode(["status" => "error", "message" => "Agent not found."]);
            exit;
        }

        $balanceBefore = (float)$agent['wallet_balance'];
        $balanceAfter = $balanceBefore + $amount;
        if ($balanceAfter < 0) {
            echo json_encode(["status" => "error", "message" => "Adjustment would result in negative wallet balance."]);
            exit;
        }

        $uStmt = $conn->prepare("UPDATE agents SET wallet_balance = ? WHERE phone_number = ?");
        $uStmt->bind_param("ds", $balanceAfter, $phone);
        $uStmt->execute();
        $uStmt->close();

        $iStmt = $conn->prepare("INSERT INTO agent_wallet_transactions (agent_phone, transaction_type, amount, balance_before, balance_after, description, reference_id) VALUES (?, 'admin_adjustment', ?, ?, ?, ?, ?)");
        $refId = 'ADM_' . time();
        $iStmt->bind_param("sdddss", $phone, $amount, $balanceBefore, $balanceAfter, $reason, $refId);
        $iStmt->execute();
        $iStmt->close();

        echo json_encode([
            "status" => "success",
            "message" => "Wallet adjusted successfully!",
            "wallet_balance" => $balanceAfter
        ]);
        exit;

    default:
        echo json_encode(["status" => "error", "message" => "Unknown admin action."]);
        exit;
}

function formatAgentRow($row) {
    $baseUrl = 'https://agnicarrental.com/2025/';
    return [
        "id" => (int)$row['id'],
        "phone_number" => $row['phone_number'],
        "full_name" => $row['full_name'],
        "agency_name" => $row['agency_name'],
        "address" => $row['address'],
        "gst_number" => $row['gst_number'],
        "pan_doc" => $row['pan_doc'] ? (strpos($row['pan_doc'], 'http') === 0 ? $row['pan_doc'] : $baseUrl . $row['pan_doc']) : null,
        "profile_photo" => $row['profile_photo'] ? (strpos($row['profile_photo'], 'http') === 0 ? $row['profile_photo'] : $baseUrl . $row['profile_photo']) : null,
        "status" => $row['status'],
        "rejection_reason" => $row['rejection_reason'],
        "wallet_balance" => (float)$row['wallet_balance'],
        "created_at" => $row['created_at'],
        "updated_at" => $row['updated_at']
    ];
}
?>
