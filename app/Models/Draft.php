<?php
declare(strict_types=1);

namespace App\Models;

class Draft extends Model
{
    protected string $table = 'refer_drafts';
    protected string $primaryKey = 'id';
    protected array $fillable = ['id', 'car_id', 'nurse_id', 'car_name', 'nurse_name', 'shift_name', 'check_date', 'shift_code', 'started_at', 'payload'];
    protected array $casts = [];

    public function getByVehicle(string $carId): ?array
    {
        return $this->firstWhere('car_id', $carId);
    }

    /**
     * Lock the draft row of one vehicle (car_id is UNIQUE) for the surrounding
     * transaction, so two people editing the same draft cannot overwrite each other.
     */
    public function lockByVehicle(string $carId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE car_id = ? FOR UPDATE");
        $stmt->execute([$carId]);
        $row = $stmt->fetch();
        return $row ?: null;
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