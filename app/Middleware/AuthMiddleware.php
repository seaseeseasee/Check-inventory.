<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Services\AuthService;
use App\Helpers\Response;

class AuthMiddleware
{
    private AuthService $authService;

    public function __construct()
    {
        $this->authService = new AuthService();
    }

    public function handle(): void
    {
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            $this->configureSession();
            session_start();
        }

        // Validate origin for non-GET requests
        $this->authService->validateOrigin();

        // Check authentication for API routes
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        if ($scriptDir !== '' && str_starts_with($path, $scriptDir . '/')) {
            $path = substr($path, strlen($scriptDir));
        }
        $isApi = str_starts_with($path, '/api/');
        
        if ($isApi) {
            $action = $_GET['action'] ?? '';
            $publicActions = ['login'];
            
            // Extract the actual endpoint from path
            $endpoint = $path;
            if (preg_match('/^\/api\/(\w+)/', $path, $matches)) {
                $endpoint = '/api/' . $matches[1];
            }
            
            // Public endpoints that don't require auth
            $publicEndpoints = [
                '/api/auth/login',
                '/api/auth/me', // session probe: reports authenticated=false when logged out
            ];
            
            $isPublic = false;
            foreach ($publicEndpoints as $public) {
                if (str_starts_with($path, $public)) {
                    $isPublic = true;
                    break;
                }
            }
            
            if (!$isPublic) {
                if (!$this->authService->checkAuth()) {
                    Response::unauthorized('Login required');
                }
                // ยังใช้รหัสผ่านเริ่มต้น/ชั่วคราว → ทำได้แค่เปลี่ยนรหัสผ่านหรือออกจากระบบ (บังคับที่ server ไม่พึ่งหน้าเว็บ)
                $allowedWhilePending = ['/api/auth/change-password', '/api/auth/logout'];
                if ($this->authService->mustChangePassword() && !in_array($path, $allowedWhilePending, true)) {
                    Response::error('กรุณาเปลี่ยนรหัสผ่านก่อนเริ่มใช้งาน', 403, ['code' => 'PASSWORD_CHANGE_REQUIRED']);
                }
            }
        }
    }

    private function configureSession(): void
    {
        $origin = $_ENV['APP_ORIGIN'] ?? 'http://localhost';
        // ปกติ public/index.php เริ่ม session ไปแล้ว — ส่วนนี้เป็นสำรองและต้องตั้งค่าเหมือนกัน
        $secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
            || parse_url($origin, PHP_URL_SCHEME) === 'https';

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('refer_session');
        ini_set('session.use_strict_mode', '1');
    }
}