<?php
declare(strict_types=1);

namespace App\Models;

class Inspection extends Model
{
    protected string $table = 'refer_checks';
    protected string $primaryKey = 'id';
    protected array $fillable = ['id', 'car_id', 'nurse_id', 'car_name', 'nurse_name', 'check_date', 'shift_code', 'shift_name', 'started_at', 'completed_at', 'revision', 'updated_at', 'updated_by'];
    protected array $casts = [];

    public function getByVehicle(string $carId): array
    {
        return $this->where('car_id', $carId);
    }

    public function getLatestByVehicle(string $carId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE car_id = ? ORDER BY completed_at DESC LIMIT 1");
        $stmt->execute([$carId]);
        $result = $stmt->fetch();
        return $result ? $this->cast($result) : null;
    }

    public function getWithItems(string $id): ?array
    {
        $inspection = $this->find($id);
        if (!$inspection) return null;
        $itemModel = new InspectionItem();
        $inspection['results'] = $itemModel->getByCheck($id);
        return $inspection;
    }
}