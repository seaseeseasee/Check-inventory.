<?php
declare(strict_types=1);

namespace App\Models;

class Revision extends Model
{
    protected string $table = 'refer_revision';
    protected string $primaryKey = 'id';
    protected array $fillable = ['id', 'revision'];
    protected array $casts = [
        'revision' => 'integer',
    ];

    public function getCurrent(): int
    {
        $stmt = $this->db->query("SELECT revision FROM {$this->table} WHERE id = 1");
        $result = $stmt->fetch();
        return $result ? (int)$result['revision'] : 0;
    }

    public function lockAndGet(): int
    {
        $this->db->beginTransaction();
        $stmt = $this->db->prepare("SELECT revision FROM {$this->table} WHERE id = 1 FOR UPDATE");
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    public function increment(): int
    {
        $stmt = $this->db->exec("UPDATE {$this->table} SET revision = revision + 1 WHERE id = 1");
        return $this->getCurrent();
    }

    public function commit(): void
    {
        $this->db->commit();
    }

    public function rollback(): void
    {
        $this->db->rollBack();
    }
}