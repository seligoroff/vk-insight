<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Indicates whether migrations have been run.
     *
     * @var bool
     */
    protected static $migrationsRun = false;

    /**
     * Setup the test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Run migrations once before all tests if they haven't been run yet
        if (!static::$migrationsRun) {
            $this->runMigrations();
            static::$migrationsRun = true;
        }
    }

    /**
     * Run database migrations and clear tables.
     *
     * @return void
     */
    protected function runMigrations(): void
    {
        // Only run migrations if we're using a real database (not in-memory SQLite)
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        // Skip if using in-memory database (SQLite :memory:)
        if ($database === ':memory:') {
            return;
        }

        try {
            // Drop all tables and re-run migrations to ensure clean state
            Artisan::call('migrate:fresh', [
                '--force' => true,
                '--env' => 'testing',
            ]);

            // Also clear any data that might be left
            $this->clearDatabase();
        } catch (\Exception $e) {
            // If migrations fail, log error but don't fail tests immediately
            // This allows tests to run even if DB is not fully set up
            if (config('app.debug')) {
                error_log('Migration warning: ' . $e->getMessage());
            }
        }
    }

    /**
     * Clear all data from database tables (except migrations table).
     *
     * @return void
     */
    protected function clearDatabase(): void
    {
        try {
            $connection = DB::connection();
            $driverName = $connection->getDriverName();

            // Get list of tables
            $tables = [];
            if ($driverName === 'mysql') {
                $tables = DB::select('SHOW TABLES');
                $tables = array_map(function ($table) {
                    $key = array_keys((array) $table)[0];
                    return $table->$key;
                }, $tables);
            } else {
                // For other databases, try Doctrine if available
                try {
                    $schemaManager = $connection->getDoctrineSchemaManager();
                    $tables = $schemaManager->listTableNames();
                } catch (\Exception $e) {
                    // If Doctrine is not available, skip cleanup
                    return;
                }
            }

            // Disable foreign key checks temporarily for MySQL
            if ($driverName === 'mysql') {
                DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            }

            foreach ($tables as $table) {
                // Skip migrations table to avoid issues
                if ($table !== 'migrations') {
                    try {
                        DB::table($table)->truncate();
                    } catch (\Exception $e) {
                        // Skip tables that can't be truncated
                        continue;
                    }
                }
            }

            // Re-enable foreign key checks
            if ($driverName === 'mysql') {
                DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            }
        } catch (\Exception $e) {
            // Ignore errors during cleanup
            if (config('app.debug')) {
                error_log('Database cleanup warning: ' . $e->getMessage());
            }
        }
    }
}
