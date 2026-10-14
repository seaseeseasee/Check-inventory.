<?php
declare(strict_types=1);

namespace App\Models;

class RestockRequest extends Model
{
    protected string $table = 'refer_restock_requests';
    protected string $primaryKey = 'id';
    protected array $fillable = [
        'id', 'no', 'car_id', 'car_name', 'check_id', 
        'created_by', 'created_name', 'created_by_user_id',
        'assigned_to_user_id', 'reason', 'note', 'status', 
        'created_at', 'completed_at'
    ];
    protected array $casts = [];

    // New status constants
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';
    public const STATUS_PARTIAL = 'PARTIAL';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_CANCELLED = 'CANCELLED';

    // Legacy status constants (for backward compatibility)
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_FULFILLED = 'fulfilled';

    public const VALID_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_IN_PROGRESS,
        self::STATUS_PARTIAL,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    public function getWithDetails(string $id): ?array
    {
        $request = $this->find($id);
        if (!$request) return null;
        $request['items'] = $this->getItemsByRequest($id);
        $request['actions'] = $this->getActionsByRequest($id);
        return $request;
    }

    public function getByVehicle(string $carId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE car_id = ? ORDER BY created_at DESC");
        $stmt->execute([$carId]);
        return array_map([$this, 'cast'], $stmt->fetchAll());
    }

    public function getByStatus(string $status): array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE status = ? ORDER BY created_at DESC");
        $stmt->execute([$status]);
        return array_map([$this, 'cast'], $stmt->fetchAll());
    }

    public function getByAssignedUser(string $userId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE assigned_to_user_id = ? ORDER BY created_at DESC");
        $stmt->execute([$userId]);
        return array_map([$this, 'cast'], $stmt->fetchAll());
    }

    public function getByCreatedByUser(string $userId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE created_by_user_id = ? ORDER BY created_at DESC");
        $stmt->execute([$userId]);
        return array_map([$this, 'cast'], $stmt->fetchAll());
    }

    public function getActiveByCheck(string $checkId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE check_id = ? AND status != ? LIMIT 1");
        $stmt->execute([$checkId, self::STATUS_CANCELLED]);
        $result = $stmt->fetch();
        return $result ? $this->cast($result) : null;
    }

    public function getAllWithDetails(): array
    {
        $stmt = $this->db->query("SELECT * FROM {$this->table} ORDER BY created_at DESC");
        $requests = array_map([$this, 'cast'], $stmt->fetchAll());
        
        foreach ($requests as &$req) {
            $req['items'] = $this->getItemsByRequest($req['id']);
            $req['actions'] = $this->getActionsByRequest($req['id']);
        }
        return $requests;
    }

    public function getAllWithDetailsForStaff(string $userId = null): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE status != ?";
        $params = [self::STATUS_CANCELLED];
        
        if ($userId) {
            $sql .= " AND (assigned_to_user_id = ? OR assigned_to_user_id IS NULL)";
            $params[] = $userId;
        }
        
        $sql .= " ORDER BY 
            CASE status 
                WHEN 'PENDING' THEN 1 
                WHEN 'IN_PROGRESS' THEN 2 
                WHEN 'PARTIAL' THEN 3 
                WHEN 'COMPLETED' THEN 4 
                ELSE 5 
            END,
            created_at ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $requests = array_map([$this, 'cast'], $stmt->fetchAll());
        
        foreach ($requests as &$req) {
            $req['items'] = $this->getItemsByRequest($req['id']);
            $req['actions'] = $this->getActionsByRequest($req['id']);
        }
        return $requests;
    }

    public function getAllWithDetailsForAdmin(): array
    {
        $stmt = $this->db->query("SELECT * FROM {$this->table} ORDER BY created_at DESC");
        $requests = array_map([$this, 'cast'], $stmt->fetchAll());
        
        foreach ($requests as &$req) {
            $req['items'] = $this->getItemsByRequest($req['id']);
            $req['actions'] = $this->getActionsByRequest($req['id']);
        }
        return $requests;
    }

    public function getPendingCount(): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM {$this->table} WHERE status = ?");
        $stmt->execute([self::STATUS_PENDING]);
        return (int)$stmt->fetchColumn();
    }

    public function getInProgressCount(): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM {$this->table} WHERE status = ?");
        $stmt->execute([self::STATUS_IN_PROGRESS]);
        return (int)$stmt->fetchColumn();
    }

    public function getPartialCount(): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM {$this->table} WHERE status = ?");
        $stmt->execute([self::STATUS_PARTIAL]);
        return (int)$stmt->fetchColumn();
    }

    public function getCompletedCount(): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM {$this->table} WHERE status = ?");
        $stmt->execute([self::STATUS_COMPLETED]);
        return (int)$stmt->fetchColumn();
    }

    private function getItemsByRequest(string $requestId): array
    {
        $itemModel = new RestockItem();
        return $itemModel->getByRequest($requestId);
    }

    private function getActionsByRequest(string $requestId): array
    {
        $actionModel = new RestockAction();
        return $actionModel->getByRequest($requestId);
    }

    public function updateStatus(string $id, string $status): bool
    {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException("Invalid status: {$status}");
        }
        $data = ['status' => $status];
        if ($status === self::STATUS_COMPLETED) {
            $data['completed_at'] = (new \DateTime())->format('Y-m-d\TH:i:s.u\Z');
        }
        return $this->update($id, $data);
    }

    public function assignToUser(string $id, string $userId): bool
    {
        return $this->update($id, ['assigned_to_user_id' => $userId]);
    }

    public function unassign(string $id): bool
    {
        return $this->update($id, ['assigned_to_user_id' => null]);
    }

    public function isValidStatusTransition(string $fromStatus, string $toStatus): bool
    {
        $transitions = [
            self::STATUS_PENDING => [self::STATUS_IN_PROGRESS, self::STATUS_CANCELLED],
            self::STATUS_IN_PROGRESS => [self::STATUS_PARTIAL, self::STATUS_COMPLETED, self::STATUS_CANCELLED],
            self::STATUS_PARTIAL => [self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED, self::STATUS_CANCELLED],
            self::STATUS_COMPLETED => [], // Cannot change from completed
            self::STATUS_CANCELLED => [], // Cannot change from cancelled
        ];

        // Allow legacy statuses to transition to new ones
        $legacyMap = [
            self::STATUS_DRAFT => self::STATUS_PENDING,
            self::STATUS_SUBMITTED => self::STATUS_PENDING,
            self::STATUS_FULFILLED => self::STATUS_COMPLETED,
        ];

        if (isset($legacyMap[$fromStatus])) {
            $fromStatus = $legacyMap[$fromStatus];
        }

        return in_array($toStatus, $transitions[$fromStatus] ?? [], true);
    }
}