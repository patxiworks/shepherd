-- Adds the `source` table (Admin > Source, super admin only): the columns of
-- `activities` without the date, except that `day` holds the numeric day of the
-- week (1 = the first day of the week per Admin > Settings, so with a Sunday
-- start Mon = 2, Tue = 3 ...) and the separate `weekday` column is not needed.
--
--   mysql -u USER -p DBNAME < migrate/007_source.sql
--
-- Safe to re-run.

CREATE TABLE IF NOT EXISTS source (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  zone_id INT UNSIGNED NOT NULL,
  week SMALLINT NULL,
  day TINYINT NULL,
  centre VARCHAR(150) NULL,
  activity VARCHAR(255) NULL,
  section VARCHAR(20) NULL,
  labor VARCHAR(30) NULL,
  from_time TIME NULL,
  to_time TIME NULL,
  duration TIME NULL,
  mfrequency DECIMAL(4,2) NULL,
  priest VARCHAR(150) NULL,
  description TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_source_zone FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE CASCADE,
  INDEX idx_source_zone (zone_id),
  INDEX idx_source_section (section),
  INDEX idx_source_centre (centre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- mfrequency can be fractional (0.33 = once every three weeks); safe to re-run.
ALTER TABLE source MODIFY mfrequency DECIMAL(4,2) NULL;
