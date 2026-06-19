<?php
header('Content-Type: text/plain');

echo "=== MIGRATIONS LOG ===\n";
if (file_exists('migrations.log')) {
    echo file_get_contents('migrations.log');
} else {
    echo "migrations.log not found\n";
}

echo "\n=== PHP ERROR LOG (last 10KB) ===\n";
if (file_exists('error_log')) {
    $file = fopen('error_log', 'r');
    if ($file) {
        fseek($file, -10000, SEEK_END);
        echo fread($file, 10000);
        fclose($file);
    } else {
        echo "Could not open error_log\n";
    }
} else {
    echo "error_log not found\n";
}
?>
