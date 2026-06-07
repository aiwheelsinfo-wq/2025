<?php
header('Content-Type: application/json');
require_once "db_connect.php";

$appName = isset($_GET['appName']) ? $_GET['appName'] : '';

if(!$appName){
    echo json_encode([
        "status" => "error",
        "message" => "App name required"
    ]);
    exit;
}

$query = "SELECT * FROM app_version_control WHERE appName = '$appName' ORDER BY id DESC LIMIT 1";
$result = $conn->query($query);

if($result->num_rows > 0){
    $row = $result->fetch_assoc();
    echo json_encode([
        "status" => "success",
        "latest_version" => $row['latest_version'],
        "min_supported_version" => $row['min_supported_version'],
        "update_url" => $row['update_url'],
        "force_update" => $row['force_update'],
        "release_notes" => $row['release_notes']
    ]);
}else{
    echo json_encode([
        "status" => "error",
        "message" => "No version found for $appName"
    ]);
}
?>
