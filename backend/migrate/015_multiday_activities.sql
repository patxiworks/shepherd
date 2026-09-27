-- 1. Adds `is_multiday` to activity_types: marks the activity types that are
--    inherently multi-day programmes at a venue (retreats, courses, camps —
--    ported from the standalone "Painted Calendar" venue-booking tool) as
--    opposed to the regular single-day activities. It's a flag on the type,
--    not derived from the name at read time, since a multi-day-sounding name
--    doesn't always mean multi-day (e.g. "Mass St. Josemaria" is one day) and
--    some single-day-sounding names (e.g. "crt") span several days here.
--    Seeds it with the activity codes already in use in that tool.
-- 2. Adds the multiday_activities table: like `activities`, but holds a date
--    +time RANGE (start_date/start_time .. end_date/end_time, both freely
--    picked, not tied to the day's from_time/to_time) instead of a single
--    activity_date. Kept separate from `activities` — rather than adding
--    start/end columns there — so the day-to-day Activities view/queries
--    never need to filter these out; they get their own admin view instead
--    (the venue/date-range calendar from the Painted Calendar tool).
--
--   mysql -u USER -p DBNAME < migrate/015_multiday_activities.sql
--
-- Safe to re-run.

SET @add_is_multiday = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE activity_types ADD COLUMN is_multiday TINYINT(1) NOT NULL DEFAULT 0 AFTER name',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_types' AND COLUMN_NAME = 'is_multiday');
PREPARE stmt FROM @add_is_multiday;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Seed the multi-day activity types used by the Painted Calendar tool.
INSERT INTO activity_types (name, is_multiday) VALUES
  ('crt', 1),
  ('ca', 1),
  ('cv', 1),
  ('cve', 1),
  ('cv Univ', 1),
  ('UNIV-cv', 1),
  ('cv-sem', 1),
  ('cv-stgr', 1),
  ('cv-egr', 1),
  ('cv sacd n', 1),
  ('Easter-cv', 1),
  ('Holiday programme: Administration Clubs', 1),
  ('Mass St. Josemaria', 1)
ON DUPLICATE KEY UPDATE is_multiday = 1;

-- ---------------------------------------------------------------------
-- multiday_activities: multi-day venue programmes (formerly the standalone
-- "Painted Calendar" tool's Backend Log). centre / activity / section /
-- labor are text, same convention as activities (see the comment above that
-- table): they store the *name*, not an id, so renaming an entry in the
-- admin cascades to existing rows.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS multiday_activities (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  zone_id INT UNSIGNED NOT NULL,
  centre VARCHAR(150) NULL,
  activity VARCHAR(255) NULL,
  section VARCHAR(20) NULL,
  labor VARCHAR(30) NULL,
  start_date DATE NOT NULL,
  start_time TIME NULL,
  end_date DATE NOT NULL,
  end_time TIME NULL,
  description TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_multiday_activities_zone FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE CASCADE,
  INDEX idx_multiday_activities_zone_start (zone_id, start_date),
  INDEX idx_multiday_activities_centre (centre),
  INDEX idx_multiday_activities_section (section)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
