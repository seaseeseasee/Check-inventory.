<?php
declare(strict_types=1);

namespace App\Models;

class Item extends Model
{
    protected string $table = 'refer_items';
    protected string $primaryKey = 'id';
    protected array $fillable = ['id', 'car_id', 'name', 'standard', 'target_qty', 'unit', 'current_qty', 'stock_updated_at', 'expiry_text', 'is_battery', 'active'];
    protected array $casts = [
        'is_battery' => 'boolean',
        'active' => 'boolean',
        // NULL-safe: Model::castValue() skips unset/null values, so "ยังไม่เคยตรวจนับ"
        // stays NULL instead of collapsing to 0.
        'target_qty' => 'float',
        'current_qty' => 'float',
    ];

    public function getByVehicle(string $carId): array
    {
        return $this->where('car_id', $carId);
    }

    public function getActiveByVehicle(string $carId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE car_id = ? AND active = 1 ORDER BY id");
        $stmt->execute([$carId]);
        return array_map([$this, 'cast'], $stmt->fetchAll());
    }

    /**
     * Lock one item row for the surrounding transaction.
     * Used so two operators cannot read the same onboard balance and overwrite each other.
     */
    public function lockById(string $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE {$this->primaryKey} = ? FOR UPDATE");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? $this->cast($row) : null;
    }

    /**
     * Items of one vehicle, locked in a deterministic order (id) to avoid lock-order deadlocks.
     *
     * @param string[] $ids
     */
    public function lockByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (empty($ids)) {
            return [];
        }
        sort($ids, SORT_STRING);

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->table} WHERE id IN ({$placeholders}) ORDER BY id FOR UPDATE"
        );
        $stmt->execute($ids);
        return array_map([$this, 'cast'], $stmt->fetchAll());
    }

    /**
     * ยอดคงเหลือบนรถที่ "เชื่อถือได้" เท่านั้น: ต้องมี stamp เวลาเขียนยอด (stock_updated_at)
     * คืน null เมื่อรถคันนี้ยังไม่เคยถูกตรวจนับ — ห้ามตีความเป็น 0
     * (เป็นกฎเดียวกันกับที่หน้าตรวจรถและหน้าเติมของใช้ จึงแสดงตัวเลขตรงกันทุกหน้า)
     */
    public static function countedQty(array $item): ?float
    {
        if (self::countedAt($item) === null) {
            return null;
        }
        if (!isset($item['current_qty']) || $item['current_qty'] === '' || $item['current_qty'] === null) {
            return null;
        }
        return round((float)$item['current_qty'], 2);
    }

    public static function countedAt(array $item): ?string
    {
        $at = trim((string)($item['stock_updated_at'] ?? ''));
        return $at === '' ? null : $at;
    }

    public function updateExpiry(string $id, string $expiry): bool
    {
        return $this->update($id, ['expiry_text' => $expiry]);
    }

    /**
     * Write the onboard balance of this vehicle item (NULL = never counted).
     */
    public function updateStock(string $id, ?float $currentQty, string $updatedAt): bool
    {
        return $this->update($id, [
            'current_qty' => $currentQty,
            'stock_updated_at' => $updatedAt,
        ]);
    }

    public function toggleActive(string $id): bool
    {
        $item = $this->find($id);
        if (!$item) return false;
        return $this->update($id, ['active' => !$item['active']]);
    }
}