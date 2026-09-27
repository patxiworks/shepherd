-- Adds the max_masses_per_day setting (Admin > Settings, super admin): the
-- Activities page flags the masses of a priest who has more masses than this
-- on the same day. Default 2.
--
--   mysql -u USER -p DBNAME < migrate/014_max_masses_setting.sql
--
-- Safe to re-run (keeps a value that has already been saved). Without it the
-- default of 2 is used anyway.

INSERT IGNORE INTO settings (name, value) VALUES ('max_masses_per_day', '2');
