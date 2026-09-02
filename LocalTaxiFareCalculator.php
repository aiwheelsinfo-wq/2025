<?php
declare(strict_types=1);

/**
 * LocalTaxiFareCalculator
 * 
 * Standalone, isolated Dynamic Pricing & Fare Calculation Engine for Local City Taxi.
 * Calculates: Base Minimum Fare + Excess KM + Peak/Night Surcharges + Demand Elasticity + GST + Driver/Company Splits.
 */
class LocalTaxiFareCalculator
{
    private static function toPaise(float $amount): int
    {
        return (int)round($amount * 100.0);
    }

    private static function fromPaise(int $paise): float
    {
        return round($paise / 100.0, 2);
    }

    private static function pctOf(int $paise, float $pct): int
    {
        return (int)round(($paise * $pct) / 100.0);
    }

    /**
     * Fetch global local taxi settings
     */
    public static function getGlobalSettings(mysqli $conn): array
    {
        $res = mysqli_query($conn, "SELECT * FROM `local_taxi_global_settings` WHERE `id` = 1 LIMIT 1");
        if ($res && $row = mysqli_fetch_assoc($res)) {
            return $row;
        }
        return [
            'dynamic_pricing_active' => 1,
            'pricing_sensitivity' => 50.0,
            'peak_surge_active' => 1,
            'peak_morning_start' => '08:00:00',
            'peak_morning_end' => '11:00:00',
            'peak_evening_start' => '17:30:00',
            'peak_evening_end' => '21:00:00',
            'peak_multiplier' => 1.25,
            'night_surcharge_active' => 1,
            'night_start' => '23:00:00',
            'night_end' => '05:00:00',
            'night_multiplier' => 1.20,
            'gst_active' => 1,
            'gst_rate' => 5.00,
            'company_share_active' => 1,
            'company_share_type' => 'percent',
            'company_share_value' => 10.00,
            'is_active' => 1
        ];
    }

    /**
     * Fetch vehicle rule for local taxi
     */
    public static function getVehicleRule(mysqli $conn, $carType): array
    {
        $esc = mysqli_real_escape_string($conn, (string)$carType);
        $res = mysqli_query($conn, "SELECT * FROM `local_taxi_vehicle_rules` WHERE `car_type_label` = '$esc' OR `car_type_id` = '$esc' LIMIT 1");
        if ($res && $row = mysqli_fetch_assoc($res)) {
            return $row;
        }
        return [
            'car_type_id' => 2,
            'car_type_label' => 'Sedan',
            'base_fare' => 250.00,
            'included_base_km' => 5.00,
            'per_km_rate' => 14.00,
            'waiting_charge_per_min' => 2.00,
            'min_floor_rate' => 11.20,
            'max_ceiling_rate' => 21.00,
            'is_active' => 1
        ];
    }

    /**
     * Calculates Local Taxi Fare
     */
    public static function calculate(
        mysqli $conn,
        $carType,
        float $distanceKm,
        string $pickupTime = '',
        array $settingsOverride = []
    ): array {
        $global = self::getGlobalSettings($conn);
        if (!empty($settingsOverride)) {
            $global = array_merge($global, $settingsOverride);
        }

        $vehRule = self::getVehicleRule($conn, $carType);
        if (!empty($settingsOverride['vehicle_rule_override'])) {
            $vehRule = array_merge($vehRule, $settingsOverride['vehicle_rule_override']);
        }

        $baseFare = (float)($vehRule['base_fare'] ?? 250.00);
        $includedKm = (float)($vehRule['included_base_km'] ?? 5.00);
        $baseKmRate = (float)($vehRule['per_km_rate'] ?? 14.00);

        // 1. Distance breakdown
        $chargeableKm = max($distanceKm, 1.0);
        $excessKm = max(0.0, $chargeableKm - $includedKm);

        // 2. Dynamic Demand Elasticity
        $dynamicMetrics = self::calculateDynamicRate($conn, $global, $vehRule, $baseKmRate, $settingsOverride);
        $effectiveKmRate = $dynamicMetrics['effective_km_rate'];

        // 3. Excess KM Charge
        $excessCharge = $excessKm * $effectiveKmRate;
        $subtotalBeforeSurcharges = $baseFare + $excessCharge;

        // 4. Time-of-Day Surcharges (Peak Rush & Night)
        $timeSurcharges = self::evaluateTimeSurcharges($global, $pickupTime);
        $multiplier = $timeSurcharges['total_multiplier'];

        $subtotalAfterSurcharges = $subtotalBeforeSurcharges * $multiplier;
        $subtotalP = self::toPaise($subtotalAfterSurcharges);

        // 5. GST Tax (5%)
        $gstRate = !empty($global['gst_active']) ? (float)($global['gst_rate'] ?? 5.00) : 0.0;
        $gstP = self::pctOf($subtotalP, $gstRate);
        $totalCustomerFareP = $subtotalP + $gstP;

        // 6. Platform Company Share & Driver Payout
        $companyProfitP = 0;
        if (!empty($global['company_share_active'])) {
            if (($global['company_share_type'] ?? 'percent') === 'flat') {
                $companyProfitP = self::toPaise((float)($global['company_share_value'] ?? 40.0));
            } else {
                $companyPct = (float)($global['company_share_value'] ?? 10.0);
                $companyProfitP = self::pctOf($subtotalP, $companyPct);
            }
        }

        $driverPayoutP = max(0, $subtotalP - $companyProfitP);

        return [
            'trip_type'              => 'Local Taxi',
            'car_type'               => $vehRule['car_type_label'],
            'distance_km'            => $chargeableKm,
            'base_fare'              => $baseFare,
            'included_base_km'       => $includedKm,
            'base_km_rate'           => $baseKmRate,
            'effective_km_rate'      => $effectiveKmRate,
            'excess_km'              => $excessKm,
            'excess_km_charge'       => round($excessCharge, 2),
            'time_surcharges'        => $timeSurcharges,
            'subtotal_fare'          => self::fromPaise($subtotalP),
            'gst_rate'               => $gstRate,
            'gst_amount'             => self::fromPaise($gstP),
            'final_customer_fare'    => self::fromPaise($totalCustomerFareP),
            'company_share_amount'   => self::fromPaise($companyProfitP),
            'driver_payout_amount'   => self::fromPaise($driverPayoutP),
            'dynamic_pricing'        => $dynamicMetrics,
        ];
    }

    /**
     * Evaluates Peak Hour & Night Surcharges
     */
    private static function evaluateTimeSurcharges(array $global, string $timeStr): array
    {
        $checkTime = !empty($timeStr) ? date('H:i:s', strtotime($timeStr)) : date('H:i:s');
        
        $isPeak = false;
        $isNight = false;
        $multiplier = 1.0;
        $appliedLabels = [];

        // Check Peak Morning Rush (8:00 AM - 11:00 AM)
        if (!empty($global['peak_surge_active'])) {
            $mStart = $global['peak_morning_start'] ?? '08:00:00';
            $mEnd = $global['peak_morning_end'] ?? '11:00:00';
            $eStart = $global['peak_evening_start'] ?? '17:30:00';
            $eEnd = $global['peak_evening_end'] ?? '21:00:00';

            if (($checkTime >= $mStart && $checkTime <= $mEnd) || ($checkTime >= $eStart && $checkTime <= $eEnd)) {
                $isPeak = true;
                $pMult = (float)($global['peak_multiplier'] ?? 1.25);
                $multiplier *= $pMult;
                $appliedLabels[] = "Peak City Rush (+".round(($pMult - 1.0)*100)."%)";
            }
        }

        // Check Night Surcharge (11:00 PM - 5:00 AM)
        if (!empty($global['night_surcharge_active'])) {
            $nStart = $global['night_start'] ?? '23:00:00';
            $nEnd = $global['night_end'] ?? '05:00:00';

            if ($checkTime >= $nStart || $checkTime <= $nEnd) {
                $isNight = true;
                $nMult = (float)($global['night_multiplier'] ?? 1.20);
                $multiplier *= $nMult;
                $appliedLabels[] = "Night Surcharge (+".round(($nMult - 1.0)*100)."%)";
            }
        }

        return [
            'checked_time'     => $checkTime,
            'is_peak'          => $isPeak,
            'is_night'         => $isNight,
            'total_multiplier' => round($multiplier, 2),
            'applied_labels'   => $appliedLabels
        ];
    }

    /**
     * Real-time dynamic demand & supply elasticity
     */
    private static function calculateDynamicRate(
        mysqli $conn,
        array $global,
        array $vehRule,
        float $baseRate,
        array $overrides = []
    ): array {
        $isActive = !empty($global['dynamic_pricing_active']);
        $sensitivity = (float)($global['pricing_sensitivity'] ?? 50.0);

        // Fetch live local bookings today vs reference baseline
        $todayRes = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM `bookings` WHERE LOWER(`trip_type`) LIKE '%local%' AND `date` = CURDATE()");
        $todayDemand = ($todayRes && $r = mysqli_fetch_assoc($todayRes)) ? (float)$r['cnt'] : 0.0;
        
        $refDemand = (float)($overrides['simulated_reference_demand'] ?? 8.0);
        if ($todayDemand <= 0) {
            $demandRatio = 1.0;
            $demandChangePct = 0.0;
        } else {
            $demandRatio = round($todayDemand / $refDemand, 4);
            $demandChangePct = ($demandRatio - 1.0) * 100.0;
        }

        $priceAdjPct = $isActive ? ($demandChangePct * ($sensitivity / 100.0)) : 0.0;
        $rawRate = $baseRate * (1.0 + ($priceAdjPct / 100.0));

        $minFloor = (float)($vehRule['min_floor_rate'] ?? ($baseRate * 0.80));
        $maxCeiling = (float)($vehRule['max_ceiling_rate'] ?? ($baseRate * 1.50));

        $effectiveRate = max($minFloor, min($maxCeiling, $rawRate));

        return [
            'is_active'          => $isActive,
            'today_demand'       => $todayDemand,
            'reference_demand'   => $refDemand,
            'demand_ratio'       => $demandRatio,
            'demand_change_pct'  => round($demandChangePct, 1),
            'price_adj_pct'      => round($priceAdjPct, 1),
            'base_km_rate'       => $baseRate,
            'effective_km_rate'  => round($effectiveRate, 2),
            'min_floor'          => $minFloor,
            'max_ceiling'        => $maxCeiling
        ];
    }
}
