<?php

/**
 * Structured Audit Logger for One-Way Fare Management
 * Records before and after JSON snapshots for complete accountability & rollback capability.
 */
class OneWayAuditLogger {

    public static function record(
        mysqli $conn,
        string $adminId,
        string $actionType,
        int $targetId,
        array $previousValues,
        array $newValues
    ): bool {
        try {
            $stmt = $conn->prepare(
                "INSERT INTO `one_way_fare_audit_log` (`admin_id`, `action_type`, `target_id`, `previous_values`, `new_values`) VALUES (?, ?, ?, ?, ?)"
            );
            if (!$stmt) return false;

            $prevJson = json_encode($previousValues, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $newJson = json_encode($newValues, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $stmt->bind_param("ssiss", $adminId, $actionType, $targetId, $prevJson, $newJson);
            $success = $stmt->execute();
            $stmt->close();
            return $success;
        } catch (Throwable $e) {
            error_log("OneWayAuditLogger Error: " . $e->getMessage());
            return false;
        }
    }
}
