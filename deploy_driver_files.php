<?php
header("Content-Type: text/plain");

$src_dir = './driver2025_src';
$dest_dir = '../driver2025';

if (!is_dir($src_dir)) {
    die("Source directory does not exist: $src_dir\n");
}

if (!is_dir($dest_dir)) {
    die("Destination directory does not exist: $dest_dir\n");
}

$files = scandir($src_dir);
$copied_count = 0;
foreach ($files as $file) {
    if ($file === '.' || $file === '..') {
        continue;
    }
    
    $src_file = $src_dir . '/' . $file;
    $dest_file = $dest_dir . '/' . $file;
    
    if (is_file($src_file)) {
        if (copy($src_file, $dest_file)) {
            echo "Copied $file successfully to $dest_dir\n";
            $copied_count++;
        } else {
            echo "Failed to copy $file to $dest_dir\n";
        }
    }
}
echo "Total files copied: $copied_count\n";
?>
