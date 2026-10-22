<?php
declare(strict_types=1);

namespace App\Models;

use App\Config\Database;
use PDO;
use PDOStatement;

abstract class Model
{
    protected PDO $db;
    protected string $table;
    protected string $primaryKey = 'id';
    protected array $fillable = [];
    protected array $casts = [];

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function find(string $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE {$this->primaryKey} = ? LIMIT 1");
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        return $result ? $this->cast($result) : null;
    }

    public function all(array $columns = ['*'], string $orderBy = ''): array
    {
        $sql = "SELECT " . implode(', ', $columns) . " FROM {$this->table}";
        if ($orderBy) {
            $sql .= " ORDER BY {$orderBy}";
        }
        $stmt = $this->db->query($sql);
        return array_map([$this, 'cast'], $stmt->fetchAll());
    }

    public function where(string $column, mixed $value, string $operator = '='): array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE {$column} {$operator} ?");
        $stmt->execute([$value]);
        return array_map([$this, 'cast'], $stmt->fetchAll());
    }

    public function firstWhere(string $column, mixed $value, string $operator = '='): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE {$column} {$operator} ? LIMIT 1");
        $stmt->execute([$value]);
        $result = $stmt->fetch();
        return $result ? $this->cast($result) : null;
    }

    public function create(array $data): array
    {
        $data = $this->filterFillable($data);
        $columns = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        
        $sql = "INSERT INTO {$this->table} (" . implode(', ', $columns) . ") VALUES ({$placeholders})";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_values($data));
        
        $id = $this->db->lastInsertId();
        if ($id) {
            return $this->find((string)$id) ?? $data;
        }
        return $data;
    }

    public function update(string $id, array $data): bool
    {
        $data = $this->filterFillable($data);
        if (empty($data)) return false;
        
        $sets = implode(', ', array_map(fn($col) => "{$col} = ?", array_keys($data)));
        $sql = "UPDATE {$this->table} SET {$sets} WHERE {$this->primaryKey} = ?";
        $stmt = $this->db->prepare($sql);
        $params = array_values($data);
        $params[] = $id;
        return $stmt->execute($params);
    }

    public function delete(string $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM {$this->table} WHERE {$this->primaryKey} = ?");
        return $stmt->execute([$id]);
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    protected function filterFillable(array $data): array
    {
        if (!empty($this->fillable)) {
            $data = array_intersect_key($data, array_flip($this->fillable));
        }
        // PDO (emulate_prepares=false) ส่ง false เป็น '' ซึ่ง MariaDB strict ปฏิเสธสำหรับคอลัมน์ TINYINT
        // (เดิมทำให้ ลบผู้ใช้ / พักพยาบาล / พักวอร์ด / พักอุปกรณ์ ได้ 500) → แปลง bool เป็น 0/1 ที่จุดเดียว
        return array_map(fn($v) => is_bool($v) ? (int)$v : $v, $data);
    }

    protected function cast(array $row): array
    {
        foreach ($this->casts as $key => $type) {
            if (isset($row[$key])) {
                $row[$key] = $this->castValue($row[$key], $type);
            }
        }
        return $row;
    }

    private function castValue(mixed $value, string $type): mixed
    {
        return match ($type) {
            'int', 'integer' => (int)$value,
            'float', 'double' => (float)$value,
            'bool', 'boolean' => (bool)$value,
            'array', 'json' => json_decode((string)$value, true) ?? [],
            'datetime' => $value ? new \DateTime($value) : null,
            default => $value,
        };
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getPrimaryKey(): string
    {
        return $this->primaryKey;
    }
}