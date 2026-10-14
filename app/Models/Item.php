<?php
declare(strict_types=1);

namespace App\Models;

class Item extends Model
{
    protected string $table = 'refer_items';
    protected string $primaryKey = 'id';
    protected array $fillable = ['id', 'car_id', 'name', 'standard', 'expiry_text', 'is_battery', 'active'];
    protected array $casts = [
        'is_battery' => 'boolean',
        'active' => 'boolean',
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

    public function updateExpiry(string $id, string $expiry): bool
    {
        return $this->update($id, ['expiry_text' => $expiry]);
    }

    public function toggleActive(string $id): bool
    {
        $item = $this->find($id);
        if (!$item) return false;
        return $this->update($id, ['active' => !$item['active']]);
    }
}