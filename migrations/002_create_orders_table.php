<?php

/**
 * Migration: 002_create_orders_table.php
 * Demonstrates: Creating a table safely (idempotent).
 */
return function (mysqli $conn) {
    // 1. Check if table 'orders' already exists
    $tableCheck = mysqli_query($conn, "SHOW TABLES LIKE 'orders'");
    if (mysqli_num_rows($tableCheck) === 0) {
        // Table does not exist, safe to create
        $sql = "CREATE TABLE `orders` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `customer_id` INT NOT NULL,
            `amount` DECIMAL(10, 2) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        if (!mysqli_query($conn, $sql)) {
            throw new Exception("Failed to create table orders: " . mysqli_error($conn));
        }
    }
};
