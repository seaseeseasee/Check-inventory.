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

        $user = $this->userModel->findByUsername($username);
        if (!$user) {
            Response::unauthorized('Invalid credentials');
        }

        if (!$user['active']) {
            Response::forbidden('Account is deactivated');
        }

        if (!$this->userModel->verifyPassword($password, $user['password_hash'])) {
            Response::unauthorized('Invalid credentials');
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
            if ($requestOrigin !== $expected) {
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