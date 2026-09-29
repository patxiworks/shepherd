-- Adds `priest` to multiday_activities: the priest in charge of the programme
-- (a name from the zone's priests, stored as text like activities.priest, so
-- renaming a priest cascades to it). Optional. Safe to re-run.
--
--   mysql -u USER -p DBNAME < migrate/016_multiday_priest.sql

SET @add = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE multiday_activities ADD COLUMN priest VARCHAR(150) NULL AFTER labor',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'multiday_activities' AND COLUMN_NAME = 'priest');
PREPARE stmt FROM @add;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
