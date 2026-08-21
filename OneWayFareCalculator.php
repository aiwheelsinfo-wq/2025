<?php

require_once __DIR__ . '/FareCache.php';

/**
 * Isolated One-Way Dynamic Fare Calculation Engine (v2)
 * All monetary calculations performed in integer paise to eliminate floating-point drift.
 */
class OneWayFareCalculator {

    /**
     * Main calculation endpoint
     *
     * @param mysqli $conn
     * @param int|string $carType Either category ID (int) or category name (e.g. 'Sedan', 'SUV')
     * @param float $distanceKm
     * @param string $pickupAddress
     * @param string $dropAddress
     * @return array
     */
    public static function calculate(
        mysqli $conn,
        $carType,
        float $distanceKm,
        string $pickupAddress = '',
        string $dropAddress = '',
        array $settingsOverride = []
    ): array {
        // 1. Fetch Global Settings (Cached)
        $global = self::getGlobalSettings($conn);
        if (!empty($settingsOverride)) {
            $global = array_merge($global, $settingsOverride);
        }

        // 2. Fetch Vehicle Rule (Cached)
        $vehRule = self::getVehicleRule($conn, $carType);

        // 3. Distance Normalization
        $minDist = (float)($vehRule['min_distance_km'] ?? 100.0);
        $chargeableKm = max($distanceKm, $minDist);
        $kmRate = (float)($vehRule['km_rate'] ?? 13.0);

        // Base KM Charge (in integer paise)
        $baseKmChargeP = self::toPaise($chargeableKm * $kmRate);

        // 4. Driver Allowance (with switch check)
        $allowanceP = 0;
        $allowanceShort = (float)($vehRule['driver_allowance_short'] ?? 300.0);
        $allowanceLong = (float)($vehRule['driver_allowance_long'] ?? 400.0);
        $distThreshold = (float)($vehRule['distance_threshold_km'] ?? 200.0);

        if (!empty($global['driver_allowance_active'])) {
            $allowanceP = ($chargeableKm < $distThreshold)
                ? self::toPaise($allowanceShort)
                : self::toPaise($allowanceLong);
        }

        // 5. Toll Estimate (with switch check)
        $tollP = 0;
        $tollRate = (float)($global['toll_per_km_rate'] ?? 2.25);
        if (!empty($global['toll_auto_estimate'])) {
            $tollP = self::toPaise($chargeableKm * $tollRate);
        }

        // 6. Parking Surcharge (with switch check)
        $parkingP = 0;
        $defaultParking = (float)($global['default_parking_amount'] ?? 0.0);
        if (!empty($global['parking_active'])) {
            $parkingP = self::toPaise($defaultParking);
        }

        // Subtotal (Base KM + Allowance + Toll + Parking)
        $subtotalP = $baseKmChargeP + $allowanceP + $tollP + $parkingP;

        // 7. Tax / GST Calculation (Flat or Split by Route)
        $gstP = 0;
        $gstMode = $global['gst_mode'] ?? 'flat';
        $gstPercent = (float)($global['gst_percent'] ?? 5.0);
        $cgstPercent = (float)($global['cgst_percent'] ?? 2.5);
        $sgstPercent = (float)($global['sgst_percent'] ?? 2.5);
        $igstPercent = (float)($global['igst_percent'] ?? 5.0);

        $gstBreakdown = [
            'is_active' => (bool)$global['gst_active'],
            'mode'      => $gstMode,
            'rate'      => $gstPercent,
            'cgst'      => 0.0,
            'sgst'      => 0.0,
            'igst'      => 0.0,
        ];

        if (!empty($global['gst_active'])) {
            if ($gstMode === 'split') {
                $isIntraState = self::isIntraStateRoute($pickupAddress, $dropAddress);
                if ($isIntraState) {
                    $cgstP = self::pctOf($subtotalP, $cgstPercent);
                    $sgstP = self::pctOf($subtotalP, $sgstPercent);
                    $gstP = $cgstP + $sgstP;
                    $gstBreakdown['mode'] = 'intra_state';
                    $gstBreakdown['cgst'] = self::fromPaise($cgstP);
                    $gstBreakdown['sgst'] = self::fromPaise($sgstP);
                    $gstBreakdown['rate'] = $cgstPercent + $sgstPercent;
                } else {
                    $igstP = self::pctOf($subtotalP, $igstPercent);
                    $gstP = $igstP;
                    $gstBreakdown['mode'] = 'inter_state';
                    $gstBreakdown['igst'] = self::fromPaise($igstP);
                    $gstBreakdown['rate'] = $igstPercent;
                }
            } else {
                $gstP = self::pctOf($subtotalP, $gstPercent);
                $gstBreakdown['mode'] = 'flat';
                $gstBreakdown['rate'] = $gstPercent;
            }
        }

        $grossTotalP = $subtotalP + $gstP;

        // 8. Discount Calculation (with switch check)
        $discountP = 0;
        $discType = $global['discount_type'] ?? 'percentage';
        $discVal = (float)($global['discount_value'] ?? 0.0);

        if (!empty($global['discount_active']) && $discVal > 0) {
            if ($discType === 'fixed') {
                $discountP = self::toPaise($discVal);
            } else {
                $discountP = self::pctOf($grossTotalP, $discVal);
            }
            if ($discountP > $grossTotalP) {
                $discountP = $grossTotalP;
            }
        }

        // Final Fare (in integer paise -> converted to clean float)
        $finalFareP = max(0, $grossTotalP - $discountP);

        return [
            'master_engine_active'    => (bool)($global['master_engine_active'] ?? true),
            'car_type'                => $vehRule['car_type_label'] ?? (string)$carType,
            'car_type_id'             => (int)($vehRule['car_type_id'] ?? 0),
            'distance_km'             => $distanceKm,
            'chargeable_km'           => $chargeableKm,
            'km_rate'                 => $kmRate,
            'base_km_charge'          => self::fromPaise($baseKmChargeP),
            'driver_allowance'        => self::fromPaise($allowanceP),
            'driver_allowance_active' => (bool)$global['driver_allowance_active'],
            'toll_charge'             => self::fromPaise($tollP),
            'parking_charge'          => self::fromPaise($parkingP),
            'subtotal'                => self::fromPaise($subtotalP),
            'gst_amount'              => self::fromPaise($gstP),
            'gst_breakdown'           => $gstBreakdown,
            'discount_amount'         => self::fromPaise($discountP),
            'discount_active'         => (bool)$global['discount_active'],
            'final_fare'              => self::fromPaise($finalFareP),
            'final_fare_rounded'      => round(self::fromPaise($finalFareP)),
        ];
    }

    /**
     * Cached Global Settings fetch
     */
    public static function getGlobalSettings(mysqli $conn): array {
        $cacheKey = 'oneway_global_settings';
        $cached = FareCache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $res = mysqli_query($conn, "SELECT * FROM `one_way_global_settings` WHERE `id` = 1 LIMIT 1");
        if ($res && $row = mysqli_fetch_assoc($res)) {
            FareCache::set($cacheKey, $row, 300);
            return $row;
        }

        return self::defaultGlobalSettings();
    }

    /**
     * Cached Vehicle Rule fetch (supports int ID or string Category Name)
     */
    public static function getVehicleRule(mysqli $conn, $carType): array {
        $cacheKey = "oneway_veh_rule_" . (is_numeric($carType) ? "id_$carType" : "str_" . strtolower(trim((string)$carType)));
        $cached = FareCache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        if (is_numeric($carType)) {
            $stmt = $conn->prepare("SELECT * FROM `one_way_vehicle_rules` WHERE `car_type_id` = ? AND `is_active` = 1 LIMIT 1");
            $cId = (int)$carType;
            $stmt->bind_param("i", $cId);
        } else {
            $cName = trim((string)$carType);
            $stmt = $conn->prepare("SELECT * FROM `one_way_vehicle_rules` WHERE LOWER(`car_type_label`) = LOWER(?) AND `is_active` = 1 LIMIT 1");
            $stmt->bind_param("s", $cName);
        }

        if ($stmt) {
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $row = $res->fetch_assoc()) {
                $stmt->close();
                FareCache::set($cacheKey, $row, 300);
                return $row;
            }
            $stmt->close();
        }

        // Secondary fallback search in car_categories
        if (!is_numeric($carType)) {
            $catStmt = $conn->prepare("SELECT id, car_type FROM `car_categories` WHERE LOWER(`car_type`) = LOWER(?) LIMIT 1");
            if ($catStmt) {
                $cName = trim((string)$carType);
                $catStmt->bind_param("s", $cName);
                $catStmt->execute();
                $catRes = $catStmt->get_result();
                if ($catRes && $catRow = $catRes->fetch_assoc()) {
                    $catStmt->close();
                    $rule = self::defaultVehicleRule();
                    $rule['car_type_id'] = (int)$catRow['id'];
                    $rule['car_type_label'] = $catRow['car_type'];
                    FareCache::set($cacheKey, $rule, 300);
                    return $rule;
                }
                $catStmt->close();
            }
        }

        return self::defaultVehicleRule();
    }

    /**
     * Helpers for Integer-Paise Arithmetic (Prevents floating-point rounding errors)
     */
    private static function toPaise($amount): int {
        return (int)round(((float)$amount) * 100);
    }

    private static function fromPaise(int $paise): float {
        return round($paise / 100, 2);
    }

    private static function pctOf(int $basePaise, float $percent): int {
        return (int)round(($basePaise * $percent) / 100);
    }

    /**
     * Detects if route is intra-state (same state) or inter-state
     */
    public static function isIntraStateRoute(string $fromAddress, string $toAddress): bool {
        if (empty($fromAddress) || empty($toAddress)) {
            return true; // Default to intra-state if addresses unavailable
        }

        $fromState = self::extractState($fromAddress);
        $toState = self::extractState($toAddress);

        if (!empty($fromState) && !empty($toState)) {
            return strcasecmp($fromState, $toState) === 0;
        }

        return true;
    }

    private static function extractState(string $address): string {
        $states = [
            'Kerala', 'Tamil Nadu', 'Karnataka', 'Maharashtra', 'Goa',
            'Andhra Pradesh', 'Telangana', 'Gujarat', 'Rajasthan', 'Delhi',
            'Uttar Pradesh', 'Madhya Pradesh', 'Punjab', 'Haryana', 'West Bengal'
        ];

        foreach ($states as $st) {
            if (stripos($address, $st) !== false) {
                return $st;
            }
        }
        return '';
    }

    private static function defaultGlobalSettings(): array {
        return [
            'id'                     => 1,
            'master_engine_active'   => 1,
            'driver_allowance_active'=> 1,
            'discount_active'        => 0,
            'discount_type'          => 'percentage',
            'discount_value'         => 10.0,
            'gst_active'             => 1,
            'gst_mode'               => 'split',
            'gst_percent'            => 5.0,
            'cgst_percent'           => 2.5,
            'sgst_percent'           => 2.5,
            'igst_percent'           => 5.0,
            'parking_active'         => 0,
            'default_parking_amount' => 0.0,
            'toll_auto_estimate'     => 1,
            'toll_per_km_rate'       => 2.25,
            'row_version'            => 1
        ];
    }

    private static function defaultVehicleRule(): array {
        return [
            'car_type_id'            => 1,
            'car_type_label'         => 'Sedan',
            'km_rate'                => 13.0,
            'min_distance_km'        => 100.0,
            'driver_allowance_short' => 300.0,
            'driver_allowance_long'  => 400.0,
            'distance_threshold_km'  => 200.0,
            'is_active'              => 1,
            'row_version'            => 1
        ];
    }
}
