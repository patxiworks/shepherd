-- Adds the activity_types lookup table behind the Activity dropdown on the
-- admin Activities form, seeded from the activity names already in use.
--
--   mysql -u USER -p DBNAME < migrate/006_activity_types.sql
--
-- Safe to re-run.

CREATE TABLE IF NOT EXISTS activity_types (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO activity_types (name)
SELECT DISTINCT TRIM(activity) FROM activities WHERE TRIM(COALESCE(activity, '')) <> '';
