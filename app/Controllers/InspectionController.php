<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\InspectionService;
use App\Helpers\Response;
use App\Helpers\Validator;
use App\Middleware\RoleMiddleware;

/**
 * Legacy /api/inspections/* endpoints.
 *
 * หน้าจอปัจจุบันใช้ /api/staff/inspections/* แทนแล้ว แต่เส้นทางนี้ยังเปิดไว้เพื่อไม่ทำร้าย
 * client เก่า จึงต้อง "ตรวจสิทธิ์ฝั่งเซิร์ฟเวอร์ทุกครั้ง" ตามข้อ 7.8 ของสเปก
 * เดิม: ใครก็ตามที่มี session เริ่ม/ส่งรอบตรวจในชื่อใครก็ได้ และไม่ได้ส่ง user context ลงบันทึก
 */
class InspectionController extends Controller
{
    private InspectionService $inspectionService;
    private RoleMiddleware $roleMiddleware;

    public function __construct()
    {
        $this->inspectionService = new InspectionService();
        $this->roleMiddleware = new RoleMiddleware();
    }

    public function index(): void
    {
        $this->roleMiddleware->requireStaff();

        $inspections = $this->inspectionService->getInspections();
        $this->success($inspections);
    }

    public function show(string $id): void
    {
        $this->roleMiddleware->requireStaff();

        $inspection = $this->inspectionService->getInspectionWithItems($id);
        if (!$inspection) {
            $this->notFound('Inspection not found');
        }
        $this->success($inspection);
    }

    public function start(): void
    {
        $this->roleMiddleware->requireStaff();

        $input = $this->getInput();

        // like StaffController: a staff account can only inspect as the nurse linked to it
        $currentUser = $this->roleMiddleware->getCurrentUser();
        $this->forceLinkedNurse($currentUser, $input);

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
        $nurse = array_values(array_filter($nurse, fn($n) => $n['id'] === $input['nurse_id']));
        if (empty($nurse)) {
            $this->notFound('Nurse not found');
        }
        $nurse = $nurse[0];

        $draft = $this->inspectionService->getDraft($input['car_id']);
        $sameOwner = $draft
            && $draft['nurse_id'] === $input['nurse_id']
            && $draft['shift_name'] === $input['shift'];

        if ($draft && $sameOwner) {
            $this->success([
                'id' => $draft['id'],
                'car_id' => $draft['car_id'],
                'nurse_id' => $draft['nurse_id'],
                'car_name' => $draft['car_name'],
                'nurse_name' => $draft['nurse_name'],
                'shift_name' => $draft['shift_name'],
                'started_at' => $draft['started_at'],
                'results' => $this->inspectionService->getDraftResults($input['car_id']),
                'resumed' => true,
            ]);
        }

        if ($draft && empty($input['replace'])) {
            $this->error(
                'มีร่างการตรวจของ ' . $draft['nurse_name'] . ' (เวร' . $draft['shift_name'] . ') อยู่แล้ว',
                409,
                ['code' => 'DRAFT_EXISTS']
            );
        }

        $checkId = generate_id();
        $now = (new \DateTime())->format('Y-m-d\TH:i:s.u\Z');

        $draftData = [
            'id' => $checkId,
            'car_id' => (string)$input['car_id'],
            'nurse_id' => (string)$input['nurse_id'],
            'car_name' => (string)$vehicle['name'],
            'nurse_name' => (string)$nurse['name'],
            'shift_name' => (string)$input['shift'],
            'started_at' => $now,
            // ใช้กติกาเดียวกับหน้าตรวจรถ (ยอดบนรถล่าสุดจาก DB + เป้าหมาย/หน่วย)
            'results' => $this->inspectionService->buildResultsFromVehicle((string)$input['car_id']),
        ];

        $this->inspectionService->createDraft($draftData);
        $this->success($draftData + ['resumed' => false]);
    }

    public function saveDraft(): void
    {
        $this->roleMiddleware->requireStaff();

        $input = $this->getInput();

        $validator = new Validator($input, [
            'car_id' => 'required|string|max:100',
            'results' => 'required|array',
        ]);

        if (!$validator->validate()) {
            $this->validationError($validator->errors());
        }

        $draft = $this->assertDraftOwner((string)$input['car_id']);

        $this->inspectionService->updateDraft((string)$input['car_id'], $input['results']);
        $this->success(['ok' => true]);
    }

    public function submit(): void
    {
        $this->roleMiddleware->requireStaff();

        $input = $this->getInput();

        $validator = new Validator($input, [
            'car_id' => 'required|string|max:100',
            'results' => 'required|array',
        ]);

        if (!$validator->validate()) {
            $this->validationError($validator->errors());
        }

        $carId = (string)$input['car_id'];
        $draft = $this->assertDraftOwner($carId);

        // same server-side rules as the staff endpoint: 422 + Thai messages, never a 500
        $errors = $this->inspectionService->validateSubmission($carId, $input['results']);
        if (!empty($errors)) {
            $this->validationError($errors);
        }

        $currentUser = $this->roleMiddleware->getCurrentUser();
        $now = (new \DateTime())->format('Y-m-d\TH:i:s.u\Z');
        $inspectionData = array_merge($draft, [
            'completed_at' => $now,
            'results' => $input['results'],
        ]);

        $result = $this->inspectionService->saveInspection(
            $inspectionData,
            $currentUser['id'] ?? null,
            (string)($currentUser['full_name'] ?? '')
        );
        $this->success($result);
    }

    /**
     * Staff accounts may only work under the nurse record linked to their login.
     */
    private function forceLinkedNurse(?array $currentUser, array &$input): void
    {
        if (($currentUser['role'] ?? '') !== 'staff') {
            return;
        }

        $linkedNurseId = trim((string)($currentUser['nurse_id'] ?? ''));
        if ($linkedNurseId === '') {
            $this->error(
                'บัญชีนี้ยังไม่ได้เชื่อมโยงรายชื่อพยาบาล กรุณาติดต่อผู้ดูแลระบบ',
                403,
                ['code' => 'NO_LINKED_NURSE']
            );
        }
        $input['nurse_id'] = $linkedNurseId;
    }

    /**
     * The draft must exist and belong to the caller (admin may take over).
     */
    private function assertDraftOwner(string $carId): array
    {
        $draft = $this->inspectionService->getDraft($carId);
        if (!$draft) {
            $this->notFound('No active draft for this vehicle');
        }

        $currentUser = $this->roleMiddleware->getCurrentUser();
        if (($currentUser['role'] ?? '') === 'staff' && ($currentUser['nurse_id'] ?? '') !== $draft['nurse_id']) {
            $this->error(
                'ร่างการตรวจนี้เป็นของ ' . $draft['nurse_name'] . ' กรุณาเริ่มการตรวจใหม่ในชื่อของท่าน',
                403
            );
        }

        return $draft;
    }
}
