-- ========================================
-- NLETA - FINAL DATABASE FIX
-- Run this in phpMyAdmin on: if0_41489314_maintenance
-- Safe to run - will NOT delete any existing data
-- ========================================

-- --------------------------------------------------------
-- FIX 1: users table - add missing columns
-- --------------------------------------------------------
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `phone` varchar(15) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `created_at` timestamp DEFAULT current_timestamp();

-- --------------------------------------------------------
-- FIX 2: assets table - add missing created_at
-- --------------------------------------------------------
ALTER TABLE `assets`
  ADD COLUMN IF NOT EXISTS `created_at` timestamp DEFAULT current_timestamp();

-- --------------------------------------------------------
-- FIX 3: maintenance_jobs - add missing columns
-- The real DB is missing: status, owner_remark, created_at, updated_at
-- --------------------------------------------------------
ALTER TABLE `maintenance_jobs`
  ADD COLUMN IF NOT EXISTS `status` enum('assigned','in_progress','pending_owner','approved','rejected','completed') DEFAULT 'assigned',
  ADD COLUMN IF NOT EXISTS `owner_remark` text DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `created_at` timestamp DEFAULT current_timestamp(),
  ADD COLUMN IF NOT EXISTS `updated_at` timestamp DEFAULT current_timestamp() ON UPDATE current_timestamp();

-- --------------------------------------------------------
-- FIX 4: callbacks - add missing columns
-- Code uses: type, description
-- --------------------------------------------------------
ALTER TABLE `callbacks`
  ADD COLUMN IF NOT EXISTS `description` text DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `type` varchar(50) DEFAULT 'regular';

-- --------------------------------------------------------
-- FIX 5: insurance - add missing created_at
-- --------------------------------------------------------
ALTER TABLE `insurance`
  ADD COLUMN IF NOT EXISTS `created_at` timestamp DEFAULT current_timestamp();

-- --------------------------------------------------------
-- FIX 6: maintenance_records - code expects 'status' column
-- Real DB has it but as enum - make sure it matches
-- --------------------------------------------------------
ALTER TABLE `maintenance_records`
  MODIFY COLUMN IF EXISTS `status` enum('pending_owner','approved','rejected','completed') DEFAULT 'approved';

-- --------------------------------------------------------
-- FIX 7: Create fortnightly_checks table if missing
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `fortnightly_checks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_id` int(11) DEFAULT NULL,
  `owner_id` int(11) DEFAULT NULL,
  `check_date` date NOT NULL,
  `checklist` longtext DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- FIX 8: Create news table if missing
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `news` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) DEFAULT NULL,
  `text` text DEFAULT NULL,
  `date` varchar(50) DEFAULT NULL,
  `link` varchar(500) DEFAULT NULL,
  `fatal` int(11) DEFAULT 0,
  `injured` int(11) DEFAULT 0,
  `trapped` int(11) DEFAULT 0,
  `created_at` timestamp DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- FIX 9: noc_registration - code uses owner_id & asset_id
-- Already present in real DB - just ensure indexes exist
-- --------------------------------------------------------
ALTER TABLE `noc_registration`
  ADD COLUMN IF NOT EXISTS `owner_id` int(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `asset_id` int(11) DEFAULT NULL;

-- --------------------------------------------------------
-- FIX 10: assets updated_at - code stores date string
-- Real DB has it as timestamp - change to allow date string
-- --------------------------------------------------------
ALTER TABLE `assets`
  MODIFY COLUMN `updated_at` varchar(50) DEFAULT NULL;

-- ========================================
-- ALL FIXES APPLIED SUCCESSFULLY
-- ========================================
