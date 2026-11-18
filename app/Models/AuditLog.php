<?php
declare(strict_types=1);

namespace App\Models;

use App\Services\Logging\LogService;

/**
 * บันทึกการเปลี่ยนแปลงบัญชีผู้ใช้ (database/migrations/014-audit-log.sql)
 * ผู้กระทำอ่านจาก session — เขียน log ไม่สำเร็จต้องไม่ทำให้งานหลักล้ม จึงกลืน error ไว้ใน error_log
 */
class AuditLog extends Model
{
    protected string $table = 'refer_audit_log';
    protected array $fillable = ['at', 'actor_id', 'actor_username', 'action', 'target_type', 'target_id', 'details', 'ip'];

    public static function record(string $action, string $targetType, string $targetId, array $details = []): void
    {
        try {
            $actor = $_SESSION['user'] ?? null;
            (new self())->create([
                'at' => (new \DateTime())->format('Y-m-d\TH:i:s.u\Z'),
                'actor_id' => $actor['id'] ?? null,
                'actor_username' => $actor['username'] ?? null,
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'details' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
                'ip' => request_ip(),
            ]);
        } catch (\Throwable $e) {
            error_log("AUDIT write failed ({$action} {$targetType}:{$targetId}): " . $e->getMessage());
        }

        self::forwardToCentralLog($action, $targetType, $targetId, $details);
    }

    /**
     * ส่งต่อเข้าระบบ log กลาง (refer_logs) — ตารางนี้ยังเขียนเหมือนเดิม จุดที่เรียก record() ไม่ต้องแก้
     * details เดิมมี 3 แบบ: {field: [ก่อน, หลัง]} (update), ค่าเดี่ยว ๆ (create/delete), ตัวบอกเหตุการณ์ (password)
     */
    private static function forwardToCentralLog(string $action, string $targetType, string $targetId, array $details): void
    {
        try {
            [$old, $new] = self::splitDetails($action, $details);
            LogService::audit($action, $targetType, $targetType, $targetId, $old, $new, [
                'description' => self::describe($action, $targetType, array_keys($details)),
            ]);

            // เหตุการณ์ที่ต้องเห็นในหมวดความปลอดภัย/การยืนยันตัวตนด้วย
            $old ??= [];
            $new ??= [];
            if (isset($old['role'], $new['role']) && $old['role'] !== $new['role']) {
                LogService::security('security.permission_change',
                    "เปลี่ยนบทบาทผู้ใช้ {$old['role']} → {$new['role']}", 'WARNING',
                    ['module' => 'user', 'record_type' => 'user', 'record_id' => $targetId, 'status' => 'SUCCESS', 'tx_bound' => true]);
            }
            if (($details['password'] ?? null) === 'reset_by_admin') {
                LogService::auth('auth.password_reset', 'SUCCESS', [
                    'tx_bound' => true,
                    'record_type' => 'user', 'record_id' => $targetId,
                    'description' => 'ผู้ดูแลระบบตั้งรหัสผ่านชั่วคราวให้บัญชีนี้',
                ]);
            }
        } catch (\Throwable $e) {
            error_log("AUDIT forward failed ({$action} {$targetType}:{$targetId}): " . $e->getMessage());
        }
    }

    /** คำอธิบายสั้นสำหรับตาราง log เช่น "แก้ไขผู้ใช้: full_name, role" */
    public static function describe(string $action, string $targetType, array $fields): string
    {
        $verb = match (true) {
            str_ends_with($action, '.create') => 'เพิ่ม',
            str_ends_with($action, '.delete') => 'ลบ',
            str_ends_with($action, '.password_change') => 'เปลี่ยนรหัสผ่านของ',
            default => 'แก้ไข',
        };
        $noun = ['user' => 'ผู้ใช้', 'nurse' => 'พยาบาล'][$targetType] ?? $targetType;
        $fields = array_values(array_filter($fields, 'is_string'));
        return $verb . $noun . ($fields && $verb === 'แก้ไข' ? ': ' . implode(', ', $fields) : '');
    }

    /**
     * แปลง details รูปแบบเดิมเป็น [old, new] (ใช้ร่วมกับ bin/logs-import-legacy-audit.php)
     * @return array{0: ?array, 1: ?array}
     */
    public static function splitDetails(string $action, array $details): array
    {
        if (str_ends_with($action, '.create')) {
            return [null, $details ?: null];
        }
        if (str_ends_with($action, '.delete')) {
            return [$details ?: null, null];
        }
        $old = [];
        $new = [];
        foreach ($details as $field => $value) {
            if (is_array($value) && array_is_list($value) && count($value) === 2) {
                [$old[$field], $new[$field]] = $value;
            } else {
                $new[$field] = $value;
            }
        }
        return [$old ?: null, $new ?: null];
    }
}
