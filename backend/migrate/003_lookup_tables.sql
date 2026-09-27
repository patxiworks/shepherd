-- Adds the priests / sections / labors lookup tables that feed the
-- dropdowns on the admin Activities form, seeds them from the values
-- already in use, and backfills activities.weekday / activities.week
-- (which the admin form now derives automatically). Run this if you
-- created your database from an earlier copy of schema.sql.
--
--   mysql -u USER -p DBNAME < migrate/003_lookup_tables.sql
--
-- Safe to re-run.

CREATE TABLE IF NOT EXISTS priests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sections (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(20) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS labors (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(30) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Known values (mirrors src/lib/section-colors.ts)...
INSERT IGNORE INTO sections (name) VALUES ('sf'), ('sv'), ('c-m'), ('c-w'), ('p-m'), ('p-w');
INSERT IGNORE INTO labors (name) VALUES ('sm'), ('sg'), ('sr'), ('sm agd'), ('sr club'), ('seminarians'), ('priests');

-- ...plus anything already used in existing rows.
INSERT IGNORE INTO sections (name) SELECT DISTINCT TRIM(section) FROM activities WHERE TRIM(COALESCE(section, '')) <> '';
INSERT IGNORE INTO sections (name) SELECT DISTINCT TRIM(section) FROM centres WHERE TRIM(COALESCE(section, '')) <> '';
INSERT IGNORE INTO sections (name) SELECT DISTINCT TRIM(section) FROM users WHERE TRIM(COALESCE(section, '')) <> '';
INSERT IGNORE INTO labors (name) SELECT DISTINCT TRIM(labor) FROM activities WHERE TRIM(COALESCE(labor, '')) <> '';
INSERT IGNORE INTO priests (name) SELECT DISTINCT TRIM(priest) FROM activities WHERE TRIM(COALESCE(priest, '')) <> '';

-- Backfill derived fields. weekday: Mon=1 ... Sun=7 (from `day`, which is
-- stored as a 3-letter abbreviation). week: week of the month the
-- activity date falls in (days 1-7 = 1, 8-14 = 2, ...).
UPDATE activities
SET weekday = FIELD(day, 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun')
WHERE day IS NOT NULL AND FIELD(day, 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun') > 0;

UPDATE activities
SET week = CEIL(DAYOFMONTH(activity_date) / 7)
WHERE activity_date IS NOT NULL;
