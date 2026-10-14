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
        return $itemModel->where('car_id', $this->primaryKey);
    }

    public function getActiveItems(): array
    {
        $itemModel = new Item();
        return $itemModel->where('car_id', $this->primaryKey)
            ->where('active', 1);
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