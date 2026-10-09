-- Adds `alt_priest` (alternate / substitute priest) to `source` and `activities`.
-- Shown on Admin > Source only; Add from source copies it to the activities it
-- creates, where it is kept but not shown. Admin > Activities > "Optimise &
-- Review" tries it first when the main priest can't take an activity. Stored as
-- text like `priest`. Optional. Safe to re-run.
--
--   mysql -u USER -p DBNAME < migrate/019_alt_priest.sql

SET @add = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE source ADD COLUMN alt_priest VARCHAR(150) NULL AFTER priest',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'source' AND COLUMN_NAME = 'alt_priest');
PREPARE stmt FROM @add;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE activities ADD COLUMN alt_priest VARCHAR(150) NULL AFTER priest',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activities' AND COLUMN_NAME = 'alt_priest');
PREPARE stmt FROM @add;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
