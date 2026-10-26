<?php
declare(strict_types=1);

namespace App\Models;

/** เครื่องใช้ไฟฟ้ารายเครื่องบนรถ (ไม่มี Lot / ไม่ถูกตัดสต๊อคแบบของสิ้นเปลือง) */
class ItemUnit extends Model
{
    public const STATUS_READY = 'ready';
    public const STATUS_NOT_READY = 'not_ready';

    protected string $table = 'refer_item_units';
    protected string $primaryKey = 'id';
    protected array $fillable = ['id', 'item_id', 'label', 'status', 'battery_level', 'pressure_psi', 'note', 'active', 'created_at', 'updated_at', 'updated_by'];
    protected array $casts = [
        'active' => 'boolean',
        'battery_level' => 'integer',
    ];

    /** เครื่องที่ใช้งานอยู่ของหลายรายการ: item_id => [units] */
    public function getActiveByItems(array $itemIds): array
    {
        if (empty($itemIds)) {
            return [];
        }
        $in = implode(',', array_fill(0, count($itemIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->table} WHERE item_id IN ({$in}) AND active = 1 ORDER BY item_id, created_at, id"
        );
        $stmt->execute(array_values($itemIds));
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string)$row['item_id']][] = $this->cast($row);
        }
        return $out;
    }

    public function lockActiveByItem(string $itemId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE item_id = ? AND active = 1 ORDER BY id FOR UPDATE");
        $stmt->execute([$itemId]);
        return array_map([$this, 'cast'], $stmt->fetchAll());
    }

    public function countActive(string $itemId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM {$this->table} WHERE item_id = ? AND active = 1");
        $stmt->execute([$itemId]);
        return (int)$stmt->fetchColumn();
    }
}
