<?php
header("Content-Type: text/plain");
include 'db_connect.php';

$result = $conn->query("DESCRIBE drivers");
while ($row = $result->fetch_assoc()) {
    echo $row['Field'] . " | " . $row['Type'] . " | " . $row['Null'] . " | " . $row['Key'] . "\n";
}
$conn->close();
?>
