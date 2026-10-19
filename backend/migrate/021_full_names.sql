-- Full name of a centre; full name and initials of a priest. All optional text,
-- shown on Admin > Centres / Priests. `name` stays the short name that
-- activities, source etc. store. Safe to re-run.
--
--   mysql -u USER -p DBNAME < migrate/021_full_names.sql

SET @add = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE centres ADD COLUMN full_name VARCHAR(255) NULL AFTER name',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'centres' AND COLUMN_NAME = 'full_name');
PREPARE stmt FROM @add;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE priests ADD COLUMN full_name VARCHAR(255) NULL AFTER name',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'priests' AND COLUMN_NAME = 'full_name');
PREPARE stmt FROM @add;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE priests ADD COLUMN initials VARCHAR(20) NULL AFTER full_name',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'priests' AND COLUMN_NAME = 'initials');
PREPARE stmt FROM @add;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
