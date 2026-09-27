-- Adds the absences table (priests' absences, managed under Admin > Absences
-- by super and zone admins).
--
--   mysql -u USER -p DBNAME < migrate/012_absences.sql
--
-- Safe to re-run.

CREATE TABLE IF NOT EXISTS absences (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  zone_id INT UNSIGNED NOT NULL,
  priest VARCHAR(150) NOT NULL,
  start_at DATETIME NOT NULL,
  end_at DATETIME NOT NULL,
  activity VARCHAR(150) NULL,
  description TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_absences_zone FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE CASCADE,
  KEY idx_absences_zone_start (zone_id, start_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
