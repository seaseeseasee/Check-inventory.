<?php
declare(strict_types=1);

namespace App\Models;

class RestockItem extends Model
{
    protected string $table = 'refer_restock_items';
    protected string $primaryKey = 'request_id';
    protected array $fillable = ['request_id', 'item_id', 'sort_order', 'name', 'standard', 'actual_qty', 'qty_requested', 'qty_issued', 'qty_filled'];
    protected array $casts = [
        'sort_order' => 'integer',
    ];

    public function getByRequest(string $requestId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE request_id = ? ORDER BY sort_order");
        $stmt->execute([$requestId]);
        return array_map([$this, 'cast'], $stmt->fetchAll());
    }
}