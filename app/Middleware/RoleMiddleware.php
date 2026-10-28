<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\Response;

class RoleMiddleware
{
    public function requireAdmin(): void
    {
        $this->requireRole(['admin']);
    }

    public function requireStaff(): void
    {
        $this->requireRole(['staff', 'admin']); // Admin can also access staff endpoints
    }

    public function requireAnyRole(array $roles): void
    {
        $this->requireRole($roles);
    }

    private function requireRole(array $allowedRoles): void
    {
        $user = $_SESSION['user'] ?? null;
        
        if (!$user) {
            Response::unauthorized('Authentication required');
        }

        $userRole = $user['role'] ?? '';
        
        if (!in_array($userRole, $allowedRoles, true)) {
            Response::forbidden('Insufficient permissions');
        }
    }

    public function getCurrentUser(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public function getCurrentUserId(): ?string
    {
        $user = $this->getCurrentUser();
        return $user['id'] ?? null;
    }

    public function getCurrentUserRole(): ?string
    {
        $user = $this->getCurrentUser();
        return $user['role'] ?? null;
    }

    public function isAdmin(): bool
    {
        $role = $this->getCurrentUserRole();
        return $role === 'admin';
    }

    public function isStaff(): bool
    {
        $role = $this->getCurrentUserRole();
        return $role === 'staff' || $role === 'admin';
    }
}