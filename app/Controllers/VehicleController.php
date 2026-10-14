<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\InspectionService;
use App\Services\StockService;
use App\Config\Database;
use App\Middleware\RoleMiddleware;
use App\Models\Item;
use App\Helpers\Response;

class VehicleController extends Controller
{
    private InspectionService $inspectionService;
    private RoleMiddleware $roleMiddleware;
    private StockService $stockService;

    public function __construct()
    {
        $this->inspectionService = new InspectionService();
        $this->roleMiddleware = new RoleMiddleware();
        $this->stockService = new StockService();
    }

    public function index(): void
    {
        $vehicles = $this->inspectionService->getVehicles();
        $this->success($vehicles);
    }

    public function show(string $id): void
    {
        $vehicle = $this->inspectionService->getVehicle($id);
        if (!$vehicle) {
            $this->notFound('Vehicle not found');
        }

        $items = $this->inspectionService->getItemsByVehicle($id);
        $vehicle['items'] = $items;
        $this->success($vehicle);
    }

    public function updateItem(string $itemId): void
    {
        $input = $this->getInput();
        
        $itemModel = new \App\Models\Item();
        $existing = $itemModel->find($itemId);
        if (!$existing) {
            $this->notFound('Item not found');
        }

        $data = [];
        if (isset($input['standard'])) $data['standard'] = $input['standard'];
        if (isset($input['expiry_text'])) $data['expiry_text'] = $input['expiry_text'];
        if (isset($input['active'])) $data['active'] = $input['active'];
        if (isset($input['name'])) $data['name'] = $input['name'];

        if (empty($data)) {
            $this->error('No data to update');
        }

        $itemModel->update($itemId, $data);
        $this->success($itemModel->find($itemId));
    }

    public function addItem(string $vehicleId): void
    {
        $input = $this->getInput();
        
        $vehicle = $this->inspectionService->getVehicle($vehicleId);
        if (!$vehicle) {
            $this->notFound('Vehicle not found');
        }

        $validator = new \App\Helpers\Validator($input, [
            'name' => 'required|string|max:255',
            'standard' => 'required|string|max:100',
            'is_battery' => 'boolean',
        ]);

        if (!$validator->validate()) {
            $this->validationError($validator->errors());
        }

        $itemModel = new \App\Models\Item();
        $itemId = generate_id();
        $itemModel->create([
            'id' => $itemId,
            'car_id' => $vehicleId,
            'name' => $input['name'],
            'standard' => $input['standard'],
            'expiry_text' => '',
            'is_battery' => $input['is_battery'] ?? false,
            'active' => true,
        ]);

        // Optional: ตั้งสต็อกกลางจากฟอร์มเพิ่มอุปกรณ์ (สต็อกปัจจุบัน + ขั้นต่ำ)
        $stockInfo = null;
        $currentRaw = $input['current_stock'] ?? null;
        $minRaw = $input['min_stock'] ?? null;
        $hasCurrent = $currentRaw !== null && $currentRaw !== '';
        $hasMin = $minRaw !== null && $minRaw !== '';

        if ($hasCurrent || $hasMin) {
            if ($hasCurrent && (!is_numeric($currentRaw) || (float)$currentRaw < 0 || (float)$currentRaw > 1000000)) {
                $this->validationError(['current_stock' => 'สต็อกปัจจุบันต้องเป็นตัวเลข 0 - 1,000,000']);
            }
            if ($hasMin && (!is_numeric($minRaw) || (float)$minRaw < 0 || (float)$minRaw > 1000000)) {
                $this->validationError(['min_stock' => 'จุดเตือนขั้นต่ำต้องเป็นตัวเลข 0 - 1,000,000']);
            }

            $actor = $this->roleMiddleware->getCurrentUser();
            $vehicleLabel = trim((string)($vehicle['name'] ?? '') . ' ' . (string)($vehicle['plate'] ?? ''));

            try {
                $item = $this->stockService->setStockForItemName(
                    (string)$input['name'],
                    $hasCurrent ? (float)$currentRaw : null,
                    $hasMin ? (float)$minRaw : null,
                    $actor['id'] ?? null,
                    $actor['full_name'] ?? '',
                    $vehicleLabel !== '' ? 'รถ ' . $vehicleLabel : ''
                );
                if ($item !== null) {
                    $stockInfo = [
                        'name' => $item['name'],
                        'current_qty' => $item['current_qty'],
                        'min_qty' => $item['min_qty'],
                        'status' => $item['status'] ?? null,
                    ];
                }
            } catch (\Throwable $e) {
                // อุปกรณ์ถูกเพิ่มแล้ว — รายงานปัญหาสต็อกเป็นคำเตือนโดยไม่ทำให้คำขอล้มทั้งรายการ
                $stockInfo = ['error' => $e->getMessage()];
            }
        }

        $created = $itemModel->find($itemId) ?? [];
        $created['stock'] = $stockInfo;
        $this->success($created, 201);
    }

    /**
     * Bulk-copy equipment items onto a vehicle in one statement.
     * The client reviews/edits the staged list first; values arrive here
     * as the final payload and are inserted in a single multi-row INSERT.
     */
    public function copyItems(string $vehicleId): void
    {
        $this->roleMiddleware->requireAdmin();

        $vehicle = $this->inspectionService->getVehicle($vehicleId);
        if (!$vehicle) {
            $this->notFound('Vehicle not found');
        }

        $input = $this->getInput();
        $items = $input['items'] ?? null;

        if (!is_array($items) || empty($items)) {
            $this->validationError(['items' => 'ไม่มีรายการอุปกรณ์ที่จะคัดลอก']);
        }
        if (count($items) > 300) {
            $this->validationError(['items' => 'คัดลอกได้ครั้งละไม่เกิน 300 รายการ']);
        }

        $rows = [];
        foreach (array_values($items) as $index => $item) {
            $name = trim((string)($item['name'] ?? ''));
            $standard = trim((string)($item['standard'] ?? ''));
            $expiry = trim((string)($item['expiry_text'] ?? ''));

            if ($name === '') {
                $this->validationError(['items' => 'รายการที่ ' . ($index + 1) . ': ต้องมีชื่ออุปกรณ์']);
            }
            if ($standard === '') {
                $this->validationError(['items' => 'รายการที่ ' . ($index + 1) . ': ต้องมีจำนวนมาตรฐาน']);
            }
            if (mb_strlen($name) > 255 || mb_strlen($standard) > 100 || mb_strlen($expiry) > 100) {
                $this->validationError(['items' => 'รายการที่ ' . ($index + 1) . ': ข้อความยาวเกินกำหนด']);
            }

            $rows[] = [
                generate_id(),
                $vehicleId,
                $name,
                $standard,
                $expiry,
                !empty($item['is_battery']) ? 1 : 0,
                1,
            ];
        }

        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $table = (new Item())->getTable();
            $placeholders = implode(', ', array_fill(0, count($rows), '(?, ?, ?, ?, ?, ?, ?)'));
            $stmt = $db->prepare(
                "INSERT INTO {$table} (id, car_id, name, standard, expiry_text, is_battery, active) VALUES {$placeholders}"
            );
            $stmt->execute(array_merge(...$rows));
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            $this->error('คัดลอกไม่สำเร็จ: ' . $e->getMessage());
        }

        $this->success(['created' => count($rows)], 201);
    }
}