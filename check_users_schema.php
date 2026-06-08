<?php
header("Content-Type: text/plain");
include 'db_connect.php';

echo "=== USERS TABLE SCHEMA ===\n\n";

$result = mysqli_query($conn, "SHOW COLUMNS FROM users");
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        echo "Field: {$row['Field']} | Type: {$row['Type']} | Null: {$row['Null']} | Default: '{$row['Default']}'\n";
    }
} else {
    echo "Error: " . mysqli_error($conn);
}

echo "\n=== SAMPLE USERS RECORD ===\n\n";
$sample = mysqli_query($conn, "SELECT * FROM users LIMIT 1");
if ($sample && mysqli_num_rows($sample) > 0) {
    $row = mysqli_fetch_assoc($sample);
    print_r($row);
} else {
    echo "No records found.";
}

$conn->close();
?>
