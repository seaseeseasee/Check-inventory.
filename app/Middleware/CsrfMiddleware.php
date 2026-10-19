<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\Response;

class CsrfMiddleware
{
    public function handle(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'PUT' || $_SERVER['REQUEST_METHOD'] === 'DELETE') {
            $token = $_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            if (!$this->verifyToken($token)) {
                Response::forbidden('Invalid CSRF token');
            }
        }
    }

    public function verifyToken(string $token): bool
    {
        return verify_csrf($token);
    }

    public function getToken(): string
    {
        return csrf_token();
    }
}