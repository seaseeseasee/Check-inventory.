-- ============================================================================
-- 015: จำกัดสิทธิ์บัญชีของแอปให้เหลือเท่าที่ใช้ (ต้องรันด้วย root)
--
--   บัญชีแอป (MYSQL_USER) : เหลือแค่ SELECT/INSERT/UPDATE/DELETE — แอปไม่ได้ใช้ DDL ตอนทำงาน
--                           (ไม่มี routine/trigger/view/event ในฐานข้อมูลนี้)
--   migration ต่อจากนี้ให้รันด้วย root:
--     docker exec -i emer-db-1 sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' < database/migrations/0xx-....sql
--   ย้อนกลับ (ถ้าจำเป็น): GRANT ALL PRIVILEGES ON `refer\_system`.* TO `refer_app`@`%`;
--
-- ชื่อฐานข้อมูลต้องเขียน `refer\_system` (มี \ หน้า _) ให้ตรงกับ grant ที่ image MariaDB สร้างให้ MYSQL_USER
-- — เขียน `refer_system` เฉย ๆ จะเป็น grant คนละรายการ (REVOKE แล้วไม่มีผลกับสิทธิ์เดิม)
-- รันซ้ำได้ (ชื่อบัญชีแอปค่าเริ่มต้นคือ refer_app — ถ้าใช้ชื่ออื่นใน .env ให้แก้ 2 บรรทัดล่าง)
-- ============================================================================

REVOKE ALL PRIVILEGES ON `refer\_system`.* FROM `refer_app`@`%`;
GRANT SELECT, INSERT, UPDATE, DELETE ON `refer\_system`.* TO `refer_app`@`%`;
FLUSH PRIVILEGES;
