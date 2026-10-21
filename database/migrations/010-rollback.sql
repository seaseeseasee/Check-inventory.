-- ============================================================================
-- ย้อนกลับ migration 010 (ใช้เมื่อจำเป็นเท่านั้น — สำรองฐานข้อมูลก่อนรันเสมอ)
--
-- ⚠ ข้อมูลที่เกิดหลัง 010 จะหายไปกับตารางเหล่านี้: Lot, เครื่องไฟฟ้า, ledger, ประวัติการแก้รอบตรวจ
--   ส่วน refer_items.current_qty ยังเป็นยอดรวมล่าสุดที่ถูกต้อง (ถูก sync ทุกครั้ง) จึงใช้ต่อกับโค้ดรุ่นเดิมได้
-- ควรคู่กับการ checkout โค้ดรุ่นก่อน 010 และ restore dump ใน storage/backups/pre_logic_* ถ้าต้องการสถานะเดิมทุกประการ
-- ============================================================================

DROP TABLE IF EXISTS refer_check_history;
DROP TABLE IF EXISTS refer_stock_movements;
DROP TABLE IF EXISTS refer_refill_batches;
DROP TABLE IF EXISTS refer_item_units;
DROP TABLE IF EXISTS refer_item_lots;

ALTER TABLE refer_checks DROP INDEX IF EXISTS uk_check_car_date_shift, DROP INDEX IF EXISTS idx_check_date;
ALTER TABLE refer_checks
  DROP COLUMN IF EXISTS check_date, DROP COLUMN IF EXISTS shift_code, DROP COLUMN IF EXISTS revision,
  DROP COLUMN IF EXISTS updated_at, DROP COLUMN IF EXISTS updated_by;

ALTER TABLE refer_check_items
  DROP COLUMN IF EXISTS check_status, DROP COLUMN IF EXISTS item_type, DROP COLUMN IF EXISTS expected_qty,
  DROP COLUMN IF EXISTS discrepancy, DROP COLUMN IF EXISTS note,
  DROP COLUMN IF EXISTS lots_snapshot, DROP COLUMN IF EXISTS units_snapshot;

ALTER TABLE refer_drafts DROP COLUMN IF EXISTS check_date, DROP COLUMN IF EXISTS shift_code, DROP COLUMN IF EXISTS payload;
ALTER TABLE refer_items DROP COLUMN IF EXISTS item_type;
ALTER TABLE refer_vehicles DROP COLUMN IF EXISTS vehicle_color;
