<?php
declare(strict_types=1);

namespace App\Models;

class DraftItem extends Model
{
    protected string $table = 'refer_draft_items';
    protected string $primaryKey = 'draft_id';
    protected array $fillable = ['draft_id', 'item_id', 'sort_order', 'name', 'standard', 'expiry_text', 'is_battery', 'status', 'detail', 'battery_level'];
    protected array $casts = [
        'is_battery' => 'boolean',
        'sort_order' => 'integer',
        'battery_level' => 'integer',
    ];

    public function getByDraft(string $draftId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE draft_id = ? ORDER BY sort_order");
        $stmt->execute([$draftId]);
        return array_map([$this, 'cast'], $stmt->fetchAll());
    }
}