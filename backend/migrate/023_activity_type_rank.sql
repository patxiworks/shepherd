-- Rank (display order) of activity types, set with the up / down arrows on
-- Admin > Structure > Activities. Existing types are ranked alphabetically the
-- first time; types added later start at the end (default 1000000) until moved.
-- Safe to re-run (it only ranks when it adds the column).
--
--   mysql -u USER -p DBNAME < migrate/023_activity_type_rank.sql

SET @fresh = (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_types' AND COLUMN_NAME = 'sort_order');
SET @add = IF(@fresh,
    'ALTER TABLE activity_types ADD COLUMN sort_order INT NOT NULL DEFAULT 1000000 AFTER is_multiday',
    'DO 0');
PREPARE stmt FROM @add;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @r = 0;
SET @rank = IF(@fresh,
    'UPDATE activity_types SET sort_order = (@r := @r + 1) ORDER BY name',
    'DO 0');
PREPARE stmt FROM @rank;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
