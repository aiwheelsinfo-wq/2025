<?php
header('Content-Type: text/plain');

echo "=== MIGRATIONS LOG ===\n";
if (file_exists('migrations.log')) {
    $lines = file('migrations.log');
    $last_lines = array_slice($lines, -50);
    echo implode("", $last_lines);
} else {
    echo "migrations.log not found\n";
}

echo "\n=== PHP ERROR LOG ===\n";
if (file_exists('error_log')) {
    $lines = file('error_log');
    $last_lines = array_slice($lines, -50);
    echo implode("", $last_lines);
} else {
    echo "error_log not found\n";
}
?>
