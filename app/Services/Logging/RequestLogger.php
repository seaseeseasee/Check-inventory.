<?php
declare(strict_types=1);

namespace App\Services\Logging;

/**
 * ตาข่ายรองรับท้าย request — Response::*() จบด้วย exit แต่ shutdown function ยังทำงาน
 * จึงเก็บเหตุการณ์จากจุดเดียวได้โดยไม่ต้องแก้ทุก controller:
 *   - PHP fatal error                       → system CRITICAL
 *   - audit ที่รอผล transaction             → เขียนพร้อมสถานะจริง
 *   - 401/403/404/429 บน /api/*             → security (ถ้าโค้ดยังไม่ได้บันทึกเหตุการณ์ auth/security เองแล้ว)
 *   - 5xx ที่ยังไม่มี system log            → system ERROR
 */
final class RequestLogger
{
    private static bool $registered = false;

    private const FATAL = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];

    private const SECURITY_BY_STATUS = [
        401 => ['security.unauthenticated', 'เรียก API โดยไม่ได้เข้าสู่ระบบ', 'WARNING'],
        403 => ['security.forbidden', 'ถูกปฏิเสธการเข้าถึง (ไม่มีสิทธิ์ / Origin หรือ CSRF ไม่ถูกต้อง)', 'WARNING'],
        404 => ['security.not_found', 'เรียก endpoint หรือข้อมูลที่ไม่มีอยู่', 'INFO'],
        429 => ['security.rate_limited', 'ถูกจำกัดจำนวนครั้ง', 'WARNING'],
    ];

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        RequestContext::requestId();
        LogService::markShutdownHooked();
        register_shutdown_function([self::class, 'finish']);
    }

    public static function finish(): void
    {
        try {
            $status = (int)(http_response_code() ?: 200);

            $err = error_get_last();
            if ($err !== null && in_array($err['type'], self::FATAL, true)) {
                $status = max($status, 500);
                LogService::system('system.fatal_error', (string)$err['message'], 'CRITICAL', [
                    'http_status' => $status,
                    'context' => ['file' => basename((string)$err['file']), 'line' => $err['line']],
                ]);
            }

            LogService::flushDeferred($status);

            $path = (string)(RequestContext::path() ?? '');
            if (!str_starts_with($path, '/api/')) {
                return;
            }

            if (isset(self::SECURITY_BY_STATUS[$status]) && !LogService::wasAuthOrSecurityLogged()) {
                // ยังใช้รหัสผ่านชั่วคราว: AuthMiddleware ตอบ 403 ทุก API จนกว่าจะเปลี่ยนรหัส — เป็นเรื่องปกติ ไม่ใช่ภัย
                if ($status === 403 && !empty($_SESSION['user']['must_change_password'])) {
                    LogService::auth('auth.password_change_required', 'FAILED', [
                        'severity' => 'INFO',
                        'http_status' => $status,
                        'description' => 'บัญชียังใช้รหัสผ่านชั่วคราว — ถูกบังคับเปลี่ยนรหัสก่อนใช้งาน',
                    ]);
                    return;
                }
                [$action, $desc, $severity] = self::SECURITY_BY_STATUS[$status];
                LogService::security($action, $desc, $severity, ['http_status' => $status]);
            }

            if ($status >= 500 && !LogService::wasSystemLogged()) {
                LogService::system('system.http_error', "คำขอจบด้วย HTTP {$status}", 'ERROR', ['http_status' => $status]);
            }
        } catch (\Throwable $e) {
            error_log('RequestLogger failed: ' . $e->getMessage());
        }
    }
}
