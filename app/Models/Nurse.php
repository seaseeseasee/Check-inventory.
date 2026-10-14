<?php
declare(strict_types=1);

namespace App\Models;

class Nurse extends Model
{
    protected string $table = 'refer_nurses';
    protected string $primaryKey = 'id';
    protected array $fillable = ['id', 'name', 'department', 'phone', 'active', 'start_text', 'end_text', 'note'];
    protected array $casts = [
        'active' => 'boolean',
    ];

    public function getActive(): array
    {
        return $this->where('active', 1);
    }

    public function getInspections(): array
    {
        $inspectionModel = new Inspection();
        return $inspectionModel->where('nurse_id', $this->primaryKey);
    }

    public function getRestockActions(): array
    {
        $actionModel = new RestockAction();
        return $actionModel->where('by_id', $this->primaryKey);
    }
}