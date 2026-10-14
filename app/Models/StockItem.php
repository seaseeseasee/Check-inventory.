<?php
declare(strict_types=1);

namespace App\Models;

class StockItem extends Model
{
    protected string $table = 'refer_stock_items';
    protected string $primaryKey = 'id';
    protected array $fillable = [
        'id', 'name', 'unit', 'current_qty', 'min_qty', 'active', 'created_at', 'updated_at',
    ];
    protected array $casts = [
        'current_qty' => 'float',
        'min_qty' => 'float',
        'active' => 'boolean',
    ];

    public function findByName(string $name): ?array
    {
        return $this->firstWhere('name', $name);
    }

    public function getAllOrdered(): array
    {
        return $this->all(['*'], 'name ASC');
    }
}
