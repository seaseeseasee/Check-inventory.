<?php
declare(strict_types=1);

namespace App\Models;

class Vehicle extends Model
{
    protected string $table = 'refer_vehicles';
    protected string $primaryKey = 'id';
    protected array $fillable = ['id', 'name', 'plate', 'brand_model', 'status', 'odometer_initial', 'notes'];
    protected array $casts = [
        'odometer_initial' => 'int',
    ];

    public function getItems(): array
    {
        $itemModel = new Item();
        return $itemModel->getByVehicle($this->primaryKey);
    }

    public function getActiveItems(string $vehicleId): array
    {
        $itemModel = new Item();
        return $itemModel->getActiveByVehicle($vehicleId);
    }

    /**
     * ล๊อคแถวรถก่อนแก้ (กันสองหน้าจอแก้ทะเบียน/สถานะชนกัน)
     * ต้องเรียกภายใน transaction ที่ผู้เรียกเป็นผู้ commit/rollback เอง
     */
    public function lockById(string $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE {$this->primaryKey} = ? FOR UPDATE");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? $this->cast($row) : null;
    }

    public function getLatestInspection(): ?array
    {
        $inspectionModel = new Inspection();
        return $inspectionModel->firstWhere('car_id', $this->primaryKey, '=');
    }

    public function getInspections(): array
    {
        $inspectionModel = new Inspection();
        return $inspectionModel->where('car_id', $this->primaryKey);
    }

    public function getRestockRequests(): array
    {
        $restockModel = new RestockRequest();
        return $restockModel->where('car_id', $this->primaryKey);
    }

    public function hasActiveDraft(): bool
    {
        $draftModel = new Draft();
        return $draftModel->firstWhere('car_id', $this->primaryKey) !== null;
    }

    public function getActiveDraft(): ?array
    {
        $draftModel = new Draft();
        return $draftModel->firstWhere('car_id', $this->primaryKey);
    }
}