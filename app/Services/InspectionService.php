<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Vehicle;
use App\Models\Item;
use App\Models\Nurse;
use App\Models\Inspection;
use App\Models\InspectionItem;
use App\Models\Draft;
use App\Models\DraftItem;
use App\Models\Metadata;
use App\Models\RestockRequest;
use App\Models\RestockItem;
use App\Models\RestockAction;
use App\Config\Database;

class InspectionService
{
    private Vehicle $vehicleModel;
    private Item $itemModel;
    private Nurse $nurseModel;
    private Inspection $inspectionModel;
    private InspectionItem $inspectionItemModel;
    private Draft $draftModel;
    private DraftItem $draftItemModel;
    private Metadata $metadataModel;
    private RestockRequest $restockRequestModel;
    private RestockItem $restockItemModel;
    private RestockAction $restockActionModel;
    private StockService $stockService;

    public function __construct()
    {
        $this->vehicleModel = new Vehicle();
        $this->itemModel = new Item();
        $this->nurseModel = new Nurse();
        $this->inspectionModel = new Inspection();
        $this->inspectionItemModel = new InspectionItem();
        $this->draftModel = new Draft();
        $this->draftItemModel = new DraftItem();
        $this->metadataModel = new Metadata();
        $this->restockRequestModel = new RestockRequest();
        $this->restockItemModel = new RestockItem();
        $this->restockActionModel = new RestockAction();
        $this->stockService = new StockService();
    }

    public function getVehicles(): array
    {
        return $this->vehicleModel->all();
    }

    public function getVehicle(string $id): ?array
    {
        return $this->vehicleModel->find($id);
    }

    public function getItemsByVehicle(string $carId): array
    {
        return $this->itemModel->getActiveByVehicle($carId);
    }

    public function getAllItems(): array
    {
        return $this->itemModel->all();
    }

    public function getActiveNurses(): array
    {
        return $this->nurseModel->getActive();
    }

    public function getAllNurses(): array
    {
        return $this->nurseModel->all();
    }

    public function createDraft(array $data): array
    {
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            // Delete existing draft for this vehicle
            $existingDraft = $this->draftModel->firstWhere('car_id', $data['car_id']);
            if ($existingDraft) {
                $this->draftItemModel->query(
                    "DELETE FROM " . $this->draftItemModel->getTable() . " WHERE draft_id = ?",
                    [$existingDraft['id']]
                );
                $this->draftModel->delete($existingDraft['id']);
            }

            // Create new draft
            $draftId = $data['id'] ?? generate_id();
            $draftData = [
                'id' => $draftId,
                'car_id' => $data['car_id'],
                'nurse_id' => $data['nurse_id'],
                'car_name' => $data['car_name'],
                'nurse_name' => $data['nurse_name'],
                'shift_name' => $data['shift_name'],
                'started_at' => $data['started_at'],
            ];
            $this->draftModel->create($draftData);

            // Create draft items
            foreach ($data['results'] as $index => $item) {
                $this->draftItemModel->create([
                    'draft_id' => $draftId,
                    'item_id' => $item['id'],
                    'sort_order' => $index,
                    'name' => $item['name'],
                    'standard' => $item['standard'],
                    'expiry_text' => $item['expire'],
                    'is_battery' => $item['isBattery'] ? 1 : 0,
                    'status' => $item['status'],
                    'detail' => $item['missing'],
                    'battery_level' => $item['batteryLevel'] ?? null,
                ]);
            }

            $db->commit();
            return $this->draftModel->getWithItems($draftId) ?? $draftData;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function getDraft(string $carId): ?array
    {
        return $this->draftModel->getByVehicle($carId);
    }

    /**
     * Client-shaped checklist items stored in the vehicle's existing draft.
     */
    public function getDraftResults(string $carId): array
    {
        $draft = $this->draftModel->getByVehicle($carId);
        if (!$draft) return [];

        return array_map(function ($item) {
            return [
                'id' => $item['item_id'],
                'name' => $item['name'],
                'standard' => $item['standard'],
                'expire' => $item['expiry_text'],
                'isBattery' => (bool)$item['is_battery'],
                'status' => $item['status'],
                'missing' => $item['detail'] ?? '',
                'batteryLevel' => $item['battery_level'],
            ];
        }, $this->draftItemModel->getByDraft($draft['id']));
    }

    /**
     * Fresh unchecked checklist items built from the vehicle's active items.
     */
    public function buildResultsFromVehicle(string $carId): array
    {
        return array_map(function ($item) {
            return [
                'id' => $item['id'],
                'name' => $item['name'],
                'standard' => $item['standard'],
                'expire' => $item['expiry_text'],
                'isBattery' => (bool)$item['is_battery'],
                'status' => 'ยังไม่ตรวจ',
                'missing' => '',
                'batteryLevel' => null,
            ];
        }, $this->itemModel->getActiveByVehicle($carId));
    }

    public function updateDraft(string $carId, array $results): bool
    {
        $draft = $this->draftModel->getByVehicle($carId);
        if (!$draft) return false;

        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            // Delete old items
            $this->draftItemModel->query(
                "DELETE FROM " . $this->draftItemModel->getTable() . " WHERE draft_id = ?",
                [$draft['id']]
            );

            // Insert new items
            foreach ($results as $index => $item) {
                $this->draftItemModel->create([
                    'draft_id' => $draft['id'],
                    'item_id' => $item['id'],
                    'sort_order' => $index,
                    'name' => $item['name'],
                    'standard' => $item['standard'],
                    'expiry_text' => $item['expire'],
                    'is_battery' => $item['isBattery'] ? 1 : 0,
                    'status' => $item['status'],
                    'detail' => $item['missing'],
                    'battery_level' => $item['batteryLevel'] ?? null,
                ]);
            }

            // Update item expiry in main items table
            foreach ($results as $item) {
                $this->itemModel->updateExpiry($item['id'], $item['expire']);
            }

            $db->commit();
            return true;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function saveInspection(array $data, ?string $currentUserId = null, string $currentUserName = ''): array
    {
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            // Check if already exists
            $existing = $this->inspectionModel->find($data['id']);
            if ($existing) {
                throw new \RuntimeException('Inspection already exists');
            }

            // Create inspection
            $this->inspectionModel->create([
                'id' => $data['id'],
                'car_id' => $data['car_id'],
                'nurse_id' => $data['nurse_id'],
                'car_name' => $data['car_name'],
                'nurse_name' => $data['nurse_name'],
                'shift_name' => $data['shift_name'],
                'started_at' => $data['started_at'],
                'completed_at' => $data['completed_at'],
            ]);

            // Create inspection items
            foreach ($data['results'] as $index => $item) {
                $this->inspectionItemModel->create([
                    'check_id' => $data['id'],
                    'item_id' => $item['id'],
                    'sort_order' => $index,
                    'name' => $item['name'],
                    'standard' => $item['standard'],
                    'expiry_text' => $item['expire'],
                    'is_battery' => $item['isBattery'] ? 1 : 0,
                    'status' => $item['status'],
                    'detail' => $item['missing'],
                    'battery_level' => $item['batteryLevel'] ?? null,
                ]);
            }

            // Update item expiry in main items table
            foreach ($data['results'] as $item) {
                $this->itemModel->updateExpiry($item['id'], $item['expire']);
            }

            // Delete draft
            $draft = $this->draftModel->firstWhere('car_id', $data['car_id']);
            if ($draft) {
                $this->draftItemModel->query(
                    "DELETE FROM " . $this->draftItemModel->getTable() . " WHERE draft_id = ?",
                    [$draft['id']]
                );
                $this->draftModel->delete($draft['id']);
            }

            // Auto-create restock requests for missing items
            $this->createRestockRequestsFromInspection($data, $currentUserId);

            // Auto-deduct warehouse stock for used items ("ใช้ไป")
            $stockAlerts = $this->stockService->deductForInspection($data, $currentUserId, $currentUserName);

            $db->commit();
            $result = $this->inspectionModel->getWithItems($data['id']) ?? $data;
            $result['stock_alerts'] = $stockAlerts;
            return $result;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Automatically create restock requests from inspection results
     * Only creates requests for items that are missing/used and have quantity below standard
     */
    private function createRestockRequestsFromInspection(array $inspectionData, ?string $currentUserId = null): void
    {
        $missingItems = [];
        
        foreach ($inspectionData['results'] as $item) {
            // Check if item is missing or used (quantity below standard)
            if ($this->shouldCreateRestock($item)) {
                $missingItems[] = $item;
            }
        }

        if (empty($missingItems)) {
            return; // No restock needed
        }

        // Check if there's already an active restock request for this check
        $existingRequest = $this->restockRequestModel->getActiveByCheck($inspectionData['id']);
        if ($existingRequest) {
            // Update existing request with new missing items
            $this->updateExistingRestockRequest($existingRequest, $missingItems);
            return;
        }

        // Create new restock request
        $this->createNewRestockRequest($inspectionData, $missingItems, $currentUserId);
    }

    private function shouldCreateRestock(array $item): bool
    {
        // Item is missing or used
        if (in_array($item['status'], ['ขาด', 'ใช้ไป'], true)) {
            return true;
        }

        // For battery items, check if battery level is low
        if ($item['isBattery'] && isset($item['batteryLevel']) && $item['batteryLevel'] < 4) {
            return true;
        }

        // Check if actual quantity is less than standard
        $standard = $this->parseStandardQuantity($item['standard']);
        $actual = $this->parseActualQuantity($item);
        
        if ($standard > 0 && $actual < $standard) {
            return true;
        }

        return false;
    }

    private function parseStandardQuantity(string $standard): float
    {
        // Extract numeric value from standard (e.g., "10 ชิ้น" -> 10, "1 ชุด" -> 1)
        if (preg_match('/^(\d+(?:\.\d+)?)/', $standard, $matches)) {
            return (float)$matches[1];
        }
        return 0;
    }

    private function parseActualQuantity(array $item): float
    {
        // For items with missing/used status, get quantity from detail
        if (in_array($item['status'], ['ขาด', 'ใช้ไป'], true) && !empty($item['missing'])) {
            if (preg_match('/^(\d+(?:\.\d+)?)/', $item['missing'], $matches)) {
                return (float)$matches[1];
            }
        }
        
        // For checked items, assume standard quantity
        if ($item['status'] === 'Checked') {
            return $this->parseStandardQuantity($item['standard']);
        }

        return 0;
    }

    private function calculateQtyToRestock(array $item): float
    {
        $standard = $this->parseStandardQuantity($item['standard']);
        $actual = $this->parseActualQuantity($item);
        
        $qtyNeeded = $standard - $actual;
        return max(0, $qtyNeeded);
    }

    private function createNewRestockRequest(array $inspectionData, array $missingItems, ?string $currentUserId): void
    {
        $requestId = generate_id();
        $now = (new \DateTime())->format('Y-m-d\TH:i:s.u\Z');
        $requestNo = $this->generateRequestNo();

        // Get creator info - prefer system user, fallback to nurse
        $createdBy = $inspectionData['nurse_id'];
        $createdName = $inspectionData['nurse_name'];
        $createdByUserId = $currentUserId;

        $requestData = [
            'id' => $requestId,
            'no' => $requestNo,
            'car_id' => $inspectionData['car_id'],
            'car_name' => $inspectionData['car_name'],
            'check_id' => $inspectionData['id'],
            'created_by' => $createdBy,
            'created_name' => $createdName,
            'created_by_user_id' => $createdByUserId,
            'reason' => 'สร้างอัตโนมัติจากผลตรวจรถ: พบอุปกรณ์ขาด/ไม่ครบตามมาตรฐาน',
            'note' => '',
            'status' => RestockRequest::STATUS_PENDING,
            'created_at' => $now,
        ];

        $this->restockRequestModel->create($requestData);

        // Create restock items
        foreach ($missingItems as $index => $item) {
            $qtyToRestock = $this->calculateQtyToRestock($item);
            
            $this->restockItemModel->create([
                'request_id' => $requestId,
                'item_id' => $item['id'],
                'sort_order' => $index,
                'name' => $item['name'],
                'standard' => $item['standard'],
                'actual_qty' => (string)$this->parseActualQuantity($item),
                'qty_requested' => (string)$qtyToRestock,
                'qty_issued' => '',
                'qty_filled' => '',
            ]);
        }

        // Create initial action
        $this->restockActionModel->create([
            'request_id' => $requestId,
            'type' => 'create',
            'at' => $now,
            'by_id' => $createdByUserId ?? $createdBy,
            'by_name' => $createdByUserId ? 'System User' : $createdName,
            'note' => 'สร้างอัตโนมัติจากผลตรวจรถ',
            'action_lines' => [],
        ]);
    }

    private function updateExistingRestockRequest(array $existingRequest, array $missingItems): void
    {
        // Get existing items
        $existingItems = $this->restockItemModel->getByRequest($existingRequest['id']);
        $existingItemMap = [];
        foreach ($existingItems as $item) {
            $existingItemMap[$item['item_id']] = $item;
        }

        // Add new missing items that don't exist in the request
        foreach ($missingItems as $item) {
            if (!isset($existingItemMap[$item['id']])) {
                $qtyToRestock = $this->calculateQtyToRestock($item);
                $sortOrder = count($existingItems) + count($existingItemMap);
                
                $this->restockItemModel->create([
                    'request_id' => $existingRequest['id'],
                    'item_id' => $item['id'],
                    'sort_order' => $sortOrder,
                    'name' => $item['name'],
                    'standard' => $item['standard'],
                    'actual_qty' => (string)$this->parseActualQuantity($item),
                    'qty_requested' => (string)$qtyToRestock,
                    'qty_issued' => '',
                    'qty_filled' => '',
                ]);
            }
        }
    }

    private function generateRequestNo(): string
    {
        $now = new \DateTime();
        $prefix = 'RS-' . $now->format('Ymd');
        
        $stmt = Database::getConnection()->prepare(
            "SELECT COUNT(*) FROM {$this->restockRequestModel->getTable()} WHERE no LIKE ?"
        );
        $stmt->execute([$prefix . '%']);
        $count = (int)$stmt->fetchColumn() + 1;
        
        return $prefix . '-' . str_pad((string)$count, 4, '0', STR_PAD_LEFT);
    }

    public function getInspections(): array
    {
        return $this->inspectionModel->all(['*'], 'completed_at DESC');
    }

    public function getItemSummary(array $checkIds): array
    {
        $rows = (new InspectionItem())->getSummaryByChecks($checkIds);
        $summary = [];
        foreach ($rows as $row) {
            $summary[$row['check_id']] = [
                'item_count' => (int)$row['item_count'],
                'attention_count' => (int)$row['attention_count'],
            ];
        }
        return $summary;
    }

    public function getInspectionWithItems(string $id): ?array
    {
        return $this->inspectionModel->getWithItems($id);
    }

    public function getDashboardStats(): array
    {
        $vehicles = $this->getVehicles();
        $inspections = $this->getInspections();
        $today = (new \DateTime())->format('Y-m-d');

        $lastByCar = [];
        $draftByCar = [];
        foreach ($inspections as $inspection) {
            if (!isset($lastByCar[$inspection['car_id']])) {
                $lastByCar[$inspection['car_id']] = $inspection;
            }
        }
        foreach ($vehicles as $vehicle) {
            $draft = $this->getDraft($vehicle['id']);
            if ($draft) {
                $draftByCar[$vehicle['id']] = $draft;
            }
        }

        $checkedToday = 0;
        $attnTotal = 0;
        foreach ($vehicles as $vehicle) {
            $last = $lastByCar[$vehicle['id']] ?? null;
            if ($last) {
                $inspectionDate = (new \DateTime($last['completed_at']))->format('Y-m-d');
                if ($inspectionDate === $today) {
                    $checkedToday++;
                }
                $attnItems = array_filter($last['results'] ?? [], function($item) {
                    return in_array($item['status'], ['ขาด', 'ใช้ไป']) || 
                           ($item['is_battery'] && ($item['battery_level'] ?? 4) < 4);
                });
                $attnTotal += count($attnItems);
            }
        }

        return [
            'total_vehicles' => count($vehicles),
            'checked_today' => $checkedToday,
            'pending_drafts' => count($draftByCar),
            'attention_items' => $attnTotal,
            'vehicles' => $vehicles,
            'last_inspections' => $lastByCar,
            'drafts' => $draftByCar,
        ];
    }

    public function getChecklistConfig(): array
    {
        return [
            ['name' => 'หน้ากากอนามัย (Mask)', 'standard' => 1, 'is_battery' => false],
            ['name' => 'Tourniquet', 'standard' => 1, 'is_battery' => false],
            ['name' => 'ชามรูปไต', 'standard' => 1, 'is_battery' => false],
            ['name' => 'เครื่องวัดความดัน (ติดผนัง)', 'standard' => 1, 'is_battery' => false],
            ['name' => 'เครื่องวัดความดัน (พกพา)', 'standard' => 1, 'is_battery' => false],
            ['name' => 'Stethoscope', 'standard' => 1, 'is_battery' => false],
            ['name' => 'Laryngoscope + Blade', 'standard' => '1 ชุด', 'is_battery' => false],
            ['name' => 'Micropore', 'standard' => 1, 'is_battery' => false],
            ['name' => 'Transpore', 'standard' => 1, 'is_battery' => false],
            ['name' => 'เครื่อง Suction', 'standard' => 1, 'is_battery' => false],
            ['name' => 'Macgril forcep', 'standard' => 1, 'is_battery' => false],
            ['name' => 'เสาน้ำเกลือ', 'standard' => 1, 'is_battery' => false],
            ['name' => 'ถังขยะติดเชื้อ', 'standard' => 1, 'is_battery' => false],
            ['name' => 'ถังขยะทั่วไป', 'standard' => 1, 'is_battery' => false],
            ['name' => 'เครื่อง AED (ระบุจำนวนขีดแบต)', 'standard' => 1, 'is_battery' => true],
            ['name' => 'เครื่องวัด O2 Sat mobile', 'standard' => 1, 'is_battery' => false],
            ['name' => 'Slide Board', 'standard' => 1, 'is_battery' => false],
            ['name' => 'Alcohol jelly', 'standard' => 1, 'is_battery' => false],
            ['name' => 'ถุงมือเบอร์ M', 'standard' => 1, 'is_battery' => false],
            ['name' => 'ถุงมือเบอร์ S', 'standard' => 1, 'is_battery' => false],
            ['name' => 'Aquapak 340 ml', 'standard' => 1, 'is_battery' => false],
            ['name' => 'Lubricgel', 'standard' => 1, 'is_battery' => false],
            ['name' => 'Betadine', 'standard' => 1, 'is_battery' => false],
            ['name' => '0.9% NSS 100 cc', 'standard' => 1, 'is_battery' => false],
            ['name' => '0.9% NSS 1000 cc', 'standard' => 1, 'is_battery' => false],
            ['name' => '5% DN/2 1000 cc', 'standard' => 1, 'is_battery' => false],
            ['name' => '70% Alcohol', 'standard' => 1, 'is_battery' => false],
            ['name' => 'Set IV', 'standard' => 2, 'is_battery' => false],
            ['name' => 'Set Infusion', 'standard' => 2, 'is_battery' => false],
            ['name' => '3-way', 'standard' => 2, 'is_battery' => false],
            ['name' => 'Extension', 'standard' => 2, 'is_battery' => false],
            ['name' => 'สาย Suction No.14', 'standard' => 3, 'is_battery' => false],
            ['name' => 'Syringe ขนาด 20 ml', 'standard' => 2, 'is_battery' => false],
            ['name' => 'Syringe ขนาด 10 ml', 'standard' => 5, 'is_battery' => false],
            ['name' => 'Syringe ขนาด 5 ml', 'standard' => 5, 'is_battery' => false],
            ['name' => 'Syringe ขนาด 3 ml', 'standard' => 5, 'is_battery' => false],
            ['name' => 'เข็มเบอร์ 18', 'standard' => 5, 'is_battery' => false],
            ['name' => 'เข็มเบอร์ 21', 'standard' => 5, 'is_battery' => false],
            ['name' => 'เข็มเบอร์ 24', 'standard' => 5, 'is_battery' => false],
            ['name' => 'IV catheter No.20', 'standard' => 3, 'is_battery' => false],
            ['name' => 'IV catheter No.22', 'standard' => 3, 'is_battery' => false],
            ['name' => 'IV catheter No.24', 'standard' => 3, 'is_battery' => false],
            ['name' => 'Bacteria Filter', 'standard' => 1, 'is_battery' => false],
            ['name' => 'Endotracheal tube ID 6.5', 'standard' => 1, 'is_battery' => false],
            ['name' => 'Endotracheal tube ID 7', 'standard' => 2, 'is_battery' => false],
            ['name' => 'Endotracheal tube ID 7.5', 'standard' => 2, 'is_battery' => false],
            ['name' => 'Endotracheal tube ID 8', 'standard' => 2, 'is_battery' => false],
            ['name' => 'Stylet', 'standard' => 1, 'is_battery' => false],
            ['name' => 'O2 Mask with Bag', 'standard' => 1, 'is_battery' => false],
            ['name' => 'O2 cannula', 'standard' => 1, 'is_battery' => false],
            ['name' => 'ชุด Ambu Bag + สายต่อ O2', 'standard' => 1, 'is_battery' => false],
            ['name' => 'Oral Airway (4 ชิ้น No.3,4)', 'standard' => '1 ชุด', 'is_battery' => false],
            ['name' => 'Nasal Airway No.7.5', 'standard' => 1, 'is_battery' => false],
            ['name' => 'ถุงมือ Sterile No.6.5', 'standard' => 3, 'is_battery' => false],
            ['name' => 'ถุงมือ Sterile No.7', 'standard' => 3, 'is_battery' => false],
            ['name' => 'สำลี 5 ก้อน', 'standard' => 4, 'is_battery' => false],
            ['name' => 'Gauze Bandage', 'standard' => 2, 'is_battery' => false],
            ['name' => 'ไม้พันสำลี', 'standard' => 4, 'is_battery' => false],
            ['name' => 'ก๊อส 3x3', 'standard' => 4, 'is_battery' => false],
            ['name' => 'ไฟฉาย', 'standard' => 1, 'is_battery' => false],
            ['name' => 'เครื่องเจาะ DTX', 'standard' => 1, 'is_battery' => false],
            ['name' => 'O2 ถังใหญ่ (หน่วย PSI)', 'standard' => 2, 'is_battery' => false],
            ['name' => 'O2 ถังเล็ก (หน่วย PSI)', 'standard' => 1, 'is_battery' => false],
        ];
    }
}