<?php
header("Content-Type: application/json");

$allowed_dirs = [
    'driver' => '../driver2025',
    '2025' => '.'
];

$dirKey = isset($_GET['dir']) ? $_GET['dir'] : 'driver';
$dir = isset($allowed_dirs[$dirKey]) ? $allowed_dirs[$dirKey] : $allowed_dirs['driver'];

$action = isset($_GET['action']) ? $_GET['action'] : 'list';

if ($action === 'list') {
    $files = scandir($dir);
    echo json_encode(["status" => "success", "dir" => $dirKey, "files" => $files]);
} elseif ($action === 'read') {
    $file = isset($_GET['file']) ? $_GET['file'] : '';
    $filePath = $dir . '/' . basename($file);
    if (file_exists($filePath)) {
        echo json_encode([
            "status" => "success",
            "dir" => $dirKey,
            "file" => $file,
            "content" => file_get_contents($filePath)
        ]);
    } else {
        echo json_encode(["status" => "error", "message" => "File not found: $file in dir $dirKey (path: $filePath)"]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Invalid action"]);
}
?>
