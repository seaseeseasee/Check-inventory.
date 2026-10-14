<?php
/**
 * Simple .env file loader for XAMPP compatibility
 * No external dependencies required
 */

function loadEnv(string $path = null): void
{
    if ($path === null) {
        // Try multiple locations
        $possiblePaths = [
            __DIR__ . '/../.env',
            __DIR__ . '/../../.env',
            __DIR__ . '/../../../.env',
        ];
        
        foreach ($possiblePaths as $p) {
            if (file_exists($p)) {
                $path = $p;
                break;
            }
        }
    }
    
    if ($path === null || !file_exists($path)) {
        return; // No .env file found, use defaults
    }
    
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    foreach ($lines as $line) {
        $line = trim($line);
        
        // Skip comments and empty lines
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        
        // Parse KEY=VALUE
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $matches)) {
            $key = $matches[1];
            $value = $matches[2];
            
            // Remove surrounding quotes
            if (preg_match('/^"(.*)"$/', $value, $m)) {
                $value = $m[1];
            } elseif (preg_match("/^'(.*)'$/", $value, $m)) {
                $value = $m[1];
            }
            
            // Set environment variable
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

// Auto-load .env when this file is included
loadEnv();