-- ============================================================================
-- 014: บันทึกการเปลี่ยนแปลงบัญชีผู้ใช้ (audit log)
--
--   เดิมการสร้างบัญชี / เปลี่ยนบทบาท / ปิดใช้งาน / รีเซ็ตรหัสผ่าน ไม่มีร่องรอยในฐานข้อมูล
--   (มีแค่ error_log ตอนเปลี่ยนบทบาท) — ตารางนี้เก็บว่าใครทำอะไรกับบัญชีใด เมื่อไร
--   ไม่เก็บรหัสผ่านหรือ hash ใด ๆ (details บอกแค่ว่า "เปลี่ยนรหัสผ่าน")
--
-- เพิ่มตารางใหม่อย่างเดียว ไม่แตะข้อมูลเดิม — รันซ้ำได้
-- ============================================================================

CREATE TABLE IF NOT EXISTS `refer_audit_log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `at` VARCHAR(40) NOT NULL,
    `actor_id` VARCHAR(100) COLLATE utf8mb4_bin NULL COMMENT 'ผู้กระทำ (NULL = ระบบ/ไม่ได้เข้าสู่ระบบ)',
    `actor_username` VARCHAR(100) COLLATE utf8mb4_bin NULL,
    `action` VARCHAR(50) NOT NULL COMMENT 'เช่น user.create, user.update, user.password_change',
    `target_type` VARCHAR(30) NOT NULL,
    `target_id` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `details` TEXT NULL COMMENT 'JSON ของช่องที่เปลี่ยน {field: [ก่อน, หลัง]}',
    `ip` VARCHAR(45) NULL,
    PRIMARY KEY (`id`),
    KEY `idx_audit_target` (`target_type`, `target_id`),
    KEY `idx_audit_at` (`at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit trail of account changes';
