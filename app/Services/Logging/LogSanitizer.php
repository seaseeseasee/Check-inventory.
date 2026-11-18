<?php
declare(strict_types=1);

namespace App\Services\Logging;

/**
 * ทำความสะอาดข้อมูลก่อนลง log
 *   - ตัด secret: ทุก key ที่ดูเหมือนรหัสผ่าน/token/session/cookie → [REDACTED] (ค่า bool/null คงไว้ เช่น must_change_password)
 *   - กัน log injection: ตัด CR/LF/control chars, บังคับ UTF-8 ที่ถูกต้อง
 *   - mask เลขบัตรประชาชน 13 หลักในข้อความ
 *   - จำกัดขนาด: ข้อความตามความยาวคอลัมน์, JSON ตามจำนวน byte
 */
final class LogSanitizer
{
    public const REDACTED = '[REDACTED]';

    private const SENSITIVE_KEY = '/pass(word)?|passwd|pwd|secret|token|session|cookie|authori[sz]ation|csrf|api[_-]?key|private[_-]?key|pw_fp/i';

    /** ค่าบอกเหตุการณ์ (ไม่ใช่ secret) ที่ระบบเดิมใส่ไว้ใต้ key "password" — ดู User::auditChanges() */
    private const SAFE_MARKERS = ['changed_by_owner', 'reset_by_admin'];

    public static function text(?string $value, int $maxChars): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = mb_scrub($value, 'UTF-8');
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        $value = self::maskNationalId(trim($value));
        if (mb_strlen($value, 'UTF-8') > $maxChars) {
            $value = mb_substr($value, 0, $maxChars - 1, 'UTF-8') . '…';
        }
        return $value;
    }

    /** ตัด secret ทั้งก้อน (recursive) */
    public static function redact(mixed $data, int $depth = 0): mixed
    {
        if (!is_array($data)) {
            return is_string($data) ? self::text($data, 2000) : $data;
        }
        if ($depth > 6) {
            return '[depth-limit]';
        }
        $out = [];
        foreach ($data as $key => $value) {
            $cleanKey = is_string($key) ? (self::text($key, 64) ?? '') : $key;
            if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key)) {
                if ($value === null || is_bool($value)
                    || (is_string($value) && in_array($value, self::SAFE_MARKERS, true))
                    || (is_array($value) && self::isBoolPair($value))) {
                    $out[$cleanKey] = $value;
                } else {
                    $out[$cleanKey] = self::REDACTED;
                }
                continue;
            }
            $out[$cleanKey] = self::redact($value, $depth + 1);
        }
        return $out;
    }

    /** redact + json_encode โดยจำกัดขนาด — เกินแล้วเก็บแค่ว่าถูกตัด (ไม่ตัดกลาง JSON) */
    public static function json(?array $data, int $maxBytes): ?string
    {
        if ($data === null) {
            return null;
        }
        $json = json_encode(self::redact($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return '{"_error":"unencodable"}';
        }
        if (strlen($json) > $maxBytes) {
            return json_encode(['_truncated' => true, '_bytes' => strlen($json), 'keys' => array_slice(array_keys($data), 0, 50)],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: null;
        }
        return $json;
    }

    public static function maskNationalId(string $value): string
    {
        // จับก้อนตัวเลข/ขีดทั้งก้อน แล้วค่อยนับว่าเป็นตัวเลข 13 หลักพอดีหรือไม่ (เขียนติดกันหรือแบบ 1-2345-67890-12-3)
        return preg_replace_callback('/(?<![0-9A-Za-z-])\d(?:[\d-]*\d)?(?![0-9A-Za-z-])/', static function (array $m): string {
            $digits = preg_replace('/\D/', '', $m[0]) ?? '';
            return strlen($digits) === 13 ? $digits[0] . str_repeat('*', 10) . substr($digits, -2) : $m[0];
        }, $value) ?? $value;
    }

    /** ชื่อผู้ใช้ที่ไม่ตรงกับบัญชีใด (อาจเป็นรหัสผ่านที่พิมพ์ผิดช่อง) — เก็บแค่รูปทรง ไม่เก็บค่าจริง */
    public static function maskUnknownUsername(string $value): string
    {
        $value = trim($value);
        $len = mb_strlen($value, 'UTF-8');
        if ($len <= 4) {
            return str_repeat('*', $len);
        }
        return mb_substr($value, 0, 2, 'UTF-8') . str_repeat('*', $len - 4) . mb_substr($value, -2, null, 'UTF-8');
    }

    private static function isBoolPair(array $value): bool
    {
        return count($value) === 2 && array_is_list($value)
            && ($value[0] === null || is_bool($value[0])) && ($value[1] === null || is_bool($value[1]));
    }
}
