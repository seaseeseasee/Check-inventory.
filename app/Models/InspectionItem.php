<?php
declare(strict_types=1);

namespace App\Models;

class InspectionItem extends Model
{
    protected string $table = 'refer_check_items';
    protected string $primaryKey = 'check_id';
    protected array $fillable = ['check_id', 'item_id', 'sort_order', 'name', 'standard', 'expiry_text', 'is_battery', 'status', 'detail', 'battery_level', 'qty_before', 'actual_qty', 'used_qty', 'qty_target', 'unit'];
    protected array $casts = [
        'is_battery' => 'boolean',
        'sort_order' => 'integer',
        'battery_level' => 'integer',
        // NULL-safe (see Model::castValue): "ยังไม่เคยตรวจนับ" stays NULL, never collapses to 0.
        'qty_before' => 'float',
        'actual_qty' => 'float',
        'used_qty' => 'float',
        'qty_target' => 'float',
    ];

    public function getByCheck(string $checkId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE check_id = ? ORDER BY sort_order");
        $stmt->execute([$checkId]);
        return array_map([$this, 'cast'], $stmt->fetchAll());
    }

    public function getSummaryByChecks(array $checkIds): array
    {
        if (empty($checkIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($checkIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT check_id,
                    COUNT(*) AS item_count,
                    SUM(CASE WHEN status IN ('ขาด', 'ใช้ไป')
                              OR (is_battery = 1 AND battery_level IS NOT NULL AND battery_level < 4)
                         THEN 1 ELSE 0 END) AS attention_count
             FROM {$this->table}
             WHERE check_id IN ($placeholders)
             GROUP BY check_id"
        );
        $stmt->execute(array_values($checkIds));
        return $stmt->fetchAll();
    }
}