<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        // Load .env.testing before creating the application
        $this->loadTestingEnvironment();

        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    /**
     * Load .env.testing file if it exists.
     * This must be called before creating the Laravel application.
     * 
     * Looks for .env.testing in:
     * 1. tests/.env.testing (current directory)
     * 2. .env.testing (project root)
     *
     * @return void
     */
    protected function loadTestingEnvironment(): void
    {
        // First, try in tests/ directory (current location)
        $envTestingPath = __DIR__.'/.env.testing';
        
        // If not found, try in project root
        if (!file_exists($envTestingPath)) {
            $envTestingPath = __DIR__.'/../.env.testing';
        }
        
        if (!file_exists($envTestingPath)) {
            return;
        }

        // Load .env.testing file manually before Laravel loads .env
        $lines = file($envTestingPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip empty lines and comments
            if (empty($line) || strpos($line, '#') === 0) {
                continue;
            }

            // Parse KEY=VALUE format
            if (strpos($line, '=') !== false) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                // Remove quotes if present (both single and double)
                if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
                    (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
                    $value = substr($value, 1, -1);
                }
                
                // Unescape escaped characters
                $value = str_replace(['\\n', '\\r'], ["\n", "\r"], $value);
                
                // Set environment variable if not already set (phpunit.xml takes precedence)
                if (!getenv($key)) {
                    $_ENV[$key] = $value;
                    $_SERVER[$key] = $value;
                    putenv("{$key}={$value}");
                }
            }
        }
    }
}
