<?php

/**
 * Migration: 004_modify_agency_name_in_customer.php
 * Demonstrates: Modifying a column safely (idempotent).
 */
return function (mysqli $conn) {
    // 1. Ensure table 'customer' exists
    $tableCheck = mysqli_query($conn, "SHOW TABLES LIKE 'customer'");
    if (mysqli_num_rows($tableCheck) === 0) {
        throw new Exception("Prerequisite table 'customer' does not exist.");
    }

    // 2. Check if column 'agency_name' exists in 'customer'
    $columnCheck = mysqli_query($conn, "SHOW COLUMNS FROM `customer` LIKE 'agency_name'");
    if (mysqli_num_rows($columnCheck) > 0) {
        // Column exists, safe to modify
        $sql = "ALTER TABLE `customer` MODIFY COLUMN `agency_name` VARCHAR(500) NULL";
        if (!mysqli_query($conn, $sql)) {
            throw new Exception("Failed to modify column agency_name in customer: " . mysqli_error($conn));
        }
    } else {
        // Fallback: If column does not exist, add it directly with the new type
        $sql = "ALTER TABLE `customer` ADD COLUMN `agency_name` VARCHAR(500) NULL";
        if (!mysqli_query($conn, $sql)) {
            throw new Exception("Failed to add column agency_name to customer: " . mysqli_error($conn));
        }
    }
};
