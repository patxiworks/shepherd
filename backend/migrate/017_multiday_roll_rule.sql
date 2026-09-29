-- Adds `roll_rule` to multiday_activities: how the entry's dates move when the
-- year is rolled forward (List tab > Roll forward), as in the original Painted
-- Calendar tool's "Roll Rule" column:
--   exact    - the same calendar date next year
--   weekend  - the nearest same weekday, roughly 52 weeks later
--   flexible - as weekend (the default)
-- Safe to re-run.
--
--   mysql -u USER -p DBNAME < migrate/017_multiday_roll_rule.sql

SET @add = (SELECT IF(COUNT(*) = 0,
    "ALTER TABLE multiday_activities ADD COLUMN roll_rule ENUM('exact','weekend','flexible') NOT NULL DEFAULT 'flexible' AFTER description",
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'multiday_activities' AND COLUMN_NAME = 'roll_rule');
PREPARE stmt FROM @add;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
