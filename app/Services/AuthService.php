<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\LoginAttempt;
use App\Config\Database;
use App\Helpers\Response;

class AuthService
{
    private User $userModel;
    private LoginAttempt $loginAttemptModel;

    public function __construct()
    {
        $this->userModel = new User();
        $this->loginAttemptModel = new LoginAttempt();
    }

    public function login(string $username, string $password): array
    {
        $ipHash = hash('sha256', $this->getClientIp());
        $attempts = $this->loginAttemptModel->increment($ipHash);

        if ($attempts > 8) {
            Response::tooManyRequests('Too many attempts. Please try again later.');
        }

        // ชื่อผู้ใช้ = เบอร์โทร: รับได้ทั้ง "081-234-5678" และ "0812345678" (บัญชีที่ไม่ใช่เบอร์ เช่น admin ใช้ตามที่พิมพ์)
        $username = trim($username);
        $user = $this->userModel->findByUsername($username);
        $phone = User::normalizePhone($username);
        if (!$user && $phone !== null && $phone !== $username) {
            $user = $this->userModel->findByUsername($phone);
        }
        if (!$user) {
            Response::unauthorized('Invalid credentials');
        }

        if (!$user['active']) {
            Response::forbidden('Account is deactivated');
        }

        if (!$this->userModel->verifyPassword($password, $user['password_hash'])) {
            Response::unauthorized('Invalid credentials');
        }

        // เข้าสู่ระบบครั้งแรก: บัญชีถูกตั้งธงไว้ (รหัสเริ่มต้น/admin รีเซ็ต) หรือยังใช้รหัสผ่าน = ชื่อผู้ใช้ (เบอร์โทร)
        $mustChange = !empty($user['must_change_password']) || hash_equals((string)$user['username'], $password);
        if ($mustChange && empty($user['must_change_password'])) {
            $this->userModel->update($user['id'], ['must_change_password' => 1]);
        }

        // Ensure session is started
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
            // Small delay to ensure session is fully initialized
        }
        // Regenerate session ID for security
        session_regenerate_id(true);
        
        // Store user in session (without password hash)
        $sessionUser = [
            'id' => $user['id'],
            'username' => $user['username'],
            'full_name' => $user['full_name'],
            'role' => $user['role'],
            'nurse_id' => $user['nurse_id'],
            'must_change_password' => $mustChange,
        ];

        $_SESSION['user'] = $sessionUser;
        $_SESSION['until'] = time() + 8 * 3600; // 8 hours

        // Update last login
        $this->userModel->updateLastLogin($user['id']);

        // Reset login attempts on successful login
        $this->loginAttemptModel->reset($ipHash);

        return [
            'ok' => true,
            'user' => $sessionUser,
        ];
    }

    /**
     * ผู้ใช้เปลี่ยนรหัสผ่านเอง (รวมถึงตอนถูกบังคับเปลี่ยนครั้งแรก)
     * ต้องยืนยันรหัสผ่านปัจจุบันเสมอ — กันคนที่ได้ session ไปตั้งรหัสใหม่แทนเจ้าของบัญชี
     * สำเร็จ → ล้างธง must_change_password + ออก session ID ใหม่
     */
    public function changePassword(string $current, string $new, string $confirm): array
    {
        $sessionUser = $this->getCurrentUser();
        $user = $sessionUser ? $this->userModel->find((string)$sessionUser['id']) : null;
        if (!$user || !$user['active']) {
            $this->logout();
            Response::unauthorized('Login required');
        }

        // ใส่รหัสปัจจุบันผิด นับรวมกับการ login ผิด (จำกัดการเดารหัส)
        $ipHash = hash('sha256', $this->getClientIp());
        if (!$this->userModel->verifyPassword($current, (string)$user['password_hash'])) {
            if ($this->loginAttemptModel->increment($ipHash) > 8) {
                Response::tooManyRequests('ลองผิดหลายครั้งเกินไป กรุณารอสักครู่แล้วลองใหม่');
            }
            Response::validationError(['current_password' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง']);
        }

        $errors = [];
        $problems = User::passwordProblems($new, (string)$user['username']);
        if ($problems) {
            $errors['new_password'] = 'รหัสผ่านใหม่' . implode(', ', $problems);
        } elseif (hash_equals($current, $new)) {
            $errors['new_password'] = 'รหัสผ่านใหม่ต้องไม่ซ้ำกับรหัสผ่านเดิม';
        }
        if (!hash_equals($new, $confirm)) {
            $errors['confirm_password'] = 'ยืนยันรหัสผ่านไม่ตรงกับรหัสผ่านใหม่';
        }
        if ($errors) {
            Response::validationError($errors);
        }

        $now = (new \DateTime())->format('Y-m-d\TH:i:s.u\Z');
        $this->userModel->updateUser((string)$user['id'], [
            'password' => $new,
            'must_change_password' => 0,
            'password_changed_at' => $now,
        ]);
        $this->loginAttemptModel->reset($ipHash);

        session_regenerate_id(true);
        $_SESSION['user']['must_change_password'] = false;

        return ['ok' => true, 'user' => $_SESSION['user']];
    }

    /** session นี้ยังต้องเปลี่ยนรหัสผ่านก่อนใช้งานหรือไม่ */
    public function mustChangePassword(): bool
    {
        return !empty($_SESSION['user']['must_change_password']);
    }

    public function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    public function checkAuth(): bool
    {
        if (empty($_SESSION['user'])) {
            return false;
        }
        if (($_SESSION['until'] ?? 0) < time()) {
            $this->logout();
            return false;
        }
        return true;
    }

    public function getCurrentUser(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public function validateOrigin(): void
    {
        $origin = $_ENV['APP_ORIGIN'] ?? 'http://localhost';
        $host = parse_url($origin, PHP_URL_HOST);
        $secure = parse_url($origin, PHP_URL_SCHEME) === 'https';

        if (!$secure && !in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) {
            Response::serverError('HTTPS required');
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
            // Browsers send only scheme://host[:port] in Origin (never a path),
            // while APP_ORIGIN may include one (e.g. http://localhost/emer/public)
            $expected = ($secure ? 'https' : 'http') . '://' . ($host ?: 'localhost');
            $port = parse_url($origin, PHP_URL_PORT);
            if ($port) {
                $expected .= ':' . $port;
            }
            // Loopback aliases are the same machine: accept localhost/127.0.0.1/[::1]
            // interchangeably (same scheme and port) so either URL works locally
            $loopback = ['localhost', '127.0.0.1', '[::1]'];
            $isLoopbackAlias = in_array($host, $loopback, true)
                && in_array(parse_url($requestOrigin, PHP_URL_HOST), $loopback, true)
                && parse_url($requestOrigin, PHP_URL_SCHEME) === ($secure ? 'https' : 'http')
                && parse_url($requestOrigin, PHP_URL_PORT) === $port;
            if ($requestOrigin !== $expected && !$isLoopbackAlias) {
                Response::forbidden('Origin rejected');
            }
        }
    }

    private function getClientIp(): string
    {
        return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    public function hashIp(string $ip): string
    {
        return hash('sha256', $ip);
    }
}