-- Import into a NEW, empty hospital-managed MySQL 8+ database using utf8mb4.
-- Contains no personnel data. All application tables use the refer_ prefix.
CREATE TABLE refer_revision (id TINYINT PRIMARY KEY, revision BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB;
INSERT INTO refer_revision VALUES (1,0);
CREATE TABLE refer_vehicles (
 id VARCHAR(100) COLLATE utf8mb4_bin PRIMARY KEY,
 name VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE refer_nurses (
 id VARCHAR(100) COLLATE utf8mb4_bin PRIMARY KEY,
 name VARCHAR(255) NOT NULL, department VARCHAR(255) NOT NULL,
 phone VARCHAR(100) NOT NULL DEFAULT '', active BOOLEAN NOT NULL,
 start_text VARCHAR(100) NOT NULL DEFAULT '', end_text VARCHAR(100) NOT NULL DEFAULT '', note TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE refer_items (
 id VARCHAR(100) COLLATE utf8mb4_bin PRIMARY KEY,
 car_id VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
 name VARCHAR(255) NOT NULL, standard VARCHAR(100) NOT NULL,
 expiry_text VARCHAR(100) NOT NULL DEFAULT '', is_battery BOOLEAN NOT NULL, active BOOLEAN NOT NULL,
 FOREIGN KEY (car_id) REFERENCES refer_vehicles(id), INDEX(car_id,active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE refer_checks (
 id VARCHAR(100) COLLATE utf8mb4_bin PRIMARY KEY,
 car_id VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
 nurse_id VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
 car_name VARCHAR(255) NOT NULL, nurse_name VARCHAR(255) NOT NULL,
 shift_name VARCHAR(100) NOT NULL, started_at VARCHAR(40) NOT NULL, completed_at VARCHAR(40) NOT NULL,
 FOREIGN KEY(car_id) REFERENCES refer_vehicles(id), FOREIGN KEY(nurse_id) REFERENCES refer_nurses(id),
 INDEX(car_id,completed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE refer_check_items (
 check_id VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
 item_id VARCHAR(100) COLLATE utf8mb4_bin NOT NULL, sort_order INT NOT NULL,
 name VARCHAR(255) NOT NULL, standard VARCHAR(100) NOT NULL,
 expiry_text VARCHAR(100) NOT NULL, is_battery BOOLEAN NOT NULL,
 status VARCHAR(30) NOT NULL, detail TEXT NOT NULL, battery_level TINYINT NULL,
 PRIMARY KEY(check_id,item_id), FOREIGN KEY(check_id) REFERENCES refer_checks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE refer_drafts (
 id VARCHAR(100) COLLATE utf8mb4_bin PRIMARY KEY,
 car_id VARCHAR(100) COLLATE utf8mb4_bin NOT NULL UNIQUE,
 nurse_id VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
 car_name VARCHAR(255) NOT NULL, nurse_name VARCHAR(255) NOT NULL,
 shift_name VARCHAR(100) NOT NULL, started_at VARCHAR(40) NOT NULL,
 FOREIGN KEY(car_id) REFERENCES refer_vehicles(id), FOREIGN KEY(nurse_id) REFERENCES refer_nurses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE refer_draft_items (
 draft_id VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
 item_id VARCHAR(100) COLLATE utf8mb4_bin NOT NULL, sort_order INT NOT NULL,
 name VARCHAR(255) NOT NULL, standard VARCHAR(100) NOT NULL,
 expiry_text VARCHAR(100) NOT NULL, is_battery BOOLEAN NOT NULL,
 status VARCHAR(30) NOT NULL, detail TEXT NOT NULL, battery_level TINYINT NULL,
 PRIMARY KEY(draft_id,item_id), FOREIGN KEY(draft_id) REFERENCES refer_drafts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE refer_metadata (id VARCHAR(100) COLLATE utf8mb4_bin PRIMARY KEY, payload JSON NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE refer_login_attempts (ip_hash CHAR(64) PRIMARY KEY, attempts INT NOT NULL, window_start BIGINT NOT NULL) ENGINE=InnoDB;
-- v5: ระบบเบิก–เติมอุปกรณ์ (สำหรับติดตั้งใหม่ — ฐานข้อมูลเดิมใช้ migrations/001-add-restock.sql)
CREATE TABLE refer_restock_requests (
 id VARCHAR(100) COLLATE utf8mb4_bin PRIMARY KEY,
 no VARCHAR(100) NOT NULL,
 car_id VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
 car_name VARCHAR(255) NOT NULL,
 check_id VARCHAR(100) COLLATE utf8mb4_bin NULL,
 created_by VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
 created_name VARCHAR(255) NOT NULL,
 reason TEXT NOT NULL, note TEXT NOT NULL,
 status VARCHAR(20) NOT NULL,
 created_at VARCHAR(40) NOT NULL,
 FOREIGN KEY(car_id) REFERENCES refer_vehicles(id),
 FOREIGN KEY(created_by) REFERENCES refer_nurses(id),
 INDEX(car_id,status), INDEX(check_id), INDEX(no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE refer_restock_items (
 request_id VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
 item_id VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
 sort_order INT NOT NULL,
 name VARCHAR(255) NOT NULL, standard VARCHAR(100) NOT NULL,
 actual_qty VARCHAR(100) NOT NULL DEFAULT '', qty_requested VARCHAR(100) NOT NULL DEFAULT '',
 qty_issued VARCHAR(100) NOT NULL DEFAULT '', qty_filled VARCHAR(100) NOT NULL DEFAULT '',
 PRIMARY KEY(request_id,item_id),
 FOREIGN KEY(request_id) REFERENCES refer_restock_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE refer_restock_actions (
 request_id VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
 seq INT NOT NULL,
 type VARCHAR(20) NOT NULL,
 at VARCHAR(40) NOT NULL,
 by_id VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
 by_name VARCHAR(255) NOT NULL,
 note TEXT NOT NULL,
 lines JSON NOT NULL,
 PRIMARY KEY(request_id,seq),
 FOREIGN KEY(request_id) REFERENCES refer_restock_requests(id) ON DELETE CASCADE,
 FOREIGN KEY(by_id) REFERENCES refer_nurses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
