<?php
declare(strict_types=1);

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);
        if ($value === false) {
            return $default;
        }
        return $value;
    }
}

function config(string $key, mixed $default = null): mixed
{
    return \App\Config\App::get($key, $default);
}

function asset(string $path): string
{
    return \App\Config\App::asset($path);
}

function url(string $path = ''): string
{
    return \App\Config\App::url($path);
}

function view(string $path, array $data = []): void
{
    extract($data);
    $file = __DIR__ . "/../../resources/views/{$path}.php";
    if (!file_exists($file)) {
        throw new \RuntimeException("View not found: {$path}");
    }
    include $file;
}

function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function error_response(string $message, int $status = 400): void
{
    json_response(['error' => $message], $status);
}

function success_response(array $data = [], int $status = 200): void
{
    json_response(['ok' => true, 'data' => $data], $status);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(string $token): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function escape(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path, int $status = 302): void
{
    header("Location: " . url($path), true, $status);
    exit;
}

function old(string $key, mixed $default = ''): mixed
{
    return $_SESSION['old'][$key] ?? $default;
}

function flash(string $key, string $message): void
{
    $_SESSION['flash'][$key] = $message;
}

function get_flash(string $key): ?string
{
    $msg = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return $msg;
}

function is_ajax(): bool
{
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function request_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function sanitize_filename(string $filename): string
{
    return preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
}

function generate_id(): string
{
    return bin2hex(random_bytes(16));
}

function parse_datetime(string $datetime): ?DateTime
{
    try {
        return new DateTime($datetime);
    } catch (\Exception) {
        return null;
    }
}

function format_datetime(?DateTime $date, string $format = 'd/m/Y H:i:s'): string
{
    if (!$date) return '-';
    return $date->format($format);
}

function format_date_thai(?DateTime $date): string
{
    if (!$date) return '-';
    $thaiMonths = [
        1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน',
        5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม',
        9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม'
    ];
    $year = (int)$date->format('Y') + 543;
    return $date->format('d') . ' ' . $thaiMonths[(int)$date->format('m')] . ' ' . $year;
}