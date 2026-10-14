<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\InspectionService;
use App\Helpers\Response;
use App\Helpers\Validator;

class InspectionController extends Controller
{
    private InspectionService $inspectionService;

    public function __construct()
    {
        $this->inspectionService = new InspectionService();
    }

    public function index(): void
    {
        $inspections = $this->inspectionService->getInspections();
        $this->success($inspections);
    }

    public function show(string $id): void
    {
        $inspection = $this->inspectionService->getInspectionWithItems($id);
        if (!$inspection) {
            $this->notFound('Inspection not found');
        }
        $this->success($inspection);
    }

    public function start(): void
    {
        $input = $this->getInput();
        
        $validator = new Validator($input, [
            'car_id' => 'required|string|max:100',
            'nurse_id' => 'required|string|max:100',
            'shift' => 'required|string|in:เช้า,บ่าย,ดึก',
        ]);

        if (!$validator->validate()) {
            $this->validationError($validator->errors());
        }

        $vehicle = $this->inspectionService->getVehicle($input['car_id']);
        if (!$vehicle) {
            $this->notFound('Vehicle not found');
        }

        $nurse = $this->inspectionService->getAllNurses();
        $nurse = array_filter($nurse, fn($n) => $n['id'] === $input['nurse_id']);
        $nurse = array_values($nurse);
        if (empty($nurse)) {
            $this->notFound('Nurse not found');
        }
        $nurse = $nurse[0];

        // Check for existing draft
        $draft = $this->inspectionService->getDraft($input['car_id']);
        if ($draft) {
            if ($draft['nurse_id'] !== $input['nurse_id'] || $draft['shift_name'] !== $input['shift']) {
                $this->error('Vehicle has existing draft by different nurse/shift');
            }
            $items = $draft['results'];
        } else {
            // Create new check results from vehicle items
            $vehicleItems = $this->inspectionService->getItemsByVehicle($input['car_id']);
            $items = array_map(function($item) {
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
            }, $vehicleItems);
        }

        $checkId = generate_id();
        $now = (new \DateTime())->format('Y-m-d\TH:i:s.u\Z');
        
        $draftData = [
            'id' => $checkId,
            'car_id' => $input['car_id'],
            'nurse_id' => $input['nurse_id'],
            'car_name' => $vehicle['name'],
            'nurse_name' => $nurse['name'],
            'shift_name' => $input['shift'],
            'started_at' => $now,
            'results' => $items,
        ];

        $this->inspectionService->createDraft($draftData);
        $this->success($draftData);
    }

    public function saveDraft(): void
    {
        $input = $this->getInput();
        
        $validator = new Validator($input, [
            'car_id' => 'required|string|max:100',
            'results' => 'required|array',
        ]);

        if (!$validator->validate()) {
            $this->validationError($validator->errors());
        }

        $this->inspectionService->updateDraft($input['car_id'], $input['results']);
        $this->success(['ok' => true]);
    }

    public function submit(): void
    {
        $input = $this->getInput();
        
        $validator = new Validator($input, [
            'car_id' => 'required|string|max:100',
            'results' => 'required|array',
        ]);

        if (!$validator->validate()) {
            $this->validationError($validator->errors());
        }

        // Validate all items checked
        foreach ($input['results'] as $item) {
            if ($item['status'] === 'ยังไม่ตรวจ') {
                $this->error('All items must be checked');
            }
            if ($item['isBattery'] && !isset($item['batteryLevel'])) {
                $this->error('Battery level required for battery items');
            }
        }

        $draft = $this->inspectionService->getDraft($input['car_id']);
        if (!$draft) {
            $this->notFound('No active draft for this vehicle');
        }

        $now = (new \DateTime())->format('Y-m-d\TH:i:s.u\Z');
        $inspectionData = array_merge($draft, [
            'completed_at' => $now,
            'results' => $input['results'],
        ]);

        $result = $this->inspectionService->saveInspection($inspectionData);
        $this->success($result);
    }
}