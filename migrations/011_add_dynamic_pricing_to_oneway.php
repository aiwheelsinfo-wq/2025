<?php

/**
 * Migration: 011_add_dynamic_pricing_to_oneway.php
 * Safe non-destructive addition of Dynamic Pricing parameters for One-Way trips:
 * - one_way_global_settings: dynamic_pricing_active, oneway_pricing_sensitivity, outlier_threshold_pct, historical_lookback_days
 * - one_way_vehicle_rules: min_rate, max_rate, min_rate_multiplier, max_rate_multiplier
 */
return function (mysqli $conn) {
    // 1. Update one_way_global_settings table
    $tableCheck1 = mysqli_query($conn, "SHOW TABLES LIKE 'one_way_global_settings'");
    if ($tableCheck1 && mysqli_num_rows($tableCheck1) > 0) {
        $cols = [
            'dynamic_pricing_active' => "ALTER TABLE `one_way_global_settings` ADD COLUMN `dynamic_pricing_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Master ON/OFF for One-Way Dynamic Pricing' AFTER `toll_per_km_rate`",
            'oneway_pricing_sensitivity' => "ALTER TABLE `one_way_global_settings` ADD COLUMN `oneway_pricing_sensitivity` DECIMAL(5,2) NOT NULL DEFAULT 50.00 COMMENT 'Pricing sensitivity percentage (0-100%)' AFTER `dynamic_pricing_active`",
            'outlier_threshold_pct' => "ALTER TABLE `one_way_global_settings` ADD COLUMN `outlier_threshold_pct` DECIMAL(5,2) NOT NULL DEFAULT 50.00 COMMENT 'Outlier detection threshold percentage' AFTER `oneway_pricing_sensitivity`",
            'historical_lookback_days' => "ALTER TABLE `one_way_global_settings` ADD COLUMN `historical_lookback_days` INT NOT NULL DEFAULT 14 COMMENT 'Days to look back for historical baseline demand' AFTER `outlier_threshold_pct`"
        ];

        foreach ($cols as $colName => $alterSql) {
            $colCheck = mysqli_query($conn, "SHOW COLUMNS FROM `one_way_global_settings` LIKE '$colName'");
            if ($colCheck && mysqli_num_rows($colCheck) === 0) {
                if (!mysqli_query($conn, $alterSql)) {
                    throw new Exception("Failed to add column $colName to one_way_global_settings: " . mysqli_error($conn));
                }
            }
        }
    }

    // 2. Update one_way_vehicle_rules table
    $tableCheck2 = mysqli_query($conn, "SHOW TABLES LIKE 'one_way_vehicle_rules'");
    if ($tableCheck2 && mysqli_num_rows($tableCheck2) > 0) {
        $vehCols = [
            'min_rate' => "ALTER TABLE `one_way_vehicle_rules` ADD COLUMN `min_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Hard minimum rate floor (0 = auto-calculated)' AFTER `km_rate`",
            'max_rate' => "ALTER TABLE `one_way_vehicle_rules` ADD COLUMN `max_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Hard maximum rate ceiling (0 = auto-calculated)' AFTER `min_rate`",
            'min_rate_multiplier' => "ALTER TABLE `one_way_vehicle_rules` ADD COLUMN `min_rate_multiplier` DECIMAL(4,2) NOT NULL DEFAULT 0.80 COMMENT 'Default floor multiplier (0.80 = 80%)' AFTER `max_rate`",
            'max_rate_multiplier' => "ALTER TABLE `one_way_vehicle_rules` ADD COLUMN `max_rate_multiplier` DECIMAL(4,2) NOT NULL DEFAULT 1.40 COMMENT 'Default ceiling multiplier (1.40 = 140%)' AFTER `min_rate_multiplier`"
        ];

        foreach ($vehCols as $colName => $alterSql) {
            $colCheck = mysqli_query($conn, "SHOW COLUMNS FROM `one_way_vehicle_rules` LIKE '$colName'");
            if ($colCheck && mysqli_num_rows($colCheck) === 0) {
                if (!mysqli_query($conn, $alterSql)) {
                    throw new Exception("Failed to add column $colName to one_way_vehicle_rules: " . mysqli_error($conn));
                }
            }
        }
    }
};
