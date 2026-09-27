-- Removes the date from `source` (its rows are week + day patterns). Only
-- needed on a database where 007_source.sql was applied before the date was
-- removed from it; safe to re-run.
--
--   mysql -u USER -p DBNAME < migrate/008_source_drop_date.sql

SET @drop = (SELECT IF(COUNT(*) > 0, 'ALTER TABLE source DROP COLUMN activity_date', 'DO 0')
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'source' AND COLUMN_NAME = 'activity_date');
PREPARE stmt FROM @drop;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
