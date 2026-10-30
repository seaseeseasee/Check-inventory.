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
        // ชื่อผู้ใช้ = เบอร์โทร: รับได้ทั้ง "081-234-5678" และ "0812345678" (บัญชีที่ไม่ใช่เบอร์ เช่น admin ใช้ตามที่พิมพ์)
        $username = trim($username);
        $phone = User::normalizePhone($username);

        // จำกัดการเดารหัสผ่าน 2 ชั้น: ต่อบัญชี (กันเดารหัสของคนใดคนหนึ่ง) + ต่อ IP (กันไล่เดาหลายบัญชี)
        // นับเฉพาะครั้งที่ผิด และ login สำเร็จล้างเฉพาะตัวนับของบัญชีนั้น — ไม่ล้างตัวนับของ IP
        // (เดิมล้างของ IP ทำให้ผู้ที่มีบัญชีเองเดา 8 ครั้ง → login บัญชีตัวเอง → เดาต่อได้ไม่จำกัด)
        $ipKey = $this->ipKey();
        $accountKey = $this->accountKey($phone ?? $username);
        $this->assertNotThrottled($ipKey, $accountKey);

        $user = $this->userModel->findByUsername($username);
        if (!$user && $phone !== null && $phone !== $username) {
            $user = $this->userModel->findByUsername($phone);
        }

        // ตรวจรหัสผ่านก่อนบอกสถานะบัญชี — ไม่ให้คนที่ไม่รู้รหัสรู้ว่าบัญชีถูกปิดอยู่
        if (!$user || !$this->userModel->verifyPassword($password, (string)$user['password_hash'])) {
            $this->recordFailure($ipKey, $accountKey);
            Response::unauthorized('Invalid credentials');
        }

        if (!$user['active']) {
            Response::forbidden('Account is deactivated');
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
        $_SESSION['last_seen'] = time();
        // ลายนิ้วมือของรหัสผ่าน ณ ตอน login — รหัสถูกเปลี่ยน/รีเซ็ตเมื่อใด session เก่าทุกอันหลุด (ดู checkAuth)
        $_SESSION['pw_fp'] = self::passwordFingerprint((string)$user['password_hash']);

        // Update last login
        $this->userModel->updateLastLogin($user['id']);

        $this->loginAttemptModel->reset($accountKey);

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
        $ipKey = $this->ipKey();
        $accountKey = $this->accountKey((string)$user['username']);
        $this->assertNotThrottled($ipKey, $accountKey, 'ลองผิดหลายครั้งเกินไป กรุณารอสักครู่แล้วลองใหม่');
        if (!$this->userModel->verifyPassword($current, (string)$user['password_hash'])) {
            $this->recordFailure($ipKey, $accountKey);
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
        $this->loginAttemptModel->reset($accountKey);

        session_regenerate_id(true);
        $_SESSION['user']['must_change_password'] = false;
        // session นี้ใช้ต่อได้ — session อื่นของบัญชีนี้ (ลายนิ้วมือรหัสเก่า) จะหลุดเองใน request ถัดไป
        $updated = $this->userModel->find((string)$user['id']);
        $_SESSION['pw_fp'] = self::passwordFingerprint((string)($updated['password_hash'] ?? ''));

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
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        if (!headers_sent()) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 3600,
                'path' => $params['path'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'],
            ]);
        }
        session_destroy();
    }

    public function checkAuth(): bool
    {
        if (empty($_SESSION['user'])) {
            return false;
        }
        $now = time();
        if (($_SESSION['until'] ?? 0) < $now) {
            $this->logout();
            return false;
        }
        // ไม่มีการใช้งานนานเกินกำหนด (ค่าเริ่มต้น 120 นาที — เวรยาวจึงไม่ตั้งสั้น)
        $idle = max(5, (int)(getenv('SESSION_IDLE_MINUTES') ?: 120)) * 60;
        if (($_SESSION['last_seen'] ?? $now) < $now - $idle) {
            $this->logout();
            return false;
        }

        // สิทธิ์ใน session เป็นแค่สำเนา — อ่านสถานะจริงจากฐานข้อมูลทุก request
        // ปิดใช้งาน / ลดสิทธิ์ / ยกเลิกผูกพยาบาล / เปลี่ยนหรือรีเซ็ตรหัสผ่าน มีผลทันที ไม่ต้องรอ session หมดอายุ
        $user = $this->userModel->find((string)($_SESSION['user']['id'] ?? ''));
        if (!$user || !$user['active']
            || !hash_equals((string)($_SESSION['pw_fp'] ?? ''), self::passwordFingerprint((string)$user['password_hash']))) {
            $this->logout();
            return false;
        }
        $_SESSION['user']['role'] = $user['role'];
        $_SESSION['user']['nurse_id'] = $user['nurse_id'];
        $_SESSION['user']['full_name'] = $user['full_name'];
        $_SESSION['user']['username'] = $user['username'];
        if (!empty($user['must_change_password'])) {
            $_SESSION['user']['must_change_password'] = true;
        }
        $_SESSION['last_seen'] = $now;
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
            // Same-origin กับโดเมนที่เบราว์เซอร์เปิดอยู่จริง (Host) — กันกรณี .env บน production ไม่มี/ตั้ง APP_ORIGIN ผิด
            // แล้วทุก POST ได้ 403 "Origin rejected" ทั้งที่มาจากหน้าเว็บของระบบเอง
            // ยังกัน CSRF ได้เหมือนเดิม: เว็บอื่นที่ส่งคำขอข้ามมา Origin จะเป็นโดเมนของเว็บนั้น ไม่ตรงกับ Host
            $originHost = parse_url($requestOrigin, PHP_URL_HOST);
            $originPort = parse_url($requestOrigin, PHP_URL_PORT);
            $originHostPort = $originHost ? strtolower($originHost . ($originPort ? ':' . $originPort : '')) : '';
            $requestHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
            $isSameHost = $originHostPort !== '' && $requestHost !== ''
                && in_array($requestHost, [$originHostPort, $originHostPort . ':443', $originHostPort . ':80'], true);
            if ($requestOrigin !== $expected && !$isLoopbackAlias && !$isSameHost) {
                Response::forbidden('Origin rejected');
            }
        }
    }

    /**
     * IP จริงของผู้ใช้ — ดู request_ip() (REMOTE_ADDR หรือ CF-Connecting-IP เมื่อมาจาก Cloudflare จริง)
     * ห้ามอ่าน X-Forwarded-For เอง เพราะ client ปลอมได้เมื่อเข้าถึง Apache ตรง เช่น XAMPP
     * หลัง Cloudflare ถ้าใช้ REMOTE_ADDR ตรงๆ ผู้ใช้ทุกคนจะนับรวมเป็น IP เดียว → ล็อกกันเองทั้งโรงพยาบาล (429)
     */
    private function getClientIp(): string
    {
        return request_ip();
    }

    public function hashIp(string $ip): string
    {
        return hash('sha256', $ip);
    }

    // ตัวนับการลองผิด: ต่อบัญชีเข้มกว่า ต่อ IP หลวมกว่า (พยาบาลหลายคนอาจออกเน็ตผ่าน IP เดียวกันของโรงพยาบาล)
    private const ACCOUNT_MAX_FAILURES = 10;
    private const IP_MAX_FAILURES = 30;
    private const FAILURE_WINDOW_SECONDS = 900;

    private function ipKey(): string
    {
        return hash('sha256', 'ip|' . $this->getClientIp());
    }

    private function accountKey(string $username): string
    {
        return hash('sha256', 'user|' . mb_strtolower(trim($username), 'UTF-8'));
    }

    private function assertNotThrottled(string $ipKey, string $accountKey, string $message = 'Too many attempts. Please try again later.'): void
    {
        $window = self::FAILURE_WINDOW_SECONDS;
        if ($this->loginAttemptModel->getAttempts($accountKey, $window) >= self::ACCOUNT_MAX_FAILURES
            || $this->loginAttemptModel->getAttempts($ipKey, $window) >= self::IP_MAX_FAILURES) {
            Response::tooManyRequests($message);
        }
    }

    private function recordFailure(string $ipKey, string $accountKey): void
    {
        $this->loginAttemptModel->increment($accountKey, self::FAILURE_WINDOW_SECONDS);
        $this->loginAttemptModel->increment($ipKey, self::FAILURE_WINDOW_SECONDS);
    }

    private static function passwordFingerprint(string $passwordHash): string
    {
        return hash('sha256', $passwordHash);
    }
}