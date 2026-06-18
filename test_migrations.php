<?php

// Set content type to text/plain for CLI execution readability
if (php_sapi_name() !== 'cli') {
    header("Content-Type: text/plain");
}

echo "=== DATABASE MIGRATION VERIFICATION SCRIPT ===\n\n";

// Require db_connect.php which will automatically trigger the MigrationRunner
require_once __DIR__ . '/db_connect.php';

// Verify the connection is active
if (!isset($conn) || !$conn) {
    echo "Error: Database connection is not available.\n";
    exit(1);
}

echo "1. Checking if 'migrations' table exists...\n";
$migrationsTableCheck = mysqli_query($conn, "SHOW TABLES LIKE 'migrations'");
if (mysqli_num_rows($migrationsTableCheck) > 0) {
    echo "SUCCESS: 'migrations' table exists.\n";
} else {
    echo "FAILURE: 'migrations' table does not exist.\n";
}

echo "\n2. Fetching executed migrations from database:\n";
$migrationsResult = mysqli_query($conn, "SELECT * FROM migrations ORDER BY id ASC");
if ($migrationsResult) {
    while ($row = mysqli_fetch_assoc($migrationsResult)) {
        echo " - ID: {$row['id']} | Name: {$row['migration_name']} | Executed At: {$row['executed_at']}\n";
    }
    mysqli_free_result($migrationsResult);
} else {
    echo "Error fetching migrations: " . mysqli_error($conn) . "\n";
}

echo "\n3. Checking if target tables exist:\n";
$tables = ['customer', 'orders', 'discounts'];
foreach ($tables as $table) {
    $tableCheck = mysqli_query($conn, "SHOW TABLES LIKE '$table'");
    if (mysqli_num_rows($tableCheck) > 0) {
        echo " - Table '$table': EXISTS\n";
    } else {
        echo " - Table '$table': MISSING\n";
    }
}

echo "\n4. Checking if columns exist on 'customer' table:\n";
$customerColumnsCheck = mysqli_query($conn, "SHOW COLUMNS FROM `customer` LIKE 'agency_name'");
if (mysqli_num_rows($customerColumnsCheck) > 0) {
    $columnDetails = mysqli_fetch_assoc($customerColumnsCheck);
    echo " - Column 'agency_name': EXISTS (Type: {$columnDetails['Type']}, Null: {$columnDetails['Null']})\n";
} else {
    echo " - Column 'agency_name': MISSING\n";
}

echo "\n5. Checking if index exists on 'orders' table:\n";
$indexCheck = mysqli_query($conn, "SHOW INDEX FROM `orders` WHERE Key_name = 'idx_customer_id'");
if (mysqli_num_rows($indexCheck) > 0) {
    $indexDetails = mysqli_fetch_assoc($indexCheck);
    echo " - Index 'idx_customer_id': EXISTS (Column: {$indexDetails['Column_name']})\n";
} else {
    echo " - Index 'idx_customer_id': MISSING\n";
}

echo "\n5.1 Checking 'discounts' table content:\n";
$discountsCheck = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM `discounts`");
if ($discountsCheck) {
    $row = mysqli_fetch_assoc($discountsCheck);
    echo " - Row count in 'discounts': {$row['cnt']}\n";
    
    $defaultDiscountCheck = mysqli_query($conn, "SELECT * FROM `discounts` WHERE name = 'Loyalty Discount' LIMIT 1");
    if ($defaultDiscountCheck && mysqli_num_rows($defaultDiscountCheck) > 0) {
        $dd = mysqli_fetch_assoc($defaultDiscountCheck);
        echo " - Default Loyalty Discount: EXISTS (Value: {$dd['discount_value']}, Type: {$dd['discount_type']}, Scope: {$dd['apply_scope']}, Status: {$dd['status']})\n";
    } else {
        echo " - Default Loyalty Discount: MISSING\n";
    }
} else {
    echo " - Could not check 'discounts' table: " . mysqli_error($conn) . "\n";
}

echo "\n6. Displaying last 20 lines of migrations.log:\n";
$logPath = __DIR__ . '/migrations.log';
if (file_exists($logPath)) {
    $logLines = file($logPath);
    $lastLines = array_slice($logLines, -20);
    echo implode("", $lastLines);
} else {
    echo "migrations.log file does not exist.\n";
}

echo "\n=== VERIFICATION COMPLETE ===\n";
