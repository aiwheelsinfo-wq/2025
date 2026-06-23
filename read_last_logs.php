<?php
header("Content-Type: text/plain");

$log_file = './error_log';
if (!file_exists($log_file)) {
    die("Error log file not found: $log_file\n");
}

$lines = 100;
$data = file($log_file);
$line_count = count($data);
$start = max(0, $line_count - $lines);

for ($i = $start; $i < $line_count; $i++) {
    echo $data[$i];
}
?>
