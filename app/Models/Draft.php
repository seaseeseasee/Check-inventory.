<?php
declare(strict_types=1);

namespace App\Models;

class Draft extends Model
{
    protected string $table = 'refer_drafts';
    protected string $primaryKey = 'id';
    protected array $fillable = ['id', 'car_id', 'nurse_id', 'car_name', 'nurse_name', 'shift_name', 'started_at'];
    protected array $casts = [];

    public function getByVehicle(string $carId): ?array
    {
        return $this->firstWhere('car_id', $carId);
    }

    public function getWithItems(string $id): ?array
    {
        $draft = $this->find($id);
        if (!$draft) return null;
        $itemModel = new DraftItem();
        $draft['results'] = $itemModel->getByDraft($id);
        return $draft;
    }
}