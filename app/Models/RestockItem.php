<?php
declare(strict_types=1);

namespace App\Models;

class RestockItem extends Model
{
    protected string $table = 'refer_restock_items';
    protected string $primaryKey = 'request_id';
    protected array $fillable = ['request_id', 'item_id', 'sort_order', 'name', 'standard', 'actual_qty', 'qty_requested', 'qty_issued', 'qty_filled', 'vehicle_stock_before', 'vehicle_stock_after'];
    protected array $casts = [
        'sort_order' => 'integer',
        // NULL-safe: NULL vehicle balance means "ยังไม่เคยตรวจนับยอดบนรถ"
        'vehicle_stock_before' => 'float',
        'vehicle_stock_after' => 'float',
    ];

    public function getByRequest(string $requestId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE request_id = ? ORDER BY sort_order");
        $stmt->execute([$requestId]);
        return array_map([$this, 'cast'], $stmt->fetchAll());
    }

    /**
     * Lock one line of a restock request for the surrounding transaction, so two
     * operators cannot read the same qty_filled/qty_issued and both add to it.
     */
    public function lockLine(string $requestId, string $itemId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->table} WHERE request_id = ? AND item_id = ? FOR UPDATE"
        );
        $stmt->execute([$requestId, $itemId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Write the issued/filled quantities plus the vehicle-balance snapshot of one line.
     * (issued/filled columns are VARCHAR numeric text; the balance columns are DECIMAL.)
     *
     * @param float|null $stockBefore ยอดบนรถก่อนเติม (NULL = ยังไม่เคยตรวจนับ)
     * @param float|null $stockAfter  ยอดบนรถหลังเติม
     */
    public function writeQuantities(string $requestId, string $itemId, float $qtyIssued, float $qtyFilled, ?float $stockBefore, ?float $stockAfter): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE {$this->table}
                SET qty_issued = ?, qty_filled = ?, vehicle_stock_before = ?, vehicle_stock_after = ?
              WHERE request_id = ? AND item_id = ?"
        );
        return $stmt->execute([
            $this->fmtQty($qtyIssued),
            $this->fmtQty($qtyFilled),
            $stockBefore === null ? null : $this->fmtQty($stockBefore),
            $stockAfter === null ? null : $this->fmtQty($stockAfter),
            $requestId,
            $itemId,
        ]);
    }

    /**
     * ยอด "ที่ยังต้องเติม" ของรถคันนี้จากคำขอที่ยังไม่ปิด (PENDING/IN_PROGRESS/PARTIAL)
     * outstanding = Σ max(0, qty_requested − qty_filled) ต่ออุปกรณ์
     * ใช้กันสร้างรายการเติมซ้ำซ้อนเมื่อตรวจรถหลายรอบก่อนเติม (ข้อ 3.6)
     *
     * @param string[] $itemIds
     * @return array<string,array{outstanding:float,no:string,request_id:string}>
     */
    public function getOutstandingForCar(string $carId, array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter($itemIds, fn($v) => (string)$v !== '')));
        if ($carId === '' || empty($itemIds)) {
            return [];
        }

        $requestTable = (new RestockRequest())->getTable();
        $openStatuses = RestockRequest::OPEN_STATUSES;
        $statusPlaceholders = implode(',', array_fill(0, count($openStatuses), '?'));
        $itemPlaceholders = implode(',', array_fill(0, count($itemIds), '?'));

        $sql = "SELECT ri.item_id, ri.qty_requested, ri.qty_filled, rr.no, rr.id AS request_id
                  FROM {$this->table} ri
                  JOIN {$requestTable} rr ON rr.id = ri.request_id
                 WHERE rr.car_id = ?
                   AND rr.status IN ({$statusPlaceholders})
                   AND ri.item_id IN ({$itemPlaceholders})
                 ORDER BY rr.created_at ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge([$carId], $openStatuses, $itemIds));

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $itemId = (string)$row['item_id'];
            $remaining = round(max(0.0, (float)$row['qty_requested'] - (float)$row['qty_filled']), 2);
            if (!isset($out[$itemId])) {
                $out[$itemId] = ['outstanding' => 0.0, 'no' => (string)$row['no'], 'request_id' => (string)$row['request_id']];
            }
            $out[$itemId]['outstanding'] = round($out[$itemId]['outstanding'] + $remaining, 2);
        }

        return $out;
    }

    private function fmtQty(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') ?: '0';
    }
}