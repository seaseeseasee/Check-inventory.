<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Helpers\Response;

class AuthController extends Controller
{
    private AuthService $authService;

    public function __construct()
    {
        $this->authService = new AuthService();
    }

    public function login(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Method not allowed', 405);
        }

        $input = $this->getInput();
        $username = $input['username'] ?? '';
        $password = $input['password'] ?? '';

        if (!is_string($username) || $username === '') {
            Response::error('Username required');
        }

        if (!is_string($password) || $password === '') {
            Response::error('Password required');
        }

        $result = $this->authService->login($username, $password);
        $this->success($result);
    }

    public function logout(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Method not allowed', 405);
        }

        $this->authService->logout();
        $this->success(['ok' => true]);
    }

    public function me(): void
    {
        // Session probe must not 401: the login gate calls this before sign-in,
        // and browsers log HTTP 4xx responses as console errors.
        if (!$this->authService->checkAuth()) {
            $this->success(['authenticated' => false]);
            return;
        }

        $user = $this->authService->getCurrentUser();
        $this->success([
            'authenticated' => true,
            'user' => $user,
            'session_expires' => $_SESSION['until'] ?? 0,
        ]);
    }
}