<?php
declare(strict_types=1);

namespace App\Models;

class Metadata extends Model
{
    protected string $table = 'refer_metadata';
    protected string $primaryKey = 'id';
    protected array $fillable = ['id', 'payload'];
    protected array $casts = [
        'payload' => 'json',
    ];

    public function get(string $id): mixed
    {
        $row = $this->find($id);
        return $row['payload'] ?? null;
    }

    public function set(string $id, mixed $payload): bool
    {
        $data = ['id' => $id, 'payload' => $payload];
        $existing = $this->find($id);
        if ($existing) {
            return $this->update($id, ['payload' => $payload]);
        }
        return (bool)$this->create($data);
    }

    public function getSchemaVersion(): int
    {
        $schema = $this->get('schema');
        return $schema['version'] ?? 0;
    }

    public function setSchemaVersion(int $version): bool
    {
        return $this->set('schema', ['version' => $version]);
    }
}