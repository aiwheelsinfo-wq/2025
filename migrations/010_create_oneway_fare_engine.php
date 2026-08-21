<?php

/**
 * Migration: 010_create_oneway_fare_engine.php
 * Safe creation of isolated One-Way Fare Management tables:
 * - one_way_global_settings
 * - one_way_vehicle_rules
 * - one_way_fare_audit_log
 */
return function (mysqli $conn) {
    // 1. Ensure common car categories exist in car_categories
    $categories = ['Sedan', 'SUV', 'Ertiga', 'Innova', 'Crysta'];
    foreach ($categories as $cat) {
        $catCheck = mysqli_query($conn, "SELECT id FROM `car_categories` WHERE `car_type` = '" . mysqli_real_escape_string($conn, $cat) . "'");
        if ($catCheck && mysqli_num_rows($catCheck) === 0) {
            mysqli_query($conn, "INSERT INTO `car_categories` (`car_type`, `status`) VALUES ('" . mysqli_real_escape_string($conn, $cat) . "', 'active')");
        }
    }

    // 2. Create one_way_global_settings table
    $tableCheck1 = mysqli_query($conn, "SHOW TABLES LIKE 'one_way_global_settings'");
    if (mysqli_num_rows($tableCheck1) === 0) {
        $sql1 = "CREATE TABLE `one_way_global_settings` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `master_engine_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = New Engine, 0 = Legacy Fallback',
            `driver_allowance_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Master ON/OFF for Driver Allowance',
            `discount_active` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Master ON/OFF for One-Way Discounts',
            `discount_type` ENUM('percentage', 'fixed') NOT NULL DEFAULT 'percentage',
            `discount_value` DECIMAL(10,2) NOT NULL DEFAULT 10.00,
            `gst_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Master ON/OFF for Tax/GST',
            `gst_mode` ENUM('flat', 'split') NOT NULL DEFAULT 'flat' COMMENT 'flat = single gst_percent, split = CGST/SGST vs IGST',
            `gst_percent` DECIMAL(5,2) NOT NULL DEFAULT 5.00,
            `cgst_percent` DECIMAL(5,2) NOT NULL DEFAULT 2.50,
            `sgst_percent` DECIMAL(5,2) NOT NULL DEFAULT 2.50,
            `igst_percent` DECIMAL(5,2) NOT NULL DEFAULT 5.00,
            `parking_active` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Master ON/OFF for Parking Surcharge',
            `default_parking_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `toll_auto_estimate` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Auto calculate estimated toll',
            `toll_per_km_rate` DECIMAL(6,2) NOT NULL DEFAULT 2.25 COMMENT 'Toll multiplier per KM',
            `row_version` INT NOT NULL DEFAULT 1 COMMENT 'Optimistic lock counter',
            `updated_by` VARCHAR(100) DEFAULT 'admin',
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        if (!mysqli_query($conn, $sql1)) {
            throw new Exception("Failed to create table one_way_global_settings: " . mysqli_error($conn));
        }

        // Seed default row
        $seedSql1 = "INSERT INTO `one_way_global_settings` (
            `id`, `master_engine_active`, `driver_allowance_active`, `discount_active`,
            `discount_type`, `discount_value`, `gst_active`, `gst_mode`, `gst_percent`,
            `cgst_percent`, `sgst_percent`, `igst_percent`,
            `parking_active`, `default_parking_amount`, `toll_auto_estimate`, `toll_per_km_rate`, `row_version`
        ) VALUES (
            1, 1, 1, 0, 'percentage', 10.00, 1, 'split', 5.00, 2.50, 2.50, 5.00, 0, 0.00, 1, 2.25, 1
        )";
        if (!mysqli_query($conn, $seedSql1)) {
            throw new Exception("Failed to seed one_way_global_settings: " . mysqli_error($conn));
        }
    }

    // 3. Create one_way_vehicle_rules table
    $tableCheck2 = mysqli_query($conn, "SHOW TABLES LIKE 'one_way_vehicle_rules'");
    if (mysqli_num_rows($tableCheck2) === 0) {
        $sql2 = "CREATE TABLE `one_way_vehicle_rules` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `car_type_id` INT NOT NULL,
            `car_type_label` VARCHAR(100) NOT NULL,
            `km_rate` DECIMAL(10,2) NOT NULL DEFAULT 13.00,
            `min_distance_km` DECIMAL(10,2) NOT NULL DEFAULT 100.00,
            `driver_allowance_short` DECIMAL(10,2) NOT NULL DEFAULT 300.00 COMMENT 'For distance < threshold',
            `driver_allowance_long` DECIMAL(10,2) NOT NULL DEFAULT 400.00 COMMENT 'For distance >= threshold',
            `distance_threshold_km` DECIMAL(10,2) NOT NULL DEFAULT 200.00,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `display_order` INT NOT NULL DEFAULT 1,
            `row_version` INT NOT NULL DEFAULT 1,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_car_type_id` (`car_type_id`),
            CONSTRAINT `fk_oneway_car_type` FOREIGN KEY (`car_type_id`) REFERENCES `car_categories`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        if (!mysqli_query($conn, $sql2)) {
            throw new Exception("Failed to create table one_way_vehicle_rules: " . mysqli_error($conn));
        }

        // Seed initial rates linked to car_categories
        $defaultRules = [
            'Sedan'  => ['rate' => 13.00, 'short' => 300.00, 'long' => 400.00, 'order' => 1],
            'SUV'    => ['rate' => 17.00, 'short' => 300.00, 'long' => 400.00, 'order' => 2],
            'Ertiga' => ['rate' => 17.00, 'short' => 300.00, 'long' => 400.00, 'order' => 3],
            'Innova' => ['rate' => 20.00, 'short' => 300.00, 'long' => 400.00, 'order' => 4],
            'Crysta' => ['rate' => 23.00, 'short' => 300.00, 'long' => 400.00, 'order' => 5],
        ];

        foreach ($defaultRules as $cName => $r) {
            $catQuery = mysqli_query($conn, "SELECT id FROM `car_categories` WHERE `car_type` = '$cName' LIMIT 1");
            if ($catQuery && $catRow = mysqli_fetch_assoc($catQuery)) {
                $cId = (int)$catRow['id'];
                $seedSql = "INSERT INTO `one_way_vehicle_rules` (
                    `car_type_id`, `car_type_label`, `km_rate`, `min_distance_km`,
                    `driver_allowance_short`, `driver_allowance_long`, `distance_threshold_km`, `is_active`, `display_order`, `row_version`
                ) VALUES (
                    $cId, '$cName', {$r['rate']}, 100.00, {$r['short']}, {$r['long']}, 200.00, 1, {$r['order']}, 1
                )";
                mysqli_query($conn, $seedSql);
            }
        }
    }

    // 4. Create one_way_fare_audit_log table
    $tableCheck3 = mysqli_query($conn, "SHOW TABLES LIKE 'one_way_fare_audit_log'");
    if (mysqli_num_rows($tableCheck3) === 0) {
        $sql3 = "CREATE TABLE `one_way_fare_audit_log` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `admin_id` VARCHAR(100) NOT NULL,
            `action_type` ENUM('global_settings_update', 'vehicle_rule_update', 'vehicle_rule_create', 'vehicle_rule_toggle', 'vehicle_rule_delete') NOT NULL,
            `target_id` INT NOT NULL,
            `previous_values` JSON NOT NULL,
            `new_values` JSON NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        if (!mysqli_query($conn, $sql3)) {
            throw new Exception("Failed to create table one_way_fare_audit_log: " . mysqli_error($conn));
        }
    }
};
