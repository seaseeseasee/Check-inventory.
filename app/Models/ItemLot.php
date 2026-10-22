<?php
declare(strict_types=1);

namespace App\Models;

/**
 * Lot วันหมดอายุของอุปกรณ์ที่นับเป็นจำนวน (box / piece / durable) บนรถ
 * ยอดรวมของทุก Lot = refer_items.current_qty (sync โดย VehicleStockService เท่านั้น)
 *
 * ลำดับ FEFO ที่ใช้ทุกที่: ล็อตที่ยังไม่หมดอายุ (วันใกล้สุดก่อน) → ไม่ทราบ/ไม่มีวันหมดอายุ → หมดอายุแล้ว
 * (ของหมดอายุไม่ควรถูก "ใช้" — จึงตัดเป็นลำดับท้ายเมื่อของดีไม่พอเท่านั้น)
 */
class ItemLot extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_VOID = 'void';

    protected string $table = 'refer_item_lots';
    protected string $primaryKey = 'id';
    protected array $fillable = ['id', 'item_id', 'lot_number', 'expiry_date', 'received_date', 'qty_received', 'qty_remaining', 'lot_status', 'note', 'source', 'created_at', 'created_by'];
    protected array $casts = [];

    private static function fefoOrder(): string
    {
        return "CASE WHEN expiry_date IS NULL THEN 1 WHEN expiry_date < ? THEN 2 ELSE 0 END, expiry_date, created_at, id";
    }

    /** Lot ที่ยังมีของ ของหลายรายการ (ลำดับ FEFO) */
    public function getOpenByItems(array $itemIds, string $today): array
    {
        if (empty($itemIds)) {
            return [];
        }
        $in = implode(',', array_fill(0, count($itemIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->table} WHERE item_id IN ({$in}) AND qty_remaining > 0 AND lot_status = 'active'
             ORDER BY item_id, " . self::fefoOrder()
        );
        $stmt->execute(array_merge(array_values($itemIds), [$today]));
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string)$row['item_id']][] = $row;
        }
        return $out;
    }

    /** ล็อก Lot ที่ยังมีของของอุปกรณ์ 1 รายการ เรียงแบบ FEFO */
    public function lockOpenByItem(string $itemId, string $today): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->table} WHERE item_id = ? AND qty_remaining > 0 AND lot_status = 'active'
             ORDER BY " . self::fefoOrder() . " FOR UPDATE"
        );
        $stmt->execute([$itemId, $today]);
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
