<?php
declare(strict_types=1);

namespace App\Services\Logging;

/**
 * ข้อมูลของ request ปัจจุบันที่ทุก log ใช้ร่วมกัน (คำนวณครั้งเดียวต่อ request)
 * CLI ไม่มี HTTP → ip/ua/method/path เป็น null
 */
final class RequestContext
{
    private static ?string $requestId = null;

    public static function requestId(): string
    {
        return self::$requestId ??= bin2hex(random_bytes(8));
    }

    public static function isHttp(): bool
    {
        return PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_METHOD']);
    }

    public static function ip(): ?string
    {
        if (!self::isHttp()) {
            return null;
        }
        $ip = function_exists('request_ip') ? request_ip() : (string)($_SERVER['REMOTE_ADDR'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }

    public static function userAgent(): ?string
    {
        if (!self::isHttp()) {
            return null;
        }
        return LogSanitizer::text((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 255) ?: null;
    }

    public static function method(): ?string
    {
        return self::isHttp() ? substr(strtoupper((string)$_SERVER['REQUEST_METHOD']), 0, 7) : null;
    }

    /** path ที่ router เห็น (ตัด base path + ไม่มี query string — query อาจมีคำค้นข้อมูลส่วนบุคคล) */
    public static function path(): ?string
    {
        if (!self::isHttp()) {
            return null;
        }
        $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        $base = (string)($_ENV['BASE_PATH'] ?? '');
        if ($base !== '' && str_starts_with($path, $base . '/')) {
            $path = substr($path, strlen($base));
        }
        return LogSanitizer::text($path, 255);
    }

    /** ผู้ใช้จาก session — ['id','username','role'] หรือ null */
    public static function user(): ?array
    {
        $u = $_SESSION['user'] ?? null;
        if (!is_array($u) || empty($u['id'])) {
            return null;
        }
        return [
            'id' => (string)$u['id'],
            'username' => isset($u['username']) ? (string)$u['username'] : null,
            'role' => isset($u['role']) ? (string)$u['role'] : null,
        ];
    }
}
