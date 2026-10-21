<?php
declare(strict_types=1);

namespace App\Models;

/**
 * Lot วันหมดอายุของอุปกรณ์สิ้นเปลือง (box / piece) บนรถ
 * ยอดรวมของทุก Lot = refer_items.current_qty (sync โดย VehicleStockService เท่านั้น)
 */
class ItemLot extends Model
{
    protected string $table = 'refer_item_lots';
    protected string $primaryKey = 'id';
    protected array $fillable = ['id', 'item_id', 'expiry_date', 'qty_received', 'qty_remaining', 'source', 'created_at', 'created_by'];
    protected array $casts = [];

    /** Lot ที่ยังมีของ ของหลายรายการ — FEFO: วันหมดอายุเร็วสุดก่อน, ไม่ทราบวันหมดอายุไว้ท้าย */
    public function getOpenByItems(array $itemIds): array
    {
        if (empty($itemIds)) {
            return [];
        }
        $in = implode(',', array_fill(0, count($itemIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->table} WHERE item_id IN ({$in}) AND qty_remaining > 0
             ORDER BY item_id, expiry_date IS NULL, expiry_date, created_at, id"
        );
        $stmt->execute(array_values($itemIds));
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string)$row['item_id']][] = $row;
        }
        return $out;
    }

    /** ล็อก Lot ที่ยังมีของของอุปกรณ์ 1 รายการ เรียงแบบ FEFO */
    public function lockOpenByItem(string $itemId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->table} WHERE item_id = ? AND qty_remaining > 0
             ORDER BY expiry_date IS NULL, expiry_date, created_at, id FOR UPDATE"
        );
        $stmt->execute([$itemId]);
        return $stmt->fetchAll();
    }

    public function lockById(string $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function sumRemaining(string $itemId): float
    {
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(qty_remaining), 0) FROM {$this->table} WHERE item_id = ?");
        $stmt->execute([$itemId]);
        return round((float)$stmt->fetchColumn(), 2);
    }

    public function setRemaining(string $id, float $qty): void
    {
        $this->query("UPDATE {$this->table} SET qty_remaining = ? WHERE id = ?", [round($qty, 2), $id]);
    }
}
