<?php
declare(strict_types=1);

namespace App\Models;

/** Ledger ทุกการเปลี่ยนยอดบนรถ (เพิ่มอย่างเดียว ไม่แก้/ลบ) */
class StockMovement extends Model
{
    public const TYPE_INIT = 'INIT';
    public const TYPE_USE = 'USE';
    public const TYPE_REFILL = 'REFILL';
    public const TYPE_ADJUST = 'ADJUST';
    public const TYPE_REVERSAL = 'REVERSAL';
    /** ล้างสต็อกทั้งระบบ (migration 012b) */
    public const TYPE_RESET = 'RESET';

    protected string $table = 'refer_stock_movements';
    protected string $primaryKey = 'id';
    protected array $fillable = ['id', 'item_id', 'car_id', 'lot_id', 'type', 'qty', 'balance_after', 'check_id', 'batch_id', 'by_user_id', 'by_name', 'reason', 'at'];
    protected array $casts = [];

    /** ยอดสุทธิที่รอบตรวจหนึ่งทำกับแต่ละ Lot (รวม REVERSAL แล้ว) — ใช้ย้อนผลเมื่อแก้รอบตรวจ */
    public function netByLotForCheck(string $checkId): array
    {
        $stmt = $this->db->prepare(
            "SELECT item_id, lot_id, ROUND(SUM(qty), 2) AS net FROM {$this->table}
             WHERE check_id = ? AND lot_id IS NOT NULL GROUP BY item_id, lot_id HAVING net <> 0 ORDER BY item_id, lot_id"
        );
        $stmt->execute([$checkId]);
        return $stmt->fetchAll();
    }

    public function getByCar(string $carId, int $limit = 200): array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE car_id = ? ORDER BY at DESC, id DESC LIMIT " . max(1, min($limit, 1000)));
        $stmt->execute([$carId]);
        return $stmt->fetchAll();
    }

    public function getByBatch(string $batchId): array
    {
        return $this->where('batch_id', $batchId);
    }
}
