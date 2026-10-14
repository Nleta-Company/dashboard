-- SQL to update the maintenance job date from 01 Apr 2026 to 20 Apr 2026
-- Run this in phpMyAdmin on your InfinityFree database

-- Update the created_at for the specific maintenance job
UPDATE maintenance_jobs 
SET created_at = '2026-04-20 21:38:00' 
WHERE DATE(created_at) = '2026-04-01';

-- Verify the change
SELECT id, asset_id, created_at, status 
FROM maintenance_jobs 
WHERE DATE(created_at) = '2026-04-20';