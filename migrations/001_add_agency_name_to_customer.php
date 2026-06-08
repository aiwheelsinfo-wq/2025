<?php

/**
 * Migration: 001_add_agency_name_to_customer.php
 * Demonstrates: Adding a column safely (idempotent).
 */
return function (mysqli $conn) {
    // 1. Check if table 'customer' exists. If not, create it for demonstration purposes.
    $tableCheck = mysqli_query($conn, "SHOW TABLES LIKE 'customer'");
    if (mysqli_num_rows($tableCheck) === 0) {
        $createTableSql = "CREATE TABLE `customer` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(255) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        if (!mysqli_query($conn, $createTableSql)) {
            throw new Exception("Failed to create customer table: " . mysqli_error($conn));
        }
    }

    // 2. Check if column 'agency_name' already exists
    $columnCheck = mysqli_query($conn, "SHOW COLUMNS FROM `customer` LIKE 'agency_name'");
    if (mysqli_num_rows($columnCheck) === 0) {
        // Column does not exist, safe to add
        $alterSql = "ALTER TABLE `customer` ADD COLUMN `agency_name` VARCHAR(255) NULL";
        if (!mysqli_query($conn, $alterSql)) {
            throw new Exception("Failed to add column agency_name to customer: " . mysqli_error($conn));
        }
    }
};
