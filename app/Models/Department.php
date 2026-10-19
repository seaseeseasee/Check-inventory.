<?php
declare(strict_types=1);

namespace App\Models;

/**
 * ทะเบียนวอร์ด/หน่วยงาน (refer_departments) — master list ของ "หน่วยงาน" ในตารางพยาบาล
 */
class Department extends Model
{
    protected string $table = 'refer_departments';
    protected string $primaryKey = 'id';
    protected array $fillable = ['id', 'name', 'notes', 'active', 'created_at', 'updated_at'];
    protected array $casts = [
        'active' => 'boolean',
    ];

    /** ล๊อคแถว master ก่อนแก้ (ใช้ทุกคำสั่งเขียนเพื่อกันแก้ชนกันสองหน้าจอ) */
    public function lockById(string $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE {$this->primaryKey} = ? FOR UPDATE");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? $this->cast($row) : null;
    }

    /** หาตามชื่อ (ไม่สนตัวพิมพ์เล็ก-ใหญ่ และเทียบค่าที่ trim แล้ว) — กันวอร์ดชื่อซ้ำแบบสะกดต่าง-case */
    public function findByName(string $name): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->table} WHERE LOWER(name) = LOWER(TRIM(?)) LIMIT 1"
        );
        $stmt->execute([$name]);
        $row = $stmt->fetch();
        return $row ? $this->cast($row) : null;
    }

    /**
     * จำนวนพยาบาลที่สังกัดวอร์ดนี้ — นับทั้งแถวที่ผูก FK และแถวเดิมที่ department_id ยัง NULL
     * (กรณีหลังต้องมี ก็ต่อเมื่อ data ขยับหลัง migration จึงต้องนับไว้เป็นเกราะกันข้อมูลหลุด)
     */
    public function countAssigned(string $id, string $name): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM refer_nurses
              WHERE department_id = ? OR (department_id IS NULL AND TRIM(department) = ?)"
        );
        $stmt->execute([$id, $name]);
        return (int)$stmt->fetchColumn();
    }
}
