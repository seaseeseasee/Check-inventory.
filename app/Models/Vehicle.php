<?php
declare(strict_types=1);

namespace App\Models;

class Vehicle extends Model
{
    protected string $table = 'refer_vehicles';
    protected string $primaryKey = 'id';
    protected array $fillable = ['id', 'name', 'vehicle_color', 'plate', 'brand_model', 'status', 'vehicle_type', 'home_department_id', 'odometer_initial', 'notes'];

    /** ประเภทรถ: รถ Refer (ส่งต่อผู้ป่วย) | รถ EMR ประจำวอร์ด */
    public const TYPE_REFER = 'refer';
    public const TYPE_EMR = 'emr';
    public const TYPES = [self::TYPE_REFER, self::TYPE_EMR];
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