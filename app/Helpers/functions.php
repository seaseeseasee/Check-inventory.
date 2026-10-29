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

/**
 * IP จริงของผู้ใช้
 * - ปกติ = REMOTE_ADDR (Docker: mod_remoteip แทนค่าให้จาก nginx แล้ว)
 * - หลัง Cloudflare: REMOTE_ADDR เป็น IP ของ Cloudflare ที่ผู้ใช้ทุกคนใช้ร่วมกัน → อ่าน CF-Connecting-IP
 *   แต่เฉพาะเมื่อคำขอมาจากช่วง IP ของ Cloudflare จริง — ต่อตรงมาที่เซิร์ฟเวอร์จะปลอม header นี้ไม่ได้
 */
function request_ip(): string
{
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $cf = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
    if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP) && is_cloudflare_ip($remote)) {
        return $cf;
    }
    return $remote !== '' ? $remote : 'unknown';
}

/** ช่วง IP ของ Cloudflare — https://www.cloudflare.com/ips/ (อัปเดตถ้า Cloudflare ประกาศช่วงใหม่) */
function is_cloudflare_ip(string $ip): bool
{
    static $ranges = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return false;
    }
    foreach ($ranges as $range) {
        [$subnet, $bits] = explode('/', $range);
        $net = inet_pton($subnet);
        if (strlen($net) !== strlen($bin)) {
            continue;
        }
        $bytes = intdiv((int)$bits, 8);
        $rem = (int)$bits % 8;
        if (substr($bin, 0, $bytes) !== substr($net, 0, $bytes)) {
            continue;
        }
        if ($rem === 0 || ((ord($bin[$bytes]) ^ ord($net[$bytes])) & (0xFF << (8 - $rem)) & 0xFF) === 0) {
            return true;
        }
    }
    return false;
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