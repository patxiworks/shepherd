-- 1. Gives each priest a zone (the Activities form now lists only the
--    priests of the selected zone). Existing priests are assigned to the
--    zone they have the most activities in; review them under
--    Admin > Priests afterwards, and any priest that serves more than one
--    zone will need to be reassigned by hand.
-- 2. Backfills the fields the Activities form now derives instead of asking
--    for: day / weekday / week come from activity_date, section comes from
--    the activity's centre.
--
-- Run ONCE, after 003_lookup_tables.sql (the ALTER is not re-runnable):
--
--   mysql -u USER -p DBNAME < migrate/004_priest_zones_derived_fields.sql

ALTER TABLE priests
  ADD COLUMN zone_id INT UNSIGNED NULL AFTER name,
  ADD CONSTRAINT fk_priests_zone FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE CASCADE;

UPDATE priests p
SET p.zone_id = (
  SELECT a.zone_id FROM activities a
  WHERE a.priest = p.name
  GROUP BY a.zone_id
  ORDER BY COUNT(*) DESC, a.zone_id
  LIMIT 1
)
WHERE p.zone_id IS NULL;

UPDATE activities
SET day = ELT(WEEKDAY(activity_date) + 1, 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'),
    weekday = WEEKDAY(activity_date) + 1,
    week = CEIL(DAYOFMONTH(activity_date) / 7)
WHERE activity_date IS NOT NULL;

UPDATE activities a
JOIN centres c ON c.zone_id = a.zone_id AND c.name = a.centre
SET a.section = c.section
WHERE c.section IS NOT NULL AND NOT (a.section <=> c.section);

UPDATE zones SET last_update = UTC_TIMESTAMP();
