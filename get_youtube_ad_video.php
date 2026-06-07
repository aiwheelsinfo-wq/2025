<?php
header('Content-Type: application/json');
include 'db_connect.php';

$sql = "SELECT youtube_url FROM videos ORDER BY id DESC LIMIT 1";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo json_encode(['youtube_url' => $row['youtube_url']]);
} else {
    echo json_encode(['error' => 'No video found']);
}

$conn->close();
?>
