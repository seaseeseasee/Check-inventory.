<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\Response;

class RateLimitMiddleware
{
    private array $limits = [];
    private int $window;

    public function __construct(array $limits = [], int $window = 60)
    {
        $this->limits = $limits;
        $this->window = $window;
    }

    public function handle(string $key, int $maxAttempts = 60): void
    {
        $ip = $this->getClientIp();
        $cacheKey = "ratelimit:{$key}:{$ip}";
        
        // In a real app, use Redis or database. For now, use session-based simple limiting
        $attempts = $_SESSION[$cacheKey]['count'] ?? 0;
        $windowStart = $_SESSION[$cacheKey]['window'] ?? time();
        
        if (time() - $windowStart > $this->window) {
            $attempts = 0;
            $windowStart = time();
        }
        
        if ($attempts >= $maxAttempts) {
            Response::tooManyRequests('Too many requests. Please try again later.');
        }
        
        $_SESSION[$cacheKey] = [
            'count' => $attempts + 1,
            'window' => $windowStart,
        ];
    }

    private function getClientIp(): string
    {
        return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
}