-- Adds the settings table (Admin > Settings) with week_start = 'sunday',
-- and re-derives activities.weekday / activities.week: weekday counts from
-- the start of the week (Sun = 1 ... Sat = 7); week is which occurrence of
-- that day of the week it is in the month (2026-09-20 = 3rd Sunday = 3).
--
--   mysql -u USER -p DBNAME < migrate/005_settings_week_start.sql
--
-- Safe to re-run.

CREATE TABLE IF NOT EXISTS settings (
  name VARCHAR(50) NOT NULL PRIMARY KEY,
  value VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (name, value) VALUES ('week_start', 'sunday');

UPDATE activities
SET weekday = DAYOFWEEK(activity_date),
    week = CEIL(DAYOFMONTH(activity_date) / 7)
WHERE activity_date IS NOT NULL;

UPDATE zones SET last_update = UTC_TIMESTAMP();
