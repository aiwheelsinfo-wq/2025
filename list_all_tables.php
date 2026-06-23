<?php
header("Content-Type: application/json");
include 'db_connect.php';

$result = mysqli_query($conn, "SHOW TABLES");
$tables = [];

if ($result) {
    while ($row = mysqli_fetch_row($result)) {
        $tableName = $row[0];
        $cntResult = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM `$tableName`");
        $count = 0;
        if ($cntResult) {
            $cntRow = mysqli_fetch_assoc($cntResult);
            $count = (int)$cntRow['cnt'];
        }
        $tables[$tableName] = $count;
    }
} else {
    echo json_encode(["success" => false, "error" => mysqli_error($conn)]);
    exit;
}

echo json_encode(["success" => true, "tables" => $tables]);
mysqli_close($conn);
?>
