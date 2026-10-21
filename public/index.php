<?php
/**
 * Public Entry Point for XAMPP
 * 
 * This is the main entry point for the application.
 * It handles routing and serves the SPA.
 * Works with XAMPP without Virtual Host configuration.
 */

// Load environment variables (simple loader, no Composer required)
require_once __DIR__ . '/../config/env.php';
loadEnv();

// Load helper functions first (provides env() function)
require_once __DIR__ . '/../config/bootstrap.php';

// Load configuration
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

// Load simple autoloader (no Composer required)
require_once __DIR__ . '/../vendor/autoload.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    // Detect if we're on HTTPS (for production)
    $secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
        && !in_array($_SERVER['HTTP_HOST'], ['localhost', '127.0.0.1', '[::1]'], true);
    
    // Determine session path based on installation
    $basePath = '/';
    if (isset($_SERVER['SCRIPT_NAME'])) {
        $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
        if ($scriptDir !== '/' && $scriptDir !== '\\') {
            $basePath = rtrim($scriptDir, '/') . '/';
        }
    }
    
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $basePath,
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('refer_session');
    ini_set('session.use_strict_mode', '1');
    session_start();
}

// Set default timezone
date_default_timezone_set('Asia/Bangkok');

// Handle API routes
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Handle base path for subdirectory installations (e.g., /refer-system/)
$basePath = '';
if (isset($_SERVER['SCRIPT_NAME'])) {
    $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
    if ($scriptDir !== '/' && $scriptDir !== '\\') {
        $basePath = rtrim($scriptDir, '/');
    }
}

// Remove base path from request path for routing
if ($basePath && str_starts_with($path, $basePath . '/')) {
    $path = substr($path, strlen($basePath));
} elseif ($basePath && $path === $basePath) {
    $path = '/';
}

// Expose base path so routes/web.php strips it from the request path too
$_ENV['BASE_PATH'] = $basePath;
putenv('BASE_PATH=' . $basePath);

// Handle API routes
if (str_starts_with($path, '/api/')) {
    require_once __DIR__ . '/../routes/web.php';
    exit;
}

// Serve static assets
if (str_starts_with($path, '/assets/') && !str_contains($path, '..')) {
    $file = __DIR__ . $path;
    if (!is_file($file)) {
        // Fallback: assets are authored in resources/assets when not copied to public/assets
        $file = __DIR__ . '/../resources' . $path;
    }
    if (is_file($file)) {
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        $mimeTypes = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
        ];
        header('Content-Type: ' . ($mimeTypes[$ext] ?? 'application/octet-stream'));
        header('Cache-Control: public, max-age=31536000');
        readfile($file);
        exit;
    }
}

// Serve setup-admin.php if requested directly
if ($path === '/setup-admin.php') {
    $setupFile = __DIR__ . '/setup-admin.php';
    if (file_exists($setupFile)) {
        require_once $setupFile;
        exit;
    }
}

// Page-specific styles and scripts
$pageStyles = ['dashboard', 'inspections', 'restock', 'refill-shift', 'nurses', 'history', 'vehicles', 'users', 'admin'];
$pageScripts = ['dashboard', 'inspections', 'restock', 'nurses', 'history', 'vehicles', 'users'];

// Serve the SPA for all other routes
require_once __DIR__ . '/../routes/web.php';