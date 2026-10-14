<?php
declare(strict_types=1);

/**
 * Application Configuration
 * Values can be overridden by .env file
 */

// Helper to get env with default
if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed {
        $value = getenv($key);
        if ($value === false) {
            return $default;
        }
        return $value;
    }
}

return [
    'name' => env('APP_NAME', 'Ambulance Refer Checklist'),
    'version' => env('APP_VERSION', '2.0.0'),
    'timezone' => env('APP_TIMEZONE', 'Asia/Bangkok'),
    'locale' => env('APP_LOCALE', 'th_TH'),
    'debug' => filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN),
    'url' => env('APP_URL', 'http://localhost/refer-system/public'),
    'session_lifetime' => (int)env('SESSION_LIFETIME', '480'),
    'password_hash' => env('PASSWORD_HASH', ''),
    'origin' => env('APP_ORIGIN', 'http://localhost/refer-system/public'),
];