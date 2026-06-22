<?php
include 'db_connect.php';
$result = $conn->query("SELECT * FROM cancellation_policy");
$policies = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $policies[] = $row;
    }
} else {
    echo json_encode(["error" => $conn->error]);
    exit;
}
echo json_encode($policies);
$conn->close();
?>
