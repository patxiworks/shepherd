-- Adds an index on activities (activity_date, priest), used by the conflict
-- check on the admin Activities page (same priest, same date, overlapping
-- times; matched across zones). Safe to re-run.
--
--   mysql -u USER -p DBNAME < migrate/013_activities_priest_index.sql

SET @add = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE activities ADD INDEX idx_activities_date_priest (activity_date, priest)', 'DO 0')
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activities' AND INDEX_NAME = 'idx_activities_date_priest');
PREPARE stmt FROM @add;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
