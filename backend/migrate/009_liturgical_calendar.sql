-- Adds the liturgical_calendar table (filled from Admin > Settings > Liturgical
-- calendar by the PHP port of ROMCAL, includes/romcal/) and the default
-- calendar options in `settings`.
--
--   mysql -u USER -p DBNAME < migrate/009_liturgical_calendar.sql
--
-- Safe to re-run.

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

-- ROMCAL's own defaults: Ascension on Thursday, Epiphany on the Sunday,
-- Corpus Christi on the Sunday, optional memorials included.
INSERT IGNORE INTO settings (name, value) VALUES
  ('calendar_ascension_on_sunday', '0'),
  ('calendar_epiphany_on_jan6', '0'),
  ('calendar_corpus_christi_on_thursday', '0'),
  ('calendar_optional_memorials', '1');
