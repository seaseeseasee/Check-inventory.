-- ============================================================================
-- 018: สิทธิ์ฐานข้อมูลของระบบ log (ต้องรันด้วย root, หลัง 017)
--
--   refer_app (บัญชีเว็บ) : refer_logs → SELECT + INSERT เท่านั้น = แก้/ลบ log ย้อนหลังไม่ได้
--                          ยกเว้น UPDATE เฉพาะคอลัมน์ repeat_count, last_seen_at ของ log_events
--                          (รวมเหตุการณ์ security ที่ซ้ำ — แก้เนื้อหา log ไม่ได้)
--   refer_log_archiver    : ใช้โดยสคริปต์ CLI archive เท่านั้น — สร้างแบบล็อกไว้ (ACCOUNT LOCK)
--                          ไม่มีรหัสผ่านในไฟล์นี้; ตั้งรหัส + ปลดล็อกด้วยมือ (ดูท้ายไฟล์)
--
-- สิทธิ์เดิมของ refer_app บน refer_system (015) ไม่ถูกแตะ
-- ชื่อบัญชีแอปค่าเริ่มต้นคือ refer_app — ถ้าใช้ชื่ออื่นใน .env ให้แก้ทุกบรรทัดที่มี refer_app
-- รันซ้ำได้:
--   docker exec -i emer-db-1 sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD"' < database/migrations/018-log-grants.sql
-- ============================================================================

-- ---- บัญชีเว็บ ----
GRANT SELECT, INSERT ON `refer_logs`.* TO `refer_app`@`%`;
GRANT UPDATE (`repeat_count`, `last_seen_at`) ON `refer_logs`.`log_events` TO `refer_app`@`%`;

-- ---- บัญชีสคริปต์ archive ----
CREATE USER IF NOT EXISTS `refer_log_archiver`@`%` ACCOUNT LOCK;
GRANT SELECT, INSERT, DELETE ON `refer_logs`.`log_events`       TO `refer_log_archiver`@`%`;
GRANT SELECT, DELETE         ON `refer_logs`.`log_changes`      TO `refer_log_archiver`@`%`;
GRANT SELECT, INSERT, UPDATE ON `refer_logs`.`log_archives`     TO `refer_log_archiver`@`%`;
GRANT SELECT, INSERT, UPDATE ON `refer_logs`.`log_archive_runs` TO `refer_log_archiver`@`%`;

FLUSH PRIVILEGES;

-- ---- ขั้นตอนมือ (ทำครั้งเดียว ตอนพร้อมใช้ archive ใน Phase 4) ----
-- ALTER USER `refer_log_archiver`@`%` IDENTIFIED BY '<รหัสยาวสุ่ม>' ACCOUNT UNLOCK;
-- แล้วใส่รหัสเดียวกันใน .env เป็น LOG_ARCHIVER_PASSWORD
--
-- ---- ตรวจผล ----
-- SHOW GRANTS FOR `refer_app`@`%`;
-- SHOW GRANTS FOR `refer_log_archiver`@`%`;
