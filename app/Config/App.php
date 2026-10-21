<?php
declare(strict_types=1);

namespace App\Config;

class App
{
    private static array $config = [];

    public static function load(): void
    {
        if (empty(self::$config)) {
            self::$config = require __DIR__ . '/../../config/app.php';
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();
        $keys = explode('.', $key);
        $value = self::$config;
        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }
        return $value;
    }

    public static function env(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);
        if ($value === false) {
            return $default;
        }
        return $value;
    }

    public static function url(string $path = ''): string
    {
        self::load();
        // Path only (no scheme/host): an absolute APP_URL breaks the page when it is
        // opened under another host name (localhost vs 127.0.0.1) because the CSP
        // allows only 'self' — the browser would block every CSS/JS file.
        $base = rtrim((string)parse_url(self::$config['url'], PHP_URL_PATH), '/');
        return $base . '/' . ltrim($path, '/');
    }

    public static function asset(string $path): string
    {
        $path = ltrim($path, '/');
        $url = self::url('assets/' . $path);

        // Cache-bust: assets are served with a 1-year max-age header
        $file = __DIR__ . '/../../public/assets/' . $path;
        if (!is_file($file)) {
            $file = __DIR__ . '/../../resources/assets/' . $path;
        }
        if (is_file($file)) {
            $url .= '?v=' . filemtime($file);
        }

        return $url;
    }
}