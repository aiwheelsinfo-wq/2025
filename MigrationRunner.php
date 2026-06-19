<?php

/**
 * MigrationRunner
 * 
 * Automatically manages, executes, and logs database migrations.
 * Uses MySQL GET_LOCK to prevent concurrent executions during deployment.
 */
class MigrationRunner {
    private static $logFile = __DIR__ . '/migrations.log';

    /**
     * Entrypoint to run all pending migrations.
     *
     * @param mysqli $conn The active MySQLi connection.
     */
    public static function run(mysqli $conn) {
        // Guard against multiple executions in the same PHP request lifecycle
        if (defined('MIGRATIONS_RUN_EXECUTED')) {
            return;
        }
        define('MIGRATIONS_RUN_EXECUTED', true);

        // 1. Acquire MySQL Lock to prevent concurrent executions (e.g., during Git push and multiple initial web requests)
        $lockName = 'migration_runner_lock';
        $lockTimeout = 10;
        $lockResult = mysqli_query($conn, "SELECT GET_LOCK('$lockName', $lockTimeout)");
        if (!$lockResult) {
            self::log("WARNING: Failed to execute GET_LOCK query: " . mysqli_error($conn));
            return;
        }
        
        $lockRow = mysqli_fetch_row($lockResult);
        if (!$lockRow || $lockRow[0] != 1) {
            self::log("WARNING: Could not acquire migration lock '$lockName' within $lockTimeout seconds. Skipping migrations.");
            return;
        }

        try {
            self::executeMigrations($conn);
        } catch (Throwable $e) {
            self::log("ERROR: Uncaught exception during migrations: " . $e->getMessage() . PHP_EOL . $e->getTraceAsString());
        } finally {
            // 2. Release MySQL Lock
            mysqli_query($conn, "SELECT RELEASE_LOCK('$lockName')");
        }
    }

    /**
     * Scans migrations folder and runs pending migrations.
     */
    private static function executeMigrations(mysqli $conn) {
        // Ensure migrations table exists
        self::ensureMigrationsTable($conn);

        // Fetch already executed migrations
        $executed = [];
        $result = mysqli_query($conn, "SELECT migration_name FROM migrations");
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $executed[] = $row['migration_name'];
            }
            mysqli_free_result($result);
        } else {
            self::log("ERROR: Failed to fetch executed migrations: " . mysqli_error($conn));
            return;
        }

        // Scan migrations directory
        $migrationsDir = __DIR__ . '/migrations';
        if (!is_dir($migrationsDir)) {
            if (!mkdir($migrationsDir, 0755, true)) {
                self::log("ERROR: Migrations directory '$migrationsDir' does not exist and could not be created.");
                return;
            }
        }

        $files = scandir($migrationsDir);
        $pending = [];
        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                if (!in_array($file, $executed)) {
                    $pending[] = $file;
                }
            }
        }

        // Sort migrations to execute in alphabetical/numerical order (e.g. 001, 002...)
        sort($pending);

        if (empty($pending)) {
            return; // No pending migrations
        }

        self::log("INFO: Found " . count($pending) . " pending migration(s) to execute.");

        foreach ($pending as $migrationFile) {
            $filePath = $migrationsDir . '/' . $migrationFile;
            self::log("INFO: Running migration: $migrationFile");

            // Execute migration
            try {
                // Ingest the migration file. We support both:
                // 1. Returning an anonymous function (encapsulated, recommended)
                // 2. Procedural PHP (queries execute immediately during require)
                $migration = require $filePath;
                
                if (is_callable($migration)) {
                    $migration($conn);
                }

                // Record execution in the migrations table
                $stmt = mysqli_prepare($conn, "INSERT INTO migrations (migration_name) VALUES (?)");
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, "s", $migrationFile);
                    if (mysqli_stmt_execute($stmt)) {
                        self::log("SUCCESS: Migration executed and recorded: $migrationFile");
                    } else {
                        $err = mysqli_stmt_error($stmt);
                        self::log("ERROR: Failed to record migration execution for $migrationFile: $err");
                        throw new Exception("Failed to record migration: $err");
                    }
                    mysqli_stmt_close($stmt);
                } else {
                    $err = mysqli_error($conn);
                    self::log("ERROR: Failed to prepare migration record statement: $err");
                    throw new Exception("Failed to prepare statement: $err");
                }

            } catch (Throwable $e) {
                self::log("FAIL: Migration failed: $migrationFile. Error: " . $e->getMessage());
                // Abort subsequent migrations if one fails to prevent cascading database errors
                break;
            }
        }
    }

    /**
     * Checks if migrations table exists, and creates it if it doesn't.
     */
    private static function ensureMigrationsTable(mysqli $conn) {
        $tableExists = false;
        $result = mysqli_query($conn, "SHOW TABLES LIKE 'migrations'");
        if ($result && mysqli_num_rows($result) > 0) {
            $tableExists = true;
            mysqli_free_result($result);
        }

        if (!$tableExists) {
            self::log("INFO: Migrations table does not exist. Creating it.");
            $sql = "CREATE TABLE IF NOT EXISTS migrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration_name VARCHAR(255) UNIQUE,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )";
            if (mysqli_query($conn, $sql)) {
                self::log("SUCCESS: Created migrations table.");
            } else {
                self::log("ERROR: Failed to create migrations table: " . mysqli_error($conn));
            }
        }
    }

    /**
     * Writes messages to migrations.log file.
     */
    private static function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message" . PHP_EOL;
        file_put_contents(self::$logFile, $logMessage, FILE_APPEND);
    }
}
