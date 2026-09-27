-- Makes `source` agree with the lookup tables. Its centre / priest / activity
-- / section values are text (like activities'), so anything used there must
-- exist in centres / priests / activity_types / sections:
--   * adds the centres, priests and activity types found in source but
--     missing from those tables (centres get the section used in source,
--     priests the zone of the source rows);
--   * sets source.section from the row's centre (the same rule as
--     activities);
--   * drops source.unit, a copy of the zone's name that would go stale when
--     a zone is renamed (the zone is source.zone_id).
--
--   mysql -u USER -p DBNAME < migrate/010_source_integrity.sql
--
-- Safe to re-run. A priest name is unique across zones, so a source row whose
-- priest already belongs to another zone (e.g. "Fr. Theo") can't be fixed
-- automatically: the last query lists them, and they must be sorted out by
-- hand (rename the priest, or change/clear it on the source rows).

INSERT IGNORE INTO centres (zone_id, name, section)
SELECT zone_id, centre, MAX(section) FROM source
WHERE centre IS NOT NULL AND centre <> ''
GROUP BY zone_id, centre;

INSERT IGNORE INTO priests (name, zone_id)
SELECT priest, MIN(zone_id) FROM source
WHERE priest IS NOT NULL AND priest <> ''
GROUP BY priest;

INSERT IGNORE INTO activity_types (name)
SELECT DISTINCT activity FROM source WHERE activity IS NOT NULL AND activity <> '';

UPDATE source s
JOIN centres c ON c.zone_id = s.zone_id AND c.name = s.centre
SET s.section = c.section
WHERE NOT (s.section <=> c.section);

SET @drop = (SELECT IF(COUNT(*) > 0, 'ALTER TABLE source DROP COLUMN unit', 'DO 0')
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'source' AND COLUMN_NAME = 'unit');
PREPARE stmt FROM @drop;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Source rows still pointing at a priest of another zone (should return nothing):
SELECT s.zone_id, s.priest, COUNT(*) AS source_rows
FROM source s
LEFT JOIN priests p ON p.name = s.priest AND p.zone_id = s.zone_id
WHERE s.priest IS NOT NULL AND s.priest <> '' AND p.id IS NULL
GROUP BY s.zone_id, s.priest;
