<?php
declare(strict_types=1);

namespace App\Models;

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
    }
}
