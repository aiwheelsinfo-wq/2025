<?php

/**
 * Migration: 006_create_payment_settlement_tables.php
 * Adds payment collection and settlement tracking schema.
 */
return function (mysqli $conn) {
    // 1. Add remaining_balance column to bookings if not exists
    $check = mysqli_query($conn, "SHOW COLUMNS FROM `bookings` LIKE 'remaining_balance'");
    if (mysqli_num_rows($check) === 0) {
        $sql = "ALTER TABLE `bookings` ADD COLUMN `remaining_balance` DECIMAL(10,2) DEFAULT 0.00 AFTER `paid_amount`";
        if (!mysqli_query($conn, $sql)) {
            throw new Exception("Failed to add remaining_balance: " . mysqli_error($conn));
        }
    }

    // 2. Add collection_status column to bookings if not exists
    $check = mysqli_query($conn, "SHOW COLUMNS FROM `bookings` LIKE 'collection_status'");
    if (mysqli_num_rows($check) === 0) {
        $sql = "ALTER TABLE `bookings` ADD COLUMN `collection_status` VARCHAR(50) DEFAULT 'Pending Collection' AFTER `remaining_balance`";
        if (!mysqli_query($conn, $sql)) {
            throw new Exception("Failed to add collection_status: " . mysqli_error($conn));
        }
    }

    // 3. Add collection_date column to bookings if not exists
    $check = mysqli_query($conn, "SHOW COLUMNS FROM `bookings` LIKE 'collection_date'");
    if (mysqli_num_rows($check) === 0) {
        $sql = "ALTER TABLE `bookings` ADD COLUMN `collection_date` DATE DEFAULT NULL AFTER `collection_status`";
        if (!mysqli_query($conn, $sql)) {
            throw new Exception("Failed to add collection_date: " . mysqli_error($conn));
        }
    }

    // 4. Add collection_approved_at column to bookings if not exists
    $check = mysqli_query($conn, "SHOW COLUMNS FROM `bookings` LIKE 'collection_approved_at'");
    if (mysqli_num_rows($check) === 0) {
        $sql = "ALTER TABLE `bookings` ADD COLUMN `collection_approved_at` TIMESTAMP DEFAULT NULL AFTER `collection_date`";
        if (!mysqli_query($conn, $sql)) {
            throw new Exception("Failed to add collection_approved_at: " . mysqli_error($conn));
        }
    }

    // 5. Create vendor_settlements table
    $sql = "CREATE TABLE IF NOT EXISTS `vendor_settlements` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `booking_id` INT NOT NULL UNIQUE,
        `vendor_id` VARCHAR(50) NOT NULL,
        `earnings` DECIMAL(10,2) NOT NULL,
        `status` VARCHAR(50) DEFAULT 'Pending',
        `remarks` TEXT DEFAULT NULL,
        `settled_amount` DECIMAL(10,2) DEFAULT 0.00,
        `settled_date` DATE DEFAULT NULL,
        `bank_reference` VARCHAR(100) DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    if (!mysqli_query($conn, $sql)) {
        throw new Exception("Failed to create vendor_settlements: " . mysqli_error($conn));
    }

    // 6. Create settlement_history table
    $sql = "CREATE TABLE IF NOT EXISTS `settlement_history` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `settlement_id` INT NOT NULL,
        `booking_id` INT NOT NULL,
        `amount` DECIMAL(10,2) NOT NULL,
        `settled_date` DATE NOT NULL,
        `bank_reference` VARCHAR(100) NOT NULL,
        `admin_notes` TEXT DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    if (!mysqli_query($conn, $sql)) {
        throw new Exception("Failed to create settlement_history: " . mysqli_error($conn));
    }
};
