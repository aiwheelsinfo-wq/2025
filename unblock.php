<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

include 'db_connect.php';

// If a phone number is provided, unblock it
if (isset($_GET['phone'])) {
    $phone = $conn->real_escape_string($_GET['phone']);
    $sql = "UPDATE users SET STATUS = 'active' WHERE phone_number = '$phone'";
    if ($conn->query($sql) === TRUE) {
        echo json_encode(["success" => true, "message" => "User $phone unblocked successfully"]);
    } else {
        echo json_encode(["success" => false, "message" => "Error updating record: " . $conn->error]);
    }
} else {
    // List blocked users
    $result = $conn->query("SELECT phone_number, name, STATUS FROM users WHERE STATUS = 'blocked'");
    $blocked = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $blocked[] = $row;
        }
    }
    
    // Also, let's provide an option to unblock all
    if (isset($_GET['all'])) {
        $conn->query("UPDATE users SET STATUS = 'active' WHERE STATUS = 'blocked'");
        echo json_encode(["success" => true, "message" => "All blocked users unblocked", "previous_blocked" => $blocked]);
    } else {
        echo json_encode(["status" => "info", "blocked_users" => $blocked, "instruction" => "Use ?phone=NUMBER to unblock a specific user, or ?all=1 to unblock everyone."]);
    }
}
$conn->close();
?>
