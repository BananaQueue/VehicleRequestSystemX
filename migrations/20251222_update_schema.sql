-- =====================================================
-- Vehicle Request System Database Schema Update
-- =====================================================
-- This script updates the database schema to match the PHP application code
-- and removes unused tables.
-- 
-- IMPORTANT: Backup your database before running this script!
-- =====================================================

-- Start transaction for atomic updates
START TRANSACTION;

-- =====================================================
-- 1. DROP UNUSED TABLE: vehicle_requests
-- =====================================================
-- This table is defined in vrsx_db.sql but never used in the application
-- All functionality uses the 'requests' table instead

DROP TABLE IF EXISTS `vehicle_requests`;

-- =====================================================
-- 2. UPDATE REQUESTS TABLE STATUS ENUM
-- =====================================================
-- Current enum: ('pending','approved','rejected','approved_pending_dispatch')
-- Required enum: ('pending_dispatch_assignment','pending_admin_approval','approved','rejected_new_request','rejected_reassign_dispatch','rejected','cancelled')

ALTER TABLE `requests` 
MODIFY COLUMN `status` ENUM(
    'pending_dispatch_assignment',
    'pending_admin_approval', 
    'approved',
    'rejected_new_request',
    'rejected_reassign_dispatch',
    'rejected',
    'cancelled'
) DEFAULT 'pending_dispatch_assignment';

-- =====================================================
-- 3. UPDATE VEHICLES TABLE STATUS ENUM
-- =====================================================
-- Current enum: ('available','assigned','returning','unavailable')
-- Required enum: ('available','assigned','returning','maintenance','unavailable')

ALTER TABLE `vehicles`
MODIFY COLUMN `status` ENUM(
    'available',
    'assigned', 
    'returning',
    'maintenance',
    'unavailable'
) DEFAULT 'available';

-- =====================================================
-- 4. ADD MISSING COLUMNS TO REQUESTS TABLE
-- =====================================================
-- Add rejection_reason column if it doesn't exist
-- (This column is referenced in requests.sql but may be missing from vrsx_db.sql)

ALTER TABLE `requests` 
ADD COLUMN IF NOT EXISTS `rejection_reason` VARCHAR(255) NULL;

-- =====================================================
-- 4.1 ADD PASSENGER AND TRAVEL DATE FIELDS
-- =====================================================
-- Add departure date (when employee wants to depart)
ALTER TABLE `requests` 
ADD COLUMN IF NOT EXISTS `departure_date` DATE NULL;

-- Add return date (when employee wants to return)
ALTER TABLE `requests` 
ADD COLUMN IF NOT EXISTS `return_date` DATE NULL;

-- Add passenger count (calculated from passenger_names)
ALTER TABLE `requests` 
ADD COLUMN IF NOT EXISTS `passenger_count` INT DEFAULT 0;

-- Add passenger names (JSON format for simplicity)
ALTER TABLE `requests` 
ADD COLUMN IF NOT EXISTS `passenger_names` TEXT NULL;

-- =====================================================
-- 5. CREATE INDEX FOR PERFORMANCE
-- =====================================================
-- Add index on status column for better query performance

CREATE INDEX IF NOT EXISTS `idx_request_status` ON `requests` (`status`);

-- =====================================================
-- 6. CREATE REQUEST AUDIT LOG TABLE
-- =====================================================

CREATE TABLE IF NOT EXISTS `request_audit_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `request_id` INT NOT NULL,
    `action` VARCHAR(100) NOT NULL,
    `actor_id` INT NULL,
    `actor_role` VARCHAR(50) NULL,
    `actor_name` VARCHAR(255) NULL,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_request_audit_request`
        FOREIGN KEY (`request_id`) REFERENCES `requests`(`id`)
        ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS `idx_request_audit_request_id` ON `request_audit_logs` (`request_id`);

-- =====================================================
-- 7. UPDATE EXISTING DATA (if needed)
-- =====================================================
-- Update any existing records with old status values to new ones
-- This handles data migration from old enum values to new ones

UPDATE `requests` 
SET `status` = 'pending_dispatch_assignment' 
WHERE `status` = 'pending';

UPDATE `requests` 
SET `status` = 'approved' 
WHERE `status` = 'approved_pending_dispatch';

-- =====================================================
-- 8. VERIFY CHANGES
-- =====================================================
-- Display table structures to verify changes

SHOW CREATE TABLE `requests`;
SHOW CREATE TABLE `vehicles`;

-- =====================================================
-- 9. COMMIT TRANSACTION
-- =====================================================
-- If everything looks good, commit the changes
COMMIT;

-- =====================================================
-- 10. VERIFICATION QUERIES
-- =====================================================
-- Run these queries to verify the schema updates worked correctly

-- Check requests table structure
DESCRIBE `requests`;

-- Check vehicles table structure  
DESCRIBE `vehicles`;

-- Verify vehicle_requests table is dropped
SHOW TABLES LIKE 'vehicle_requests';

-- Check current data in requests table
SELECT id, status, requestor_name, destination, request_date 
FROM `requests` 
ORDER BY request_date DESC 
LIMIT 5;

-- Check current data in vehicles table
SELECT id, plate_number, status, assigned_to, driver_name 
FROM `vehicles` 
ORDER BY id;

-- =====================================================
-- 11. SUCCESS MESSAGE
-- =====================================================
SELECT 'Database schema update completed successfully!' as message;
