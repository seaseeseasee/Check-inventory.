<?php
declare(strict_types=1);

namespace App\Models;

/** สำเนารอบตรวจ "ก่อนถูกแก้ไข" แต่ละ revision */
class CheckHistory extends Model
{
    protected string $table = 'refer_check_history';
    protected string $primaryKey = 'check_id';
    protected array $fillable = ['check_id', 'revision', 'snapshot', 'by_name', 'at'];
    protected array $casts = [];

    public function getByCheck(string $checkId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE check_id = ? ORDER BY revision");
        $stmt->execute([$checkId]);
        return $stmt->fetchAll();
    }
}
