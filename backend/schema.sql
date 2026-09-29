-- Pastores MySQL schema
-- Replaces the Google Sheets datasource. Designed to preserve the JSON
-- contract the Next.js frontend already expects (see src/types/index.ts
-- in the main project) so frontend changes stay minimal.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- zones: top-level grouping used for login and data filtering
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS zones (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  last_update DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- centres: pastoral centres, each belongs to a zone
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS centres (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  zone_id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  section VARCHAR(20) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_centre_zone (zone_id, name),
  CONSTRAINT fk_centres_zone FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- users: zone login accounts (formerly rows in the "users" sheet)
-- passcode is stored hashed; case-insensitivity is preserved by always
-- lower-casing the passcode before hashing/verifying (see includes/auth.php)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  zone_id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  centre VARCHAR(150) NULL,
  section VARCHAR(20) NULL,
  passcode_hash VARCHAR(255) NOT NULL,
  role VARCHAR(20) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_zone FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- activities: the pastoral schedule (formerly the "activities" sheet)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS activities (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  zone_id INT UNSIGNED NOT NULL,
  unit VARCHAR(100) NULL,
  week SMALLINT NULL,
  day VARCHAR(20) NULL,
  weekday TINYINT NULL,
  activity_date DATE NULL,
  centre VARCHAR(150) NULL,
  activity VARCHAR(255) NULL,
  section VARCHAR(20) NULL,
  labor VARCHAR(30) NULL,
  from_time TIME NULL,
  to_time TIME NULL,
  duration TIME NULL,
  mfrequency SMALLINT NULL,
  priest VARCHAR(150) NULL,
  description TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_activities_zone FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE CASCADE,
  INDEX idx_activities_zone_date (zone_id, activity_date),
  INDEX idx_activities_section (section),
  INDEX idx_activities_centre (centre),
  INDEX idx_activities_date_priest (activity_date, priest)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- source: like activities (Admin > Source, super admin only), except `day`
-- is the numeric day of the week (1 = first day of the week per the
-- week_start setting; Sunday start: Mon = 2, Tue = 3, ...), there is no
-- separate `weekday` column, and there is no date: `week` (nth occurrence of
-- the day in the month) and `day` are entered by hand.
-- ---------------------------------------------------------------------
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

-- ---------------------------------------------------------------------
-- masses: date -> {Class, Mass} lookup (formerly the "masses" sheet)
-- zone_id is nullable: current frontend fetches masses without a zone
-- filter, so NULL rows are treated as global/shared across zones.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS masses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  zone_id INT UNSIGNED NULL,
  mass_date DATE NOT NULL,
  class VARCHAR(100) NULL,
  mass VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_masses_zone FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_mass_zone_date (zone_id, mass_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- absences: when a priest is away (retreat, holiday, ...). Managed under
-- Admin > Absences (super and zone admins). Like activities.priest, `priest`
-- and `activity` hold the name as text, not a foreign key.
-- ---------------------------------------------------------------------
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

-- ---------------------------------------------------------------------
-- priests / sections / labors / activity_types: lookup lists behind the dropdowns on the
-- admin Activities form. Managed (super admin only) under Admin > Priests,
-- Sections and Labors. activities.priest/section/labor store the *name*
-- (not an id), same as `centre`, so the frontend's JSON contract is
-- unchanged; renaming an entry in the admin cascades to existing rows.
-- ---------------------------------------------------------------------
-- A priest belongs to one zone; the Activities form only lists the priests
-- of the activity's zone.
CREATE TABLE IF NOT EXISTS priests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL UNIQUE,
  zone_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_priests_zone FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A priest can also serve in other zones (e.g. a temporary transfer): those
-- zones are listed here, and the priest's own zone is priests.zone_id. The
-- priests "of" a zone are the ones with that zone_id plus the ones listed
-- here for it (see priests_by_zone() in includes/functions.php).
CREATE TABLE IF NOT EXISTS priest_zones (
  priest_id INT UNSIGNED NOT NULL,
  zone_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (priest_id, zone_id),
  CONSTRAINT fk_priest_zones_priest FOREIGN KEY (priest_id) REFERENCES priests(id) ON DELETE CASCADE,
  CONSTRAINT fk_priest_zones_zone FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE CASCADE
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

-- activity_types: the list behind the Activity dropdown (Mass, Conf, ...).
-- activities.activity stores the name, like priest/section/labor.
-- is_multiday: marks a type as an inherently multi-day venue programme
-- (retreat, course, camp — see multiday_activities below) rather than a
-- regular single-day activity. It's a flag on the type, not derived from
-- the name at read time.
CREATE TABLE IF NOT EXISTS activity_types (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL UNIQUE,
  is_multiday TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Keep in sync with src/lib/section-colors.ts in the Next.js app.
INSERT IGNORE INTO sections (name) VALUES ('sf'), ('sv'), ('c-m'), ('c-w'), ('p-m'), ('p-w');
INSERT IGNORE INTO labors (name) VALUES ('sm'), ('sg'), ('sr'), ('sm agd'), ('sr club'), ('seminarians'), ('priests');

-- Multi-day activity types, ported from the standalone "Painted Calendar"
-- venue-booking tool (see migrate/015_multiday_activities.sql).
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
-- "Painted Calendar" tool's Backend Log) — retreats, courses and camps that
-- run across several days at a centre/venue. centre / activity / section /
-- labor are text, same convention as activities: they store the *name*, not
-- an id, so renaming an entry in the admin cascades to existing rows. Kept
-- as its own table (rather than adding start/end columns to `activities`)
-- so the day-to-day Activities view never needs to filter these out; they
-- get their own admin view (a venue/date-range calendar) instead.
-- start_date/start_time and end_date/end_time are picked freely per entry,
-- unlike activities.from_time/to_time which describe a single day.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS multiday_activities (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  zone_id INT UNSIGNED NOT NULL,
  centre VARCHAR(150) NULL,
  activity VARCHAR(255) NULL,
  section VARCHAR(20) NULL,
  labor VARCHAR(30) NULL,
  priest VARCHAR(150) NULL,
  start_date DATE NOT NULL,
  start_time TIME NULL,
  end_date DATE NOT NULL,
  end_time TIME NULL,
  description TEXT NULL,
  -- how the dates move on "Roll forward" (see migrate/017_multiday_roll_rule.sql)
  roll_rule ENUM('exact','weekend','flexible') NOT NULL DEFAULT 'flexible',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_multiday_activities_zone FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE CASCADE,
  INDEX idx_multiday_activities_zone_start (zone_id, start_date),
  INDEX idx_multiday_activities_centre (centre),
  INDEX idx_multiday_activities_section (section)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- settings: admin-editable options (Admin > Settings).
-- week_start: 'sunday' | 'monday' -- which day the week starts on, used to
-- derive activities.weekday / activities.week from the date.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  name VARCHAR(50) NOT NULL PRIMARY KEY,
  value VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (name, value) VALUES ('week_start', 'sunday');
-- max_masses_per_day: masses per priest per day above which the Activities page
-- flags them (default 2).
INSERT IGNORE INTO settings (name, value) VALUES ('max_masses_per_day', '2');

-- ---------------------------------------------------------------------
-- liturgical_calendar: one row per day, filled by Admin > Settings >
-- Liturgical calendar ("Generate calendar", super admin only) using the PHP
-- port of ROMCAL in includes/romcal/. Each run replaces the next 12 months.
--   class            A-E from includes/romcal/fixed.dat (Sundays default to C)
--   liturgical_rank  Solemnity, Feast, Memorial, Optional memorial, Sunday, ...
--   notes            the rest of the fixed.dat text for the day, ' | ' separated
--   votive/devotion  the weekday's votive Mass and Psalm 2 / Adoro te / Salve
-- The calendar options (Ascension, Epiphany, Corpus Christi, optional
-- memorials) live in `settings` as calendar_* (see migrate/009).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS liturgical_calendar (
  cal_date DATE NOT NULL PRIMARY KEY,
  celebration VARCHAR(255) NOT NULL,
  `class` CHAR(1) NULL,
  liturgical_rank VARCHAR(30) NOT NULL,
  color VARCHAR(10) NOT NULL,
  season VARCHAR(20) NOT NULL,
  notes TEXT NULL,
  votive VARCHAR(60) NULL,
  devotion VARCHAR(20) NULL,
  generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (name, value) VALUES
  ('calendar_ascension_on_sunday', '0'),
  ('calendar_epiphany_on_jan6', '0'),
  ('calendar_corpus_christi_on_thursday', '0'),
  ('calendar_optional_memorials', '1');

-- ---------------------------------------------------------------------
-- admin_users: login accounts for the PHP admin backend (separate from
-- the zone/passcode login used by the Next.js frontend)
--
-- role: 'super' sees/manages everything; 'zone' is scoped to zone_id
-- (all centres/users/activities/masses within that zone); 'centre' is
-- scoped to centre_id (only users/activities matching that centre's name,
-- within centre_id's own zone). zone_id/centre_id are ignored for 'super'
-- and enforced server-side regardless of what a form submits.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admin_users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('super', 'zone', 'centre') NOT NULL DEFAULT 'super',
  zone_id INT UNSIGNED NULL,
  centre_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_admin_users_zone FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE CASCADE,
  CONSTRAINT fk_admin_users_centre FOREIGN KEY (centre_id) REFERENCES centres(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- Seed data (adjust/remove before going live)
-- ---------------------------------------------------------------------
-- INSERT INTO zones (name) VALUES ('Zone 1');
--
-- Create the first admin user with:
--   php -r "echo password_hash('changeme', PASSWORD_DEFAULT), PHP_EOL;"
-- then:
-- INSERT INTO admin_users (username, password_hash) VALUES ('admin', '<hash from above>');
