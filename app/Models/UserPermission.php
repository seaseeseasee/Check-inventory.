<?php
declare(strict_types=1);

namespace App\Models;

/**
 * สิทธิ์ย่อยที่ซ้อนบน role (migration 017: refer_user_permissions)
 * ผู้ถือสิทธิ์ต้องเป็น role admin ด้วยเสมอ — ตรวจที่ RoleMiddleware::requirePermission()
 */
class UserPermission extends Model
{
    public const LOGS_VIEW = 'logs.view';
    public const LOGS_AUDIT_DETAIL = 'logs.audit_detail';
    public const LOGS_SECURITY = 'logs.security';
    public const LOGS_EXPORT = 'logs.export';
    public const ARCHIVES_DOWNLOAD = 'archives.download';
    public const ARCHIVES_MANAGE = 'archives.manage';

    /** ทุกสิทธิ์ที่ระบบรู้จัก + คำอธิบายภาษาไทยสำหรับหน้าจอ */
    public const ALL = [
        self::LOGS_VIEW => 'ดู Log',
        self::LOGS_AUDIT_DETAIL => 'ดูข้อมูลก่อน/หลังแก้ไข',
        self::LOGS_SECURITY => 'ดูเหตุการณ์ความปลอดภัย',
        self::LOGS_EXPORT => 'ส่งออก Log',
        self::ARCHIVES_DOWNLOAD => 'ดาวน์โหลด Archive',
        self::ARCHIVES_MANAGE => 'จัดการ Archive',
    ];

    protected string $table = 'refer_user_permissions';
    protected string $primaryKey = 'user_id';
    protected array $fillable = ['user_id', 'permission', 'granted_by', 'granted_at'];

    /** @var array<string, string[]> cache ต่อ request */
    private static array $cache = [];

    /** @return string[] */
    public function forUser(string $userId): array
    {
        if (!isset(self::$cache[$userId])) {
            $stmt = $this->db->prepare("SELECT permission FROM {$this->table} WHERE user_id = ? ORDER BY permission");
            $stmt->execute([$userId]);
            self::$cache[$userId] = array_values(array_intersect(array_keys(self::ALL), $stmt->fetchAll(\PDO::FETCH_COLUMN)));
        }
        return self::$cache[$userId];
    }

    /** @return array<string, string[]> user_id => permissions */
    public function allGrouped(): array
    {
        $out = [];
        foreach ($this->db->query("SELECT user_id, permission FROM {$this->table}")->fetchAll() as $row) {
            if (isset(self::ALL[$row['permission']])) {
                $out[(string)$row['user_id']][] = (string)$row['permission'];
            }
        }
        return $out;
    }

    /** แทนที่ชุดสิทธิ์ทั้งหมดของผู้ใช้ — คืน [ที่เพิ่ม, ที่ถอน] */
    public function replace(string $userId, array $permissions, string $grantedBy): array
    {
        $wanted = array_values(array_intersect(array_keys(self::ALL), $permissions));
        $current = $this->forUser($userId);
        $added = array_values(array_diff($wanted, $current));
        $removed = array_values(array_diff($current, $wanted));

        $now = (new \DateTime())->format('Y-m-d\TH:i:s.u\Z');
        $this->db->beginTransaction();
        try {
            if ($removed) {
                $in = implode(',', array_fill(0, count($removed), '?'));
                $this->db->prepare("DELETE FROM {$this->table} WHERE user_id = ? AND permission IN ({$in})")
                    ->execute(array_merge([$userId], $removed));
            }
            $ins = $this->db->prepare("INSERT INTO {$this->table} (user_id, permission, granted_by, granted_at) VALUES (?, ?, ?, ?)");
            foreach ($added as $perm) {
                $ins->execute([$userId, $perm, $grantedBy, $now]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
        unset(self::$cache[$userId]);
        return [$added, $removed];
    }
}
