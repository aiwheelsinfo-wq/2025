<?php
header("Content-Type: application/json");
include 'db_connect.php';

$res = mysqli_query($conn, "SHOW DATABASES");
$databases = [];
if ($res) {
    while ($row = mysqli_fetch_row($res)) {
        $databases[] = $row[0];
    }
} else {
    echo json_encode(["success" => false, "error" => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

echo json_encode(["success" => true, "databases" => $databases]);
mysqli_close($conn);
?>
