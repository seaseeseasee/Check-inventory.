<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Vehicle;
use App\Models\Nurse;
use App\Models\Item;
use App\Models\Inspection;
use App\Models\RestockRequest;
use App\Models\RestockItem;
use App\Models\RestockAction;
use App\Config\Database;
use App\Helpers\Response;

class RestockService
{
    private Vehicle $vehicleModel;
    private Nurse $nurseModel;
    private Item $itemModel;
    private Inspection $inspectionModel;
    private RestockRequest $requestModel;
    private RestockItem $itemModel2;
    private RestockAction $actionModel;

    public function __construct()
    {
        $this->vehicleModel = new Vehicle();
        $this->nurseModel = new Nurse();
        $this->itemModel = new Item();
        $this->inspectionModel = new Inspection();
        $this->requestModel = new RestockRequest();
        $this->itemModel2 = new RestockItem();
        $this->actionModel = new RestockAction();
    }

    public function getVehicles(): array
    {
        return $this->vehicleModel->all();
    }

    public function getActiveNurses(): array
    {
        return $this->nurseModel->getActive();
    }

    public function getRestockRequestsForAdmin(): array
    {
        return $this->requestModel->getAllWithDetailsForAdmin();
    }

    public function getRestockRequestsForStaff(?string $userId = null): array
    {
        return $this->requestModel->getAllWithDetailsForStaff($userId);
    }

    public function getRestockRequest(string $id): ?array
    {
        return $this->requestModel->getWithDetails($id);
    }

    public function getByVehicle(string $carId): array
    {
        return $this->requestModel->getByVehicle($carId);
    }

    public function getActiveByCheck(string $checkId): ?array
    {
        return $this->requestModel->getActiveByCheck($checkId);
    }

    public function getAssignedToUser(string $userId): array
    {
        return $this->requestModel->getByAssignedUser($userId);
    }

    public function getCreatedByUser(string $userId): array
    {
        return $this->requestModel->getByCreatedByUser($userId);
    }

    public function getStats(): array
    {
        return [
            'pending' => $this->requestModel->getPendingCount(),
            'in_progress' => $this->requestModel->getInProgressCount(),
            'partial' => $this->requestModel->getPartialCount(),
            'completed' => $this->requestModel->getCompletedCount(),
        ];
    }

    /**
     * Start restocking - change status from PENDING to IN_PROGRESS
     * Assign to current user
     */
    public function startRestock(string $id, string $userId, string $userName): ?array
    {
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $request = $this->requestModel->find($id);
            if (!$request) {
                return null;
            }

            // Validate status transition
            if (!$this->requestModel->isValidStatusTransition($request['status'], RestockRequest::STATUS_IN_PROGRESS)) {
                throw new \RuntimeException("Cannot start restock from status: {$request['status']}");
            }

            $now = (new \DateTime())->format('Y-m-d\TH:i:s.u\Z');
            
            $this->requestModel->update($id, [
                'status' => RestockRequest::STATUS_IN_PROGRESS,
                'assigned_to_user_id' => $userId,
            ]);

            $this->actionModel->create([
                'request_id' => $id,
                'type' => 'start',
                'at' => $now,
                'by_id' => $userId,
                'by_name' => $userName,
                'note' => 'เริ่มดำเนินการเติมอุปกรณ์',
                'action_lines' => [],
            ]);

            $db->commit();
            return $this->requestModel->getWithDetails($id);
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Record restock quantity - update qty_filled
     */
    public function recordRestock(string $id, string $userId, string $userName, array $items, string $note = ''): ?array
    {
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $request = $this->requestModel->find($id);
            if (!$request) {
                return null;
            }

            // Validate status - must be IN_PROGRESS or PARTIAL
            if (!in_array($request['status'], [RestockRequest::STATUS_IN_PROGRESS, RestockRequest::STATUS_PARTIAL], true)) {
                throw new \RuntimeException("Cannot record restock from status: {$request['status']}");
            }

            $now = (new \DateTime())->format('Y-m-d\TH:i:s.u\Z');
            $actionLines = [];

            foreach ($items as $item) {
                if (empty($item['qty']) || !isset($item['item_id'])) {
                    continue;
                }

                $qtyFilled = (float)$item['qty'];
                $itemId = $item['item_id'];

                $row = $this->itemModel2->query(
                    "SELECT qty_filled, qty_requested FROM " . $this->itemModel2->getTable() . " WHERE request_id = ? AND item_id = ?",
                    [$id, $itemId]
                )->fetch(\PDO::FETCH_ASSOC);

                if (!$row) {
                    continue;
                }

                $currentFilled = (float)$row['qty_filled'];
                $requested = (float)$row['qty_requested'];

                // max = จำนวนที่ต้องเติม: ห้ามเติมเกิน (เฉพาะอุปกรณ์ที่ต้องเติมจริง)
                if ($requested <= 0) {
                    continue;
                }
                $newFilled = min($currentFilled + $qtyFilled, $requested);
                $applied = $newFilled - $currentFilled;

                if ($applied <= 0) {
                    continue;
                }

                $this->itemModel2->query(
                    "UPDATE " . $this->itemModel2->getTable() . " SET qty_filled = ? WHERE request_id = ? AND item_id = ?",
                    [(string)$newFilled, $id, $itemId]
                );

                $actionLines[] = [
                    'itemId' => $itemId,
                    'qty' => (string)$applied,
                ];
            }

            if (empty($actionLines)) {
                throw new \RuntimeException('No valid items to restock');
            }

            // Determine new status
            $newStatus = $this->calculateNewStatus($id);
            
            $this->requestModel->update($id, ['status' => $newStatus]);

            $this->actionModel->create([
                'request_id' => $id,
                'type' => 'restock',
                'at' => $now,
                'by_id' => $userId,
                'by_name' => $userName,
                'note' => $note ?: 'บันทึกการเติมอุปกรณ์',
                'action_lines' => $actionLines,
            ]);

            $db->commit();
            return $this->requestModel->getWithDetails($id);
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Complete restock - mark as COMPLETED
     */
    public function completeRestock(string $id, string $userId, string $userName, string $note = ''): ?array
    {
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $request = $this->requestModel->find($id);
            if (!$request) {
                return null;
            }

            // Validate status transition
            if (!$this->requestModel->isValidStatusTransition($request['status'], RestockRequest::STATUS_COMPLETED)) {
                throw new \RuntimeException("Cannot complete restock from status: {$request['status']}");
            }

            // Verify all items are filled to at least requested quantity
            $items = $this->itemModel2->getByRequest($id);
            foreach ($items as $item) {
                $requested = (float)($item['qty_requested'] ?? 0);
                $filled = (float)($item['qty_filled'] ?? 0);
                
                if ($filled < $requested) {
                    throw new \RuntimeException("Item {$item['name']} not fully restocked (filled: $filled, requested: $requested)");
                }
            }

            $now = (new \DateTime())->format('Y-m-d\TH:i:s.u\Z');
            
            $this->requestModel->update($id, [
                'status' => RestockRequest::STATUS_COMPLETED,
            ]);

            $this->actionModel->create([
                'request_id' => $id,
                'type' => 'complete',
                'at' => $now,
                'by_id' => $userId,
                'by_name' => $userName,
                'note' => $note ?: 'เติมอุปกรณ์ครบถ้วนตามมาตรฐาน',
                'action_lines' => [],
            ]);

            $db->commit();
            return $this->requestModel->getWithDetails($id);
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Cancel restock request
     */
    public function cancelRestock(string $id, string $userId, string $userName, string $reason): ?array
    {
        if (empty(trim($reason))) {
            throw new \RuntimeException('กรุณาระบุเหตุผลการยกเลิก');
        }

        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $request = $this->requestModel->find($id);
            if (!$request) {
                return null;
            }

            // Validate status transition
            if (!$this->requestModel->isValidStatusTransition($request['status'], RestockRequest::STATUS_CANCELLED)) {
                throw new \RuntimeException("Cannot cancel restock from status: {$request['status']}");
            }

            $now = (new \DateTime())->format('Y-m-d\TH:i:s.u\Z');
            
            $this->requestModel->update($id, [
                'status' => RestockRequest::STATUS_CANCELLED,
            ]);

            $this->actionModel->create([
                'request_id' => $id,
                'type' => 'cancel',
                'at' => $now,
                'by_id' => $userId,
                'by_name' => $userName,
                'note' => $reason,
                'action_lines' => [],
            ]);

            $db->commit();
            return $this->requestModel->getWithDetails($id);
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Assign restock request to a staff member
     */
    public function assignToStaff(string $id, string $assignedUserId): ?array
    {
        $request = $this->requestModel->find($id);
        if (!$request) {
            return null;
        }

        if (!in_array($request['status'], [RestockRequest::STATUS_PENDING], true)) {
            throw new \RuntimeException("Can only assign PENDING requests");
        }

        $this->requestModel->assignToUser($id, $assignedUserId);
        return $this->requestModel->getWithDetails($id);
    }

    /**
     * Unassign restock request
     */
    public function unassign(string $id): ?array
    {
        $request = $this->requestModel->find($id);
        if (!$request) {
            return null;
        }

        $this->requestModel->unassign($id);
        return $this->requestModel->getWithDetails($id);
    }

    /**
     * Calculate new status based on filled quantities
     */
    private function calculateNewStatus(string $requestId): string
    {
        $items = $this->itemModel2->getByRequest($requestId);
        
        $allCompleted = true;
        $anyPartial = false;

        foreach ($items as $item) {
            $requested = (float)($item['qty_requested'] ?? 0);
            $filled = (float)($item['qty_filled'] ?? 0);
            
            if ($filled >= $requested && $requested > 0) {
                // This item is complete
            } elseif ($filled > 0) {
                $anyPartial = true;
                $allCompleted = false;
            } else {
                $allCompleted = false;
            }
        }

        if ($allCompleted) {
            return RestockRequest::STATUS_COMPLETED;
        } elseif ($anyPartial) {
            return RestockRequest::STATUS_PARTIAL;
        } else {
            return RestockRequest::STATUS_IN_PROGRESS;
        }
    }

    public function getExpiringItems(int $days = 60): array
    {
        $items = $this->itemModel->all();
        $expiring = [];
        $now = new \DateTime();
        
        foreach ($items as $item) {
            if (!$item['active'] || empty($item['expiry_text'])) continue;
            
            $expiry = $this->parseExpiryDate($item['expiry_text']);
            if (!$expiry) continue;
            
            $diff = (int)(($expiry->getTimestamp() - $now->getTimestamp()) / 86400);
            if ($diff < 0 || $diff <= $days) {
                $expiring[] = array_merge($item, ['days_left' => $diff]);
            }
        }
        
        usort($expiring, fn($a, $b) => $a['days_left'] <=> $b['days_left']);
        return $expiring;
    }

    private function parseExpiryDate(string $text): ?\DateTime
    {
        $text = trim($text);
        if (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{2,4})$/', $text, $m)) {
            $y = (int)$m[3];
            if ($y < 100) $y += 2500;
            if ($y > 2400) $y -= 543;
            $d = \DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $y, (int)$m[2], (int)$m[1]));
            return $d && !$d->getTimestamp() ? null : $d;
        }
        return null;
    }
}