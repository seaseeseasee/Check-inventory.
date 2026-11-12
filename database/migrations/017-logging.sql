-- ============================================================================
-- 017: ระบบ Logging & Audit Trail กลาง
--
--   1) ฐานข้อมูลแยก `refer_logs` (MariaDB ตัวเดิม) — เก็บ log ทุกประเภท
--      แยกฐานเพื่อให้ 018 ให้สิทธิ์บัญชีแอปแค่ SELECT/INSERT ได้ (append-only ที่ระดับ DB)
--   2) ตาราง `refer_user_permissions` ในฐานหลัก — สิทธิ์ดู log แยกจาก role
--
-- ไม่แก้/ไม่ลบตารางหรือข้อมูลเดิม — `refer_audit_log` เดิมคงไว้และแอปยังเขียนต่อเหมือนเดิม
-- ข้อมูลเก่าใน refer_audit_log จะนำเข้า log_events ด้วยสคริปต์ PHP (bin/logs-import-legacy-audit.php)
-- ไม่ทำใน SQL เพราะต้องคำนวณ hash chain ด้วยอัลกอริทึมเดียวกับแอป
--
-- เวลา: ทุกคอลัมน์ *_at ในฐานนี้เป็น DATETIME(6) แบบ UTC จริง — แสดงผลเป็น Asia/Bangkok ที่หน้าเว็บ
--
-- ต้องรันด้วย root (สร้างฐานข้อมูลใหม่):
--   docker exec -i emer-db-1 sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD"' < database/migrations/017-logging.sql
-- รันซ้ำได้ (IF NOT EXISTS ทั้งหมด)
-- ชื่อฐานหลักเขียนตายตัวเป็น refer_system ตาม .env.docker.example — ถ้าใช้ชื่ออื่นให้แก้ส่วนที่ 2
-- ============================================================================

CREATE DATABASE IF NOT EXISTS `refer_logs`
    DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 1.1 log_events — เหตุการณ์ทุกประเภท (1 แถว = 1 เหตุการณ์)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `refer_logs`.`log_events` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event_id`     CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'ULID (เรียงตามเวลา ไม่ซ้ำ)',
    `request_id`   CHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'เหตุการณ์จาก request เดียวกันมีค่าเดียวกัน',
    `category`     ENUM('activity','audit','auth','security','system','data_access') NOT NULL,
    `action`       VARCHAR(64)  NOT NULL COMMENT 'เช่น auth.login_failed, vehicle.update',
    `module`       VARCHAR(40)  NOT NULL COMMENT 'เช่น auth, user, nurse, vehicle, item, department, inspection, refill, lot, logs',
    `record_type`  VARCHAR(40)  NULL,
    `record_id`    VARCHAR(100) COLLATE utf8mb4_bin NULL,
    `user_id`      VARCHAR(100) COLLATE utf8mb4_bin NULL COMMENT 'NULL = ระบบ / ยังไม่ได้เข้าสู่ระบบ',
    `username`     VARCHAR(100) COLLATE utf8mb4_bin NULL COMMENT 'ชื่อบัญชี ณ เวลาที่เกิดเหตุ (เก็บเต็ม)',
    `user_role`    VARCHAR(20)  NULL,
    `description`  VARCHAR(500) NULL COMMENT 'ผ่าน LogSanitizer แล้ว (ตัด CR/LF/control chars)',
    `status`       ENUM('SUCCESS','FAILED') NOT NULL DEFAULT 'SUCCESS',
    `severity`     ENUM('INFO','WARNING','ERROR','CRITICAL') NOT NULL DEFAULT 'INFO',
    `ip_address`   VARCHAR(45)  CHARACTER SET ascii COLLATE ascii_bin NULL,
    `user_agent`   VARCHAR(255) NULL,
    `http_method`  VARCHAR(7)   CHARACTER SET ascii COLLATE ascii_bin NULL,
    `path`         VARCHAR(255) NULL COMMENT 'ไม่รวม query string',
    `http_status`  SMALLINT UNSIGNED NULL,
    `context`      JSON NULL COMMENT 'ข้อมูลประกอบเล็ก ๆ ที่ไม่ใช่ old/new (ผ่าน sanitizer, จำกัด 4 KB)',
    `repeat_count` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'เหตุการณ์ security ซ้ำใน 60 วิ รวมเป็นแถวเดียว',
    `has_changes`  TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = มีแถวใน log_changes',
    `created_at`   DATETIME(6) NOT NULL COMMENT 'UTC',
    `last_seen_at` DATETIME(6) NOT NULL COMMENT 'UTC — เท่ากับ created_at จนกว่าจะมีเหตุการณ์ซ้ำ',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_event_id` (`event_id`),
    KEY `idx_created`        (`created_at`),
    KEY `idx_cat_created`    (`category`, `created_at`),
    KEY `idx_user_created`   (`user_id`, `created_at`),
    KEY `idx_module_created` (`module`, `created_at`),
    KEY `idx_sev_created`    (`severity`, `created_at`),
    KEY `idx_status_created` (`status`, `created_at`),
    KEY `idx_action_created` (`action`, `created_at`),
    KEY `idx_record`         (`record_type`, `record_id`),
    KEY `idx_request`        (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Central log: activity/audit/auth/security/system/data_access (append-only สำหรับแอป)';

-- ----------------------------------------------------------------------------
-- 1.2 log_changes — old/new ของ audit + hash chain (แยกตารางให้ log_events แคบ ค้นเร็ว)
--   row_hash = SHA-256( prev_hash || "\n" || canonical JSON ของเหตุการณ์ + old + new )
--   แถวแรก prev_hash = '0' x 64 — ตรวจทั้งสายด้วย bin/logs-verify.php
--   ลำดับ chain_seq กันชนกันด้วย GET_LOCK('refer_logs.audit_chain') ฝั่งแอป (ไม่ต้องมีสิทธิ์ UPDATE)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `refer_logs`.`log_changes` (
    `event_id`   CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `old_value`  JSON NULL COMMENT 'เฉพาะช่องที่เปลี่ยน + ผ่าน sanitizer (จำกัด 16 KB)',
    `new_value`  JSON NULL,
    `chain_seq`  BIGINT UNSIGNED NOT NULL,
    `prev_hash`  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `row_hash`   CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (`event_id`),
    UNIQUE KEY `uq_chain_seq` (`chain_seq`),
    CONSTRAINT `fk_changes_event` FOREIGN KEY (`event_id`)
        REFERENCES `refer_logs`.`log_events` (`event_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Audit old/new values with tamper-evident hash chain';

-- ----------------------------------------------------------------------------
-- 1.3 log_archive_runs — ประวัติการรัน archive แต่ละครั้ง
--   หน้าเว็บ "สั่ง archive ตอนนี้" = INSERT แถว status='requested' → สคริปต์ CLI มารับไปทำ
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `refer_logs`.`log_archive_runs` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `status`        ENUM('requested','running','success','partial','failed') NOT NULL,
    `triggered_by`  VARCHAR(100) COLLATE utf8mb4_bin NULL COMMENT 'user_id ผู้สั่ง หรือ NULL = ตั้งเวลา',
    `requested_at`  DATETIME(6) NOT NULL COMMENT 'UTC',
    `started_at`    DATETIME(6) NULL,
    `finished_at`   DATETIME(6) NULL,
    `summary`       TEXT NULL COMMENT 'JSON สรุปผลต่อไฟล์',
    PRIMARY KEY (`id`),
    KEY `idx_status` (`status`, `requested_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Archive job history';

-- ----------------------------------------------------------------------------
-- 1.4 log_archives — Archive Index (1 แถว = 1 ไฟล์ = ประเภท × เดือน)
--   ลำดับสถานะ: exporting → verified → backed_up → purged   (ผิดพลาดขั้นใด → failed, ไม่ลบต้นฉบับ)
--   ครบอายุเก็บ (ค่าเริ่มต้น 5 ปี) → destroyed
--   UNIQUE (category, period) กัน archive ซ้ำ; แถว failed ทำซ้ำได้ที่แถวเดิม
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `refer_logs`.`log_archives` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `category`      ENUM('activity','audit','auth','security','system','data_access') NOT NULL,
    `period`        CHAR(7) CHARACTER SET ascii NOT NULL COMMENT 'YYYY-MM (ตามเวลา UTC ของ created_at)',
    `file_path`     VARCHAR(255) NOT NULL COMMENT 'relative จาก LOG_ARCHIVE_PATH',
    `backup_path`   VARCHAR(255) NULL COMMENT 'relative จาก LOG_ARCHIVE_BACKUP_PATH',
    `row_count`     INT UNSIGNED NOT NULL DEFAULT 0,
    `min_id`        BIGINT UNSIGNED NULL,
    `max_id`        BIGINT UNSIGNED NULL,
    `from_at`       DATETIME(6) NULL COMMENT 'UTC — created_at ต่ำสุดในไฟล์',
    `to_at`         DATETIME(6) NULL COMMENT 'UTC — created_at สูงสุดในไฟล์',
    `size_bytes`    BIGINT UNSIGNED NULL,
    `sha256`        CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `status`        ENUM('exporting','verified','backed_up','purged','failed','destroyed') NOT NULL,
    `error_message` VARCHAR(500) NULL,
    `run_id`        INT UNSIGNED NOT NULL,
    `created_at`    DATETIME(6) NOT NULL COMMENT 'UTC',
    `updated_at`    DATETIME(6) NOT NULL COMMENT 'UTC',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cat_period` (`category`, `period`),
    KEY `idx_status` (`status`),
    KEY `idx_run` (`run_id`),
    CONSTRAINT `fk_archive_run` FOREIGN KEY (`run_id`)
        REFERENCES `refer_logs`.`log_archive_runs` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Archive index: one .json.gz (NDJSON) file per category per month';

-- ============================================================================
-- 2. สิทธิ์ดู log (ฐานหลัก) — เพิ่มตารางใหม่อย่างเดียว ไม่แก้ refer_users
--    permission ที่ใช้: logs.view, logs.audit_detail, logs.security, logs.export,
--                     archives.download, archives.manage
--    ผู้มีสิทธิ์ต้องเป็น role admin ด้วยเสมอ (ตรวจที่แอป) — ลบบัญชีแล้วสิทธิ์หายตาม (CASCADE)
-- ============================================================================
CREATE TABLE IF NOT EXISTS `refer_system`.`refer_user_permissions` (
    `user_id`     VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `permission`  VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `granted_by`  VARCHAR(100) COLLATE utf8mb4_bin NULL,
    `granted_at`  VARCHAR(40) NOT NULL COMMENT 'รูปแบบเดียวกับคอลัมน์เวลาอื่นในฐานหลัก',
    PRIMARY KEY (`user_id`, `permission`),
    KEY `idx_permission` (`permission`),
    CONSTRAINT `fk_perm_user` FOREIGN KEY (`user_id`)
        REFERENCES `refer_system`.`refer_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
  COMMENT='Fine-grained permissions (log viewer etc.) on top of role';

-- ให้สิทธิ์ครบ 6 ตัวแก่ admin คนแรกที่ active อยู่ (บัญชีที่สร้างก่อนสุด) — คนอื่นให้ผ่านหน้าผู้ใช้ภายหลัง
-- INSERT IGNORE = รันซ้ำแล้วไม่ซ้ำ / ไม่มี admin เลยก็ไม่ใส่อะไร
INSERT IGNORE INTO `refer_system`.`refer_user_permissions` (`user_id`, `permission`, `granted_by`, `granted_at`)
SELECT u.`id`, p.`permission`, NULL, DATE_FORMAT(NOW(6), '%Y-%m-%dT%H:%i:%s.%fZ')
FROM (
    SELECT `id` FROM `refer_system`.`refer_users`
    WHERE `role` = 'admin' AND `active` = 1
    ORDER BY `created_at` ASC LIMIT 1
) u
CROSS JOIN (
    SELECT 'logs.view' AS permission UNION ALL SELECT 'logs.audit_detail' UNION ALL SELECT 'logs.security'
    UNION ALL SELECT 'logs.export' UNION ALL SELECT 'archives.download' UNION ALL SELECT 'archives.manage'
) p;
