-- Migration 004: Vehicle detail fields for the "Add New Vehicle" form
-- Run after 003-users-and-roles.sql
--
-- Additive only: no column is dropped, renamed or retyped, and no FK changes.
-- Existing rows keep working — new columns become NULL, status defaults to 'available'.
-- Rollback (if ever needed): ALTER TABLE refer_vehicles
--   DROP COLUMN plate, DROP COLUMN brand_model, DROP COLUMN status,
--   DROP COLUMN odometer_initial, DROP COLUMN notes, DROP INDEX idx_vehicle_status;

ALTER TABLE refer_vehicles
ADD COLUMN IF NOT EXISTS plate VARCHAR(50) COLLATE utf8mb4_bin NULL COMMENT 'License plate e.g. กข-1234 กรุงเทพฯ' AFTER id,
ADD COLUMN IF NOT EXISTS brand_model VARCHAR(255) NULL COMMENT 'Brand / model e.g. Toyota Commuter' AFTER name,
ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'available' COMMENT 'available | maintenance | disabled' AFTER brand_model,
ADD COLUMN IF NOT EXISTS odometer_initial INT NULL COMMENT 'Initial odometer reading (km)' AFTER status,
ADD COLUMN IF NOT EXISTS notes VARCHAR(500) NULL COMMENT 'Additional notes' AFTER odometer_initial,
ADD INDEX IF NOT EXISTS idx_vehicle_status (status);
