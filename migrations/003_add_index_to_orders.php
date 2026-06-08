<?php

/**
 * Migration: 003_add_index_to_orders.php
 * Demonstrates: Creating an index safely (idempotent).
 */
return function (mysqli $conn) {
    // 1. Ensure the parent table 'orders' exists
    $tableCheck = mysqli_query($conn, "SHOW TABLES LIKE 'orders'");
    if (mysqli_num_rows($tableCheck) === 0) {
        throw new Exception("Prerequisite table 'orders' does not exist.");
    }

    // 2. Check if index 'idx_customer_id' already exists on 'orders'
    $indexCheck = mysqli_query($conn, "SHOW INDEX FROM `orders` WHERE Key_name = 'idx_customer_id'");
    if (mysqli_num_rows($indexCheck) === 0) {
        // Index does not exist, safe to create
        $sql = "ALTER TABLE `orders` ADD INDEX `idx_customer_id` (`customer_id`)";
        if (!mysqli_query($conn, $sql)) {
            throw new Exception("Failed to add index idx_customer_id to orders: " . mysqli_error($conn));
        }
    }
};
