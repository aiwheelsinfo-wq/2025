<?php

require_once __DIR__ . '/FareCache.php';

/**
 * Isolated One-Way Dynamic Fare & Commission Engine (v2.2)
 * Incorporates Supply-Demand Elasticity, Outlier Protection, Boundary Protection,
 * and Automated Company Share / Driver Net Payout Split.
 * All monetary calculations performed in integer paise to eliminate floating-point drift.
 */
class OneWayFareCalculator {

    /**
     * Main calculation endpoint for One-Way trips
     *
     * @param mysqli $conn
     * @param int|string $carType Either category ID (int) or category name (e.g. 'Sedan', 'SUV')
     * @param float $distanceKm
     * @param string $pickupAddress
     * @param string $dropAddress
     * @param array $settingsOverride
     * @return array
     */
    public static function calculate(
        mysqli $conn,
        $carType,
        float $distanceKm,
        string $pickupAddress = '',
        string $dropAddress = '',
        array $settingsOverride = [],
        ?string $targetDate = null
    ): array {
        // 1. Fetch Global Settings (Cached)
        $global = self::getGlobalSettings($conn);
        if (!empty($settingsOverride)) {
            $global = array_merge($global, $settingsOverride);
        }

        // 2. Fetch Vehicle Rule (Cached)
        $vehRule = self::getVehicleRule($conn, $carType);
        if (!empty($settingsOverride['vehicle_rule_override'])) {
            $vehRule = array_merge($vehRule, $settingsOverride['vehicle_rule_override']);
        }

        // 3. Distance Normalization
        $minDist = (float)($vehRule['min_distance_km'] ?? 100.0);
        $chargeableKm = max($distanceKm, $minDist);
        $kmRate = (float)($vehRule['km_rate'] ?? 13.0);

        // Raw Base KM Charge (in integer paise)
        $rawBaseKmCharge = $chargeableKm * $kmRate;

        // 4. One-Way Dynamic Pricing Calculation (if active, date-aware)
        $dynamicMetrics = self::calculateOneWayDynamicDemand($conn, $global, $vehRule, $rawBaseKmCharge, $settingsOverride, $targetDate);
        $effectiveBaseKmCharge = $dynamicMetrics['final_one_way_rate'] > 0 ? $dynamicMetrics['final_one_way_rate'] : $rawBaseKmCharge;
        $baseKmChargeP = self::toPaise($effectiveBaseKmCharge);

        // 5. Driver Allowance (with switch check)
        $allowanceP = 0;
        $allowanceShort = (float)($vehRule['driver_allowance_short'] ?? 300.0);
        $allowanceLong = (float)($vehRule['driver_allowance_long'] ?? 400.0);
        $distThreshold = (float)($vehRule['distance_threshold_km'] ?? 200.0);

        if (!empty($global['driver_allowance_active'])) {
            $allowanceP = ($chargeableKm < $distThreshold)
                ? self::toPaise($allowanceShort)
                : self::toPaise($allowanceLong);
        }

        // 6. Toll Estimate (with switch check)
        $tollP = 0;
        $tollRate = (float)($global['toll_per_km_rate'] ?? 2.25);
        if (!empty($global['toll_auto_estimate'])) {
            $tollP = self::toPaise($chargeableKm * $tollRate);
        }

        // 7. Parking Surcharge (with switch check)
        $parkingP = 0;
        $defaultParking = (float)($global['default_parking_amount'] ?? 0.0);
        if (!empty($global['parking_active'])) {
            $parkingP = self::toPaise($defaultParking);
        }

        // Subtotal (Base KM + Allowance + Toll + Parking)
        $subtotalP = $baseKmChargeP + $allowanceP + $tollP + $parkingP;

        // 8. Tax / GST Calculation (Flat or Split by Route)
        $gstP = 0;
        $gstMode = $global['gst_mode'] ?? 'flat';
        $gstPercent = (float)($global['gst_percent'] ?? 5.0);
        $cgstPercent = (float)($global['cgst_percent'] ?? 2.5);
        $sgstPercent = (float)($global['sgst_percent'] ?? 2.5);
        $igstPercent = (float)($global['igst_percent'] ?? 5.0);

        $gstBreakdown = [
            'is_active' => (bool)($global['gst_active'] ?? true),
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

        // 9. Discount Calculation (with switch check)
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

        // 10. Company Share & Driver Net Payout Calculation
        $companyShareActive = !empty($global['company_share_active']);
        $companyShareType = $global['company_share_type'] ?? 'percentage';
        $companyShareBasis = $global['company_share_basis'] ?? 'subtotal';
        
        // Check if vehicle rule has specific override
        $vehShareOverride = (float)($vehRule['company_share_percent'] ?? 0.0);
        $companyShareValue = ($vehShareOverride > 0) ? $vehShareOverride : (float)($global['company_share_value'] ?? 15.0);

        $companyShareP = 0;
        if ($companyShareActive && $companyShareValue > 0) {
            $basisP = ($companyShareBasis === 'base_km') ? $baseKmChargeP : $subtotalP;
            if ($companyShareType === 'fixed') {
                $companyShareP = min($basisP, self::toPaise($companyShareValue));
            } else {
                $companyShareP = self::pctOf($basisP, $companyShareValue);
            }
        }

        $driverPayoutP = max(0, $subtotalP - $companyShareP);

        $companyShareBreakdown = [
            'is_active'       => $companyShareActive,
            'type'            => $companyShareType,
            'value'           => $companyShareValue,
            'basis'           => $companyShareBasis,
            'company_share'   => self::fromPaise($companyShareP),
            'driver_payout'   => self::fromPaise($driverPayoutP),
            'is_veh_override' => ($vehShareOverride > 0)
        ];

        return [
            'master_engine_active'    => (bool)($global['master_engine_active'] ?? true),
            'car_type'                => $vehRule['car_type_label'] ?? (string)$carType,
            'car_type_id'             => (int)($vehRule['car_type_id'] ?? 0),
            'distance_km'             => $distanceKm,
            'chargeable_km'           => $chargeableKm,
            'km_rate'                 => $kmRate,
            'raw_base_km_charge'      => round($rawBaseKmCharge, 2),
            'base_km_charge'          => self::fromPaise($baseKmChargeP),
            'dynamic_pricing'         => $dynamicMetrics,
            'driver_allowance'        => self::fromPaise($allowanceP),
            'driver_allowance_active' => (bool)($global['driver_allowance_active'] ?? true),
            'toll_charge'             => self::fromPaise($tollP),
            'parking_charge'          => self::fromPaise($parkingP),
            'subtotal'                => self::fromPaise($subtotalP),
            'gst_amount'              => self::fromPaise($gstP),
            'gst_breakdown'           => $gstBreakdown,
            'discount_amount'         => self::fromPaise($discountP),
            'discount_active'         => (bool)($global['discount_active'] ?? false),
            'final_fare'              => self::fromPaise($finalFareP),
            'final_fare_rounded'      => round(self::fromPaise($finalFareP)),
            'company_share_amount'    => self::fromPaise($companyShareP),
            'driver_payout_amount'    => self::fromPaise($driverPayoutP),
            'company_share_breakdown' => $companyShareBreakdown,
        ];
    }

    /**
     * One-Way Dynamic Demand Engine
     * Calculates Reference Demand, Today's Demand, Demand Ratio, Sensitivity Adjustment, and Bound Protection.
     */
    public static function calculateOneWayDynamicDemand(
        mysqli $conn,
        array $global,
        array $vehRule,
        float $baseRate,
        array $overrides = [],
        ?string $targetDate = null
    ): array {
        $isActive = !empty($global['dynamic_pricing_active']);
        $sensitivity = isset($overrides['oneway_pricing_sensitivity']) 
            ? (float)$overrides['oneway_pricing_sensitivity'] 
            : (float)($global['oneway_pricing_sensitivity'] ?? 50.0);
        $outlierThreshold = isset($overrides['outlier_threshold_pct'])
            ? (float)$overrides['outlier_threshold_pct']
            : (float)($global['outlier_threshold_pct'] ?? 50.0);

        // 1. Reference Demand (from historical One-Way bookings volume)
        $refDemand = isset($overrides['simulated_reference_demand'])
            ? (float)$overrides['simulated_reference_demand']
            : self::getHistoricalOneWayReferenceDemand($conn, $global, $outlierThreshold);

        // 2. Travel-Date Aware One-Way Demand volume
        $targetDateStr = !empty($targetDate) ? $targetDate : ($overrides['target_date'] ?? date('Y-m-d'));
        $todayDemand = isset($overrides['simulated_today_demand'])
            ? (float)$overrides['simulated_today_demand']
            : self::getOneWayDemandForDate($conn, $targetDateStr);

        // Fallbacks for safety
        if ($refDemand <= 0) $refDemand = 1.0;

        // 3. Demand Ratio & Demand Change %
        if ($todayDemand <= 0 && !isset($overrides['simulated_today_demand'])) {
            // If no bookings placed yet today, default to standard baseline (1.0 ratio)
            $demandRatio = 1.0000;
            $demandChangePct = 0.0;
        } else {
            $demandRatio = round($todayDemand / $refDemand, 4);
            $demandChangePct = ($demandRatio - 1.0) * 100.0;
        }

        // 4. Price Adjustment % based on Sensitivity
        $priceAdjustmentPct = $isActive ? ($demandChangePct * ($sensitivity / 100.0)) : 0.0;

        // 5. Dynamic One-Way Rate
        $dynamicRate = $baseRate * (1.0 + ($priceAdjustmentPct / 100.0));

        // 6. Minimum / Maximum Boundary Protection
        $minRate = (float)($vehRule['min_rate'] ?? 0.0);
        $maxRate = (float)($vehRule['max_rate'] ?? 0.0);

        if ($minRate <= 0) {
            $minMultiplier = (float)($global['min_rate_multiplier'] ?? 0.80);
            $minRate = $baseRate * $minMultiplier;
        }
        if ($maxRate <= 0) {
            $maxMultiplier = (float)($global['max_rate_multiplier'] ?? 1.40);
            $maxRate = $baseRate * $maxMultiplier;
        }

        $finalRate = $dynamicRate;
        $isFloorCapped = false;
        $isCeilingCapped = false;

        if ($isActive) {
            if ($finalRate < $minRate) {
                $finalRate = $minRate;
                $isFloorCapped = true;
            } elseif ($finalRate > $maxRate) {
                $finalRate = $maxRate;
                $isCeilingCapped = true;
            }
        } else {
            $finalRate = $baseRate;
        }

        $explanation = self::generateExplainabilityText(
            $todayDemand,
            $refDemand,
            $demandChangePct,
            $sensitivity,
            $priceAdjustmentPct,
            $baseRate,
            $finalRate,
            $isActive
        );

        return [
            'is_active'            => $isActive,
            'reference_demand'     => $refDemand,
            'today_demand'         => $todayDemand,
            'demand_ratio'         => $demandRatio,
            'demand_change_pct'    => round($demandChangePct, 1),
            'pricing_sensitivity'  => $sensitivity,
            'price_adjustment_pct' => round($priceAdjustmentPct, 1),
            'base_one_way_rate'    => $baseRate,
            'dynamic_one_way_rate' => $dynamicRate,
            'min_one_way_rate'     => $minRate,
            'max_one_way_rate'     => $maxRate,
            'final_one_way_rate'   => $finalRate,
            'is_floor_capped'      => $isFloorCapped,
            'is_ceiling_capped'    => $isCeilingCapped,
            'explanation_text'     => $explanation
        ];
    }

    /**
     * Calculates Reference Demand from historical One-Way bookings volume
     */
    public static function getHistoricalOneWayReferenceDemand(mysqli $conn, array $global, float $outlierThreshold): float {
        $cacheKey = 'oneway_ref_demand_' . date('Ymd');
        $cached = FareCache::get($cacheKey);
        if ($cached !== null && is_numeric($cached)) {
            return (float)$cached;
        }

        $lookbackDays = (int)($global['historical_lookback_days'] ?? 14);
        if ($lookbackDays <= 0) $lookbackDays = 14;

        // Query historical One-Way bookings grouped by date
        $query = "SELECT 
                    DATE(`date`) as b_date, 
                    COUNT(*) as booking_count 
                  FROM `bookings` 
                  WHERE LOWER(`trip_type`) LIKE '%one-way%' 
                    AND `date` >= DATE_SUB(CURDATE(), INTERVAL $lookbackDays DAY)
                    AND `date` < CURDATE()
                  GROUP BY DATE(`date`)";

        $res = mysqli_query($conn, $query);
        $demandHistory = [];

        if ($res && mysqli_num_rows($res) > 0) {
            while ($row = mysqli_fetch_assoc($res)) {
                $demandHistory[] = (float)$row['booking_count'];
            }
        }

        // If not enough data, use healthy reference baseline
        if (count($demandHistory) < 3) {
            $fallback = 10.0;
            FareCache::set($cacheKey, $fallback, 3600);
            return $fallback;
        }

        // Calculate Median
        $median = self::calculateMedian($demandHistory);

        // Filter Outliers: keep only demands within median ± threshold%
        $lowerBound = $median * (1.0 - ($outlierThreshold / 100.0));
        $upperBound = $median * (1.0 + ($outlierThreshold / 100.0));

        $filteredDemands = array_filter($demandHistory, function ($val) use ($lowerBound, $upperBound) {
            return $val >= $lowerBound && $val <= $upperBound;
        });

        if (empty($filteredDemands)) {
            $filteredDemands = $demandHistory;
        }

        $refDemand = array_sum($filteredDemands) / count($filteredDemands);
        $refDemand = round(max(1.0, $refDemand), 2);

        FareCache::set($cacheKey, $refDemand, 3600);
        return $refDemand;
    }

    /**
     * Calculates One-Way Demand volume for a specific travel date
     */
    public static function getOneWayDemandForDate(mysqli $conn, string $targetDate): float {
        $todayDate = date('Y-m-d');
        
        // If target travel date is today, check today's live bookings
        if ($targetDate === $todayDate) {
            $todayRes = mysqli_query($conn, "SELECT COUNT(*) as today_count FROM `bookings` WHERE LOWER(`trip_type`) LIKE '%one-way%' AND `date` = '$todayDate'");
            return ($todayRes && $bRow = mysqli_fetch_assoc($todayRes)) ? (float)$bRow['today_count'] : 0.0;
        }

        // If target travel date is in the future
        $escDate = mysqli_real_escape_string($conn, $targetDate);
        $res = mysqli_query($conn, "SELECT COUNT(*) as future_count FROM `bookings` WHERE LOWER(`trip_type`) LIKE '%one-way%' AND `date` = '$escDate'");
        $futureCount = ($res && $bRow = mysqli_fetch_assoc($res)) ? (int)$bRow['future_count'] : 0;

        // For future dates:
        // If low advance bookings (0 to 2), return 0.0 so engine uses standard 1.00 baseline (0% surge)
        // If heavy advance bookings (3+ bookings), return the actual pre-booking count to activate advance surge!
        if ($futureCount <= 2) {
            return 0.0;
        }

        return (float)$futureCount;
    }

    /**
     * Calculates Today's One-Way Demand volume (legacy helper)
     */
    public static function getTodaysOneWayDemand(mysqli $conn): float {
        return self::getOneWayDemandForDate($conn, date('Y-m-d'));
    }

    /**
     * Calculates Median of an array of numbers
     */
    public static function calculateMedian(array $numbers): float {
        if (empty($numbers)) return 1.0;
        sort($numbers, SORT_NUMERIC);
        $count = count($numbers);
        $mid = (int)floor($count / 2);

        if ($count % 2 === 0) {
            return ($numbers[$mid - 1] + $numbers[$mid]) / 2.0;
        }
        return (float)$numbers[$mid];
    }

    /**
     * Generates a clear, plain-English explanation of why the price changed
     */
    private static function generateExplainabilityText(
        float $todayDemand,
        float $refDemand,
        float $demandChangePct,
        float $sensitivity,
        float $priceAdjustmentPct,
        float $baseRate,
        float $finalRate,
        bool $isActive
    ): string {
        if (!$isActive) {
            return "Dynamic pricing is currently inactive. Standard Base One-Way Rate applied.";
        }

        $direction = $demandChangePct >= 0 ? "higher" : "lower";
        $sign = $priceAdjustmentPct >= 0 ? "+" : "";
        $absChange = abs(round($demandChangePct, 2));
        $formattedAdj = $sign . round($priceAdjustmentPct, 2) . "%";

        if (abs($demandChangePct) < 0.5) {
            return "Today's One-Way demand is at normal historical baseline level ($todayDemand vs $refDemand ref). Base One-Way rate ₹" . number_format($baseRate, 2) . " applied.";
        }

        return "Today's One-Way demand is {$absChange}% {$direction} than the historical reference demand ({$todayDemand} vs {$refDemand}). With {$sensitivity}% pricing sensitivity, the One-Way rate was adjusted by {$formattedAdj} (Base Rate: ₹" . number_format($baseRate, 2) . " → Final Rate: ₹" . number_format($finalRate, 2) . ").";
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
            return true;
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
            'id'                         => 1,
            'master_engine_active'       => 1,
            'driver_allowance_active'    => 1,
            'discount_active'            => 0,
            'discount_type'              => 'percentage',
            'discount_value'             => 10.0,
            'gst_active'                 => 1,
            'gst_mode'                   => 'split',
            'gst_percent'                => 5.0,
            'cgst_percent'               => 2.5,
            'sgst_percent'               => 2.5,
            'igst_percent'               => 5.0,
            'parking_active'             => 0,
            'default_parking_amount'     => 0.0,
            'toll_auto_estimate'         => 1,
            'toll_per_km_rate'           => 2.25,
            'dynamic_pricing_active'     => 1,
            'oneway_pricing_sensitivity' => 50.0,
            'outlier_threshold_pct'      => 50.0,
            'historical_lookback_days'   => 14,
            'company_share_active'       => 1,
            'company_share_type'         => 'percentage',
            'company_share_value'        => 15.0,
            'company_share_basis'        => 'subtotal',
            'row_version'                => 1
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
            'min_rate'               => 0.0,
            'max_rate'               => 0.0,
            'min_rate_multiplier'    => 0.80,
            'max_rate_multiplier'    => 1.40,
            'company_share_percent'  => 0.0,
            'is_active'              => 1,
            'row_version'            => 1
        ];
    }
}
