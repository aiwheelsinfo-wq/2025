<?php
header("Content-Type: application/json");

$dir = '../driver2025';
if (!is_dir($dir)) {
    echo json_encode(["status" => "error", "message" => "Directory $dir not found"]);
    exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : 'list';

if ($action === 'list') {
    $files = scandir($dir);
    echo json_encode(["status" => "success", "files" => $files]);
} elseif ($action === 'read') {
    $file = isset($_GET['file']) ? $_GET['file'] : '';
    $filePath = $dir . '/' . basename($file);
    if (file_exists($filePath)) {
        echo json_encode([
            "status" => "success",
            "file" => $file,
            "content" => file_get_contents($filePath)
        ]);
    } else {
        echo json_encode(["status" => "error", "message" => "File not found: $file"]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Invalid action"]);
}
?>
