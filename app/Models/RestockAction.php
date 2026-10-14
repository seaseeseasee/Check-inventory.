<?php
declare(strict_types=1);

namespace App\Models;

class RestockAction extends Model
{
    protected string $table = 'refer_restock_actions';
    protected string $primaryKey = 'request_id';
    protected array $fillable = ['request_id', 'seq', 'type', 'at', 'by_id', 'by_name', 'note', 'action_lines'];
    protected array $casts = [
        'seq' => 'integer',
        'action_lines' => 'json',
    ];

    public const TYPE_CREATE = 'create';
    public const TYPE_SUBMIT = 'submit';
    public const TYPE_ISSUE = 'issue';
    public const TYPE_FILL = 'fill';
    public const TYPE_FULFIL = 'fulfil';
    public const TYPE_CANCEL = 'cancel';

    public function getByRequest(string $requestId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE request_id = ? ORDER BY seq");
        $stmt->execute([$requestId]);
        $results = array_map([$this, 'cast'], $stmt->fetchAll());
        
        // Decode JSON action_lines
        foreach ($results as &$action) {
            if (is_string($action['action_lines'])) {
                $action['action_lines'] = json_decode($action['action_lines'], true) ?? [];
            }
        }
        return $results;
    }

    public function create(array $data): array
    {
        $data = $this->filterFillable($data);
        if (is_array($data['action_lines'] ?? null)) {
            $data['action_lines'] = json_encode($data['action_lines'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        
        // Get next sequence
        $stmt = $this->db->prepare("SELECT COALESCE(MAX(seq), 0) + 1 as next_seq FROM {$this->table} WHERE request_id = ?");
        $stmt->execute([$data['request_id']]);
        $data['seq'] = (int)$stmt->fetchColumn();
        
        $columns = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        
        $sql = "INSERT INTO {$this->table} (" . implode(', ', $columns) . ") VALUES ({$placeholders})";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_values($data));
        
        return $data;
    }
}