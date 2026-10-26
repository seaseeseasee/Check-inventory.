<?php
declare(strict_types=1);

// Clear opcache to ensure fresh code execution
if (function_exists('opcache_reset')) {
    opcache_reset();
}

use App\Controllers\AuthController;
use App\Controllers\HealthController;
use App\Controllers\AdminController;
use App\Controllers\StaffController;
use App\Controllers\DashboardController;
use App\Controllers\NurseController;
use App\Controllers\VehicleController;
use App\Controllers\InspectionController;
use App\Controllers\RestockController;
use App\Controllers\HistoryController;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Helpers\Response;

// Get base path from environment (set by index.php for subdirectory installations)
$basePath = $_ENV['BASE_PATH'] ?? '';

// Get request path
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Remove base path from path for routing
if ($basePath && str_starts_with($path, $basePath)) {
    $path = substr($path, strlen($basePath));
}
if ($path === '') {
    $path = '/';
}

// Run auth middleware for all routes
$authMiddleware = new AuthMiddleware();
$authMiddleware->handle();

// Run CSRF middleware for state-changing requests
$csrfMiddleware = new CsrfMiddleware();
if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'])) {
    $csrfMiddleware->handle();
}

// Simple router
$route = match (true) {
    // Auth routes
    $path === '/api/auth/login' && $method === 'POST' => ['AuthController', 'login'],
    $path === '/api/auth/logout' && $method === 'POST' => ['AuthController', 'logout'],
    $path === '/api/auth/me' && $method === 'GET' => ['AuthController', 'me'],
    $path === '/api/auth/change-password' && $method === 'POST' => ['AuthController', 'changePassword'],

    // System routes
    $path === '/api/health' && $method === 'GET' => ['HealthController', 'health'],

    // Admin routes
    $path === '/api/admin/dashboard' && $method === 'GET' => ['AdminController', 'dashboard'],
    $path === '/api/admin/users' && $method === 'GET' => ['AdminController', 'getUsers'],
    $path === '/api/admin/users' && $method === 'POST' => ['AdminController', 'createUser'],
    preg_match('/^\/api\/admin\/users\/([^\/]+)$/', $path, $matches) && $method === 'PUT' => ['AdminController', 'updateUser', $matches[1]],
    preg_match('/^\/api\/admin\/users\/([^\/]+)$/', $path, $matches) && $method === 'DELETE' => ['AdminController', 'deleteUser', $matches[1]],

    $path === '/api/admin/vehicles' && $method === 'GET' => ['AdminController', 'getVehicles'],
    $path === '/api/admin/vehicles' && $method === 'POST' => ['AdminController', 'createVehicle'],
    preg_match('/^\/api\/admin\/vehicles\/([^\/]+)$/', $path, $matches) && $method === 'GET' => ['AdminController', 'getVehicle', $matches[1]],
    preg_match('/^\/api\/admin\/vehicles\/([^\/]+)$/', $path, $matches) && $method === 'PUT' => ['AdminController', 'updateVehicle', $matches[1]],
    preg_match('/^\/api\/admin\/vehicles\/([^\/]+)$/', $path, $matches) && $method === 'DELETE' => ['AdminController', 'deleteVehicle', $matches[1]],

    $path === '/api/admin/nurses' && $method === 'GET' => ['AdminController', 'getNurses'],
    $path === '/api/admin/nurses' && $method === 'POST' => ['AdminController', 'createNurse'],
    preg_match('/^\/api\/admin\/nurses\/([^\/]+)$/', $path, $matches) && $method === 'PUT' => ['AdminController', 'updateNurse', $matches[1]],
    preg_match('/^\/api\/admin\/nurses\/([^\/]+)$/', $path, $matches) && $method === 'DELETE' => ['AdminController', 'deleteNurse', $matches[1]],

    preg_match('/^\/api\/admin\/vehicles\/([^\/]+)\/items$/', $path, $matches) && $method === 'GET' => ['AdminController', 'getVehicleItems', $matches[1]],
    preg_match('/^\/api\/admin\/vehicles\/([^\/]+)\/items$/', $path, $matches) && $method === 'POST' => ['AdminController', 'addVehicleItem', $matches[1]],
    preg_match('/^\/api\/admin\/items\/([^\/]+)$/', $path, $matches) && $method === 'PUT' => ['AdminController', 'updateItem', $matches[1]],
    preg_match('/^\/api\/admin\/items\/([^\/]+)$/', $path, $matches) && $method === 'DELETE' => ['AdminController', 'deleteItem', $matches[1]],

    $path === '/api/admin/restock' && $method === 'GET' => ['AdminController', 'getRestockRequests'],
    $path === '/api/admin/restock/stats' && $method === 'GET' => ['AdminController', 'getRestockStats'],

    // Staff routes
    $path === '/api/staff/dashboard' && $method === 'GET' => ['StaffController', 'dashboard'],
    $path === '/api/staff/vehicles' && $method === 'GET' => ['StaffController', 'getVehicles'],
    preg_match('/^\/api\/staff\/vehicles\/([^\/]+)$/', $path, $matches) && $method === 'GET' => ['StaffController', 'getVehicle', $matches[1]],
    $path === '/api/staff/inspections/shifts' && $method === 'GET' => ['StaffController', 'getShiftStatus'],
    $path === '/api/staff/inspections/start' && $method === 'POST' => ['StaffController', 'startInspection'],
    $path === '/api/staff/inspections/draft' && $method === 'GET' => ['StaffController', 'getDraftInfo'],
    $path === '/api/staff/inspections/draft' && $method === 'PUT' => ['StaffController', 'saveDraft'],
    $path === '/api/staff/inspections/submit' && $method === 'POST' => ['StaffController', 'submitInspection'],
    $path === '/api/staff/nurses' && $method === 'GET' => ['StaffController', 'getNurses'],

    $path === '/api/staff/restock' && $method === 'GET' => ['StaffController', 'getRestockRequests'],
    $path === '/api/staff/restock/my' && $method === 'GET' => ['StaffController', 'getMyRestockRequests'],
    preg_match('/^\/api\/staff\/restock\/([^\/]+)$/', $path, $matches) && $method === 'GET' => ['StaffController', 'getRestockRequest', $matches[1]],
    preg_match('/^\/api\/staff\/restock\/([^\/]+)\/cancel$/', $path, $matches) && $method === 'POST' => ['StaffController', 'cancelRestock', $matches[1]],
    // เติมของเข้ารถโดยตรง (แทน start/restock/complete + คลังกลางที่ถูกยกเลิก)
    preg_match('/^\/api\/staff\/refill\/([^\/]+)$/', $path, $matches) && $method === 'GET' => ['StaffController', 'getRefillSheet', $matches[1]],
    preg_match('/^\/api\/staff\/refill\/([^\/]+)$/', $path, $matches) && $method === 'POST' => ['StaffController', 'refillVehicle', $matches[1]],
    // จัดการล็อต (ไม่มีการลบแถว — ยกเลิก/จำหน่ายหมดอายุเป็น movement)
    preg_match('/^\/api\/staff\/lots\/([^\/]+)$/', $path, $matches) && $method === 'PUT' => ['StaffController', 'manageLot', $matches[1], 'edit'],
    preg_match('/^\/api\/staff\/lots\/([^\/]+)\/(adjust|void|expire)$/', $path, $matches) && $method === 'POST' => ['StaffController', 'manageLot', $matches[1], $matches[2]],
    preg_match('/^\/api\/staff\/items\/([^\/]+)\/movements$/', $path, $matches) && $method === 'GET' => ['StaffController', 'getItemMovements', $matches[1]],

    $path === '/api/staff/history/inspections' && $method === 'GET' => ['StaffController', 'getInspections'],
    $path === '/api/staff/history/restock' && $method === 'GET' => ['StaffController', 'getRestockHistory'],

    // คลังสต็อกกลาง (/api/stock*) ถูกยกเลิกแล้ว — ยอดอุปกรณ์อยู่ที่รถแต่ละคัน (VehicleStockService)

    // Wards/departments (ทะเบียนวอร์ด/หน่วยงานของตารางพยาบาล)
    // อ่าน: ทุก role ที่ login แล้ว — เขียน: ตรวจ role admin ที่ DepartmentController อีกชั้น
    $path === '/api/departments' && $method === 'GET' => ['DepartmentController', 'index'],
    $path === '/api/departments' && $method === 'POST' => ['DepartmentController', 'store'],
    preg_match('/^\/api\/departments\/([^\/]+)$/', $path, $matches) && $method === 'PUT' => ['DepartmentController', 'update', $matches[1]],
    preg_match('/^\/api\/departments\/([^\/]+)$/', $path, $matches) && $method === 'DELETE' => ['DepartmentController', 'destroy', $matches[1]],

    // Legacy routes (for backward compatibility)
    $path === '/api/dashboard' && $method === 'GET' => ['DashboardController', 'index'],
    $path === '/api/dashboard/stats' && $method === 'GET' => ['DashboardController', 'stats'],

    $path === '/api/nurses' && $method === 'GET' => ['NurseController', 'index'],
    $path === '/api/nurses' && $method === 'POST' => ['NurseController', 'store'],
    preg_match('/^\/api\/nurses\/([^\/]+)$/', $path, $matches) && $method === 'PUT' => ['NurseController', 'update', $matches[1]],
    preg_match('/^\/api\/nurses\/([^\/]+)$/', $path, $matches) && $method === 'DELETE' => ['NurseController', 'destroy', $matches[1]],

    $path === '/api/vehicles' && $method === 'GET' => ['VehicleController', 'index'],
    $path === '/api/vehicles' && $method === 'POST' => ['VehicleController', 'store'],
    preg_match('/^\/api\/vehicles\/([^\/]+)$/', $path, $matches) && $method === 'GET' => ['VehicleController', 'show', $matches[1]],
    preg_match('/^\/api\/vehicles\/([^\/]+)$/', $path, $matches) && $method === 'PUT' => ['VehicleController', 'update', $matches[1]],
    preg_match('/^\/api\/vehicles\/([^\/]+)$/', $path, $matches) && $method === 'DELETE' => ['VehicleController', 'destroy', $matches[1]],
    preg_match('/^\/api\/vehicles\/([^\/]+)\/items$/', $path, $matches) && $method === 'GET' => ['VehicleController', 'show', $matches[1]],
    preg_match('/^\/api\/vehicles\/([^\/]+)\/items$/', $path, $matches) && $method === 'POST' => ['VehicleController', 'addItem', $matches[1]],
    preg_match('/^\/api\/vehicles\/([^\/]+)\/items\/copy$/', $path, $matches) && $method === 'POST' => ['VehicleController', 'copyItems', $matches[1]],
    preg_match('/^\/api\/items\/([^\/]+)$/', $path, $matches) && $method === 'PUT' => ['VehicleController', 'updateItem', $matches[1]],
    preg_match('/^\/api\/items\/([^\/]+)$/', $path, $matches) && $method === 'DELETE' => ['VehicleController', 'deleteItem', $matches[1]],

    $path === '/api/inspections' && $method === 'GET' => ['InspectionController', 'index'],
    preg_match('/^\/api\/inspections\/([^\/]+)$/', $path, $matches) && $method === 'GET' => ['InspectionController', 'show', $matches[1]],
    $path === '/api/inspections/start' && $method === 'POST' => ['StaffController', 'startInspection'],
    $path === '/api/inspections/draft' && $method === 'PUT' => ['StaffController', 'saveDraft'],
    $path === '/api/inspections/submit' && $method === 'POST' => ['StaffController', 'submitInspection'],

    $path === '/api/restock' && $method === 'GET' => ['RestockController', 'index'],
    preg_match('/^\/api\/restock\/([^\/]+)$/', $path, $matches) && $method === 'GET' => ['RestockController', 'show', $matches[1]],
    // เส้นทาง /restock/draft|submit|issue|fill|fulfil ถูกถอดออก: method ใน RestockController
    // ไม่มีอยู่แล้ว (ยิงแล้วได้ 500) และไม่มี client เรียก — ใช้เส้นทาง /api/staff/restock/* แทน
    preg_match('/^\/api\/restock\/([^\/]+)\/cancel$/', $path, $matches) && $method === 'POST' => ['RestockController', 'cancel', $matches[1]],
    preg_match('/^\/api\/vehicles\/([^\/]+)\/restock$/', $path, $matches) && $method === 'GET' => ['RestockController', 'getForVehicle', $matches[1]],
    preg_match('/^\/api\/checks\/([^\/]+)\/restock$/', $path, $matches) && $method === 'GET' => ['RestockController', 'getActiveByCheck', $matches[1]],

    $path === '/api/history/inspections' && $method === 'GET' => ['HistoryController', 'inspections'],
    preg_match('/^\/api\/history\/inspections\/([^\/]+)$/', $path, $matches) && $method === 'GET' => ['HistoryController', 'inspectionDetail', $matches[1]],
    $path === '/api/history/restock' && $method === 'GET' => ['HistoryController', 'restock'],
    $path === '/api/history/inspections/clear' && $method === 'POST' => ['HistoryController', 'clearInspections'],

    default => null,
};

if ($route === null) {
    // Check if it's a static asset
    if (str_starts_with($path, '/assets/') && !str_contains($path, '..')) {
        $file = __DIR__ . '/../public' . $path;
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
            readfile($file);
            exit;
        }
    }
    
    // For SPA - serve the app shell with all tab views
    if (!str_starts_with($path, '/api/')) {
        ob_start();
        require __DIR__ . '/../resources/views/pages/dashboard/index.php';
        $content = ob_get_clean();
        require __DIR__ . '/../resources/views/layouts/app.php';
        exit;
    }

    Response::notFound('Route not found');
}

$controllerClass = $route[0];
$action = $route[1];
$params = array_slice($route, 2);

// Route table uses short names; dynamic instantiation does not resolve "use" aliases
if (!str_contains($controllerClass, '\\')) {
    $controllerClass = 'App\\Controllers\\' . $controllerClass;
}

$controller = new $controllerClass();
try {
    call_user_func_array([$controller, $action], $params);
} catch (\App\Exceptions\ApiException $e) {
    // ข้อผิดพลาดที่ต้องระบุ HTTP status + ข้อมูลประกอบ (เช่น 409 พร้อมจำนวนประวัติที่ผูกอยู่)
    error_log('API ApiException(' . $e->getStatus() . '): ' . $e->getMessage());
    Response::error($e->getMessage(), $e->getStatus(), $e->getExtra());
} catch (\PDOException $e) {
    // PDOException สืบทอดจาก RuntimeException — ต้องจับก่อน ไม่ให้ SQLSTATE/ชื่อตาราง/คอลัมน์หลุดถึงผู้ใช้
    error_log('API PDOException: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    Response::serverError();
} catch (\RuntimeException $e) {
    // Business-rule violations (invalid status transitions, etc.) must return JSON, not a PHP fatal
    error_log('API RuntimeException: ' . $e->getMessage());
    Response::error($e->getMessage());
} catch (\Throwable $e) {
    error_log('API Error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    Response::serverError();
}