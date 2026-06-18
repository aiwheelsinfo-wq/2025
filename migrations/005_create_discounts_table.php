<?php

/**
 * Migration: 005_create_discounts_table.php
 * Safe creation of the discounts table.
 */
return function (mysqli $conn) {
    // 1. Check if table 'discounts' already exists
    $tableCheck = mysqli_query($conn, "SHOW TABLES LIKE 'discounts'");
    if (mysqli_num_rows($tableCheck) === 0) {
        $sql = "CREATE TABLE `discounts` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(255) NOT NULL,
            `discount_type` ENUM('percentage', 'fixed') NOT NULL,
            `discount_value` DECIMAL(10, 2) NOT NULL,
            `description` TEXT NULL,
            `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            `start_date` DATE NOT NULL,
            `end_date` DATE NOT NULL,
            `apply_scope` VARCHAR(50) NOT NULL DEFAULT 'One-way',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        if (!mysqli_query($conn, $sql)) {
            throw new Exception("Failed to create table discounts: " . mysqli_error($conn));
        }

        // 2. Insert default 10% loyalty discount for One-way trip
        $insertSql = "INSERT INTO `discounts` (`name`, `discount_type`, `discount_value`, `description`, `status`, `start_date`, `end_date`, `apply_scope`) 
                      VALUES ('Loyalty Discount', 'percentage', 10.00, 'Default 10% loyalty discount for One-way trips', 'active', '2025-01-01', '2030-12-31', 'One-way')";
        if (!mysqli_query($conn, $insertSql)) {
            throw new Exception("Failed to insert default loyalty discount: " . mysqli_error($conn));
        }
    }
};
