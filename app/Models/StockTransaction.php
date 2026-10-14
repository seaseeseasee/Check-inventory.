<?php
declare(strict_types=1);

namespace App\Models;

class StockTransaction extends Model
{
    protected string $table = 'refer_stock_transactions';
    protected string $primaryKey = 'id';
    protected array $fillable = [
        'id', 'stock_item_id', 'name', 'type', 'qty', 'balance_after',
        'car_id', 'car_name', 'check_id', 'by_user_id', 'by_name', 'note', 'at',
    ];
    protected array $casts = [
        'qty' => 'float',
        'balance_after' => 'float',
    ];

    public function getRecent(int $limit = 200, ?string $stockItemId = null): array
    {
        $limit = max(1, min($limit, 500));

        if ($stockItemId !== null && $stockItemId !== '') {
            $rows = $this->query(
                "SELECT * FROM {$this->table} WHERE stock_item_id = ? ORDER BY at DESC, id DESC LIMIT {$limit}",
                [$stockItemId]
            )->fetchAll();
        } else {
            $rows = $this->query(
                "SELECT * FROM {$this->table} ORDER BY at DESC, id DESC LIMIT {$limit}"
            )->fetchAll();
        }

        return array_map([$this, 'cast'], $rows);
    }
}
