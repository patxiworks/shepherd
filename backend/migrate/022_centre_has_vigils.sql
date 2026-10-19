-- Whether a centre has vigils. Centres without them get no Vigil activities
-- when pulling from source (Add from source, the automatic Vigil rows, the
-- Activities Grid). Defaults to 1 so existing centres behave as before.
-- Safe to re-run.
--
--   mysql -u USER -p DBNAME < migrate/022_centre_has_vigils.sql

SET @add = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE centres ADD COLUMN has_vigils TINYINT(1) NOT NULL DEFAULT 1 AFTER section',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'centres' AND COLUMN_NAME = 'has_vigils');
PREPARE stmt FROM @add;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
