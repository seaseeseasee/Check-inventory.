<?php
/**
 * Public Entry Point for XAMPP
 * 
 * This is the main entry point for the application.
 * It handles routing and serves the SPA.
 * Works with XAMPP without Virtual Host configuration.
 */

// เวลาไทยก่อนทำอย่างอื่นทั้งหมด (โหลด config / session / log ใช้เวลาเดียวกันทั้งระบบ)
// Docker ตั้ง date.timezone ใน php.ini ด้วย — ตรงนี้กันกรณีรันบน XAMPP ที่ php.ini เป็นค่าอื่น
date_default_timezone_set('Asia/Bangkok');

// เซิร์ฟเวอร์บางแห่งอนุญาตแค่ GET/POST → หน้าเว็บส่ง PUT/PATCH/DELETE เป็น POST + X-HTTP-Method-Override
// รับเฉพาะจาก POST จึงยังผ่านการตรวจ Origin/CSRF ของคำขอที่ไม่ใช่ GET เหมือนเดิม
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $override = strtoupper((string)($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? ''));
    if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
        $_SERVER['REQUEST_METHOD'] = $override;
    }
}

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
    // Secure cookie เมื่อเว็บเปิดผ่าน HTTPS — ดูจาก APP_ORIGIN ด้วย เพราะเมื่อมี TLS proxy ด้านหน้า
    // PHP จะไม่เห็น $_SERVER['HTTPS'] เลย (เดิมจึงไม่เคยตั้ง Secure บน production)
    $secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
        || parse_url((string)($_ENV['APP_ORIGIN'] ?? getenv('APP_ORIGIN') ?: ''), PHP_URL_SCHEME) === 'https';
    
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
        // Lax (ไม่ใช่ Strict): เปิดลิงก์ระบบจากแอปอื่น (เช่น LINE) ต้องยังเห็น session เดิม
        // ไม่งั้น PHP สร้าง session ใหม่ทับ cookie เดิม = ผู้ใช้หลุดออกจากระบบ
        // CSRF กันด้วย token + ตรวจ Origin อยู่แล้ว (CsrfMiddleware / AuthService::validateOrigin)
        'samesite' => 'Lax',
    ]);
    session_name('refer_session');
    ini_set('session.use_strict_mode', '1');
    // เก็บ session ใน storage/ (Docker เป็น volume ถาวร) แทน /tmp ของ container ที่หายทุกครั้งที่ rebuild/restart
    // และอายุไฟล์ต้องไม่สั้นกว่า session ของแอป (8 ชม. ใน AuthService) — ค่าเริ่มต้น PHP 24 นาทีทำให้หลุดกลางเวร
    $sessionDir = __DIR__ . '/../storage/sessions';
    if (!is_dir($sessionDir)) {
        @mkdir($sessionDir, 0770, true);
    }
    if (is_dir($sessionDir) && is_writable($sessionDir)) {
        ini_set('session.save_path', $sessionDir);
    }
    ini_set('session.gc_maxlifetime', (string)(8 * 3600));
    session_start();
}

// Handle API routes
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Handle base path for subdirectory installations (e.g., /refer-system/)
$basePath = '';
$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
if ($scriptName !== '') {
    $scriptDir = dirname($scriptName);
    if ($scriptDir !== '/' && $scriptDir !== '\\') {
        $basePath = rtrim($scriptDir, '/');
    }
}

// เซิร์ฟเวอร์ที่ไม่มี URL rewrite: ทุก URL ผ่านตัวสคริปต์เอง (PATH_INFO) เช่น /refull/index.php/api/auth/login
// → ตัดชื่อสคริปต์ทั้งก้อนออกแทนการตัดแค่โฟลเดอร์
if ($scriptName !== '' && ($path === $scriptName || str_starts_with($path, $scriptName . '/'))) {
    $basePath = $scriptName;
}

// APP_PATH_INFO=true: ลิงก์ที่หน้าเว็บสร้าง (API, CSS/JS) ต้องขึ้นต้นด้วยชื่อสคริปต์ด้วย
if (filter_var(getenv('APP_PATH_INFO') ?: 'false', FILTER_VALIDATE_BOOLEAN) && $scriptName !== '') {
    $_ENV['URL_BASE'] = rtrim($scriptName, '/');
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