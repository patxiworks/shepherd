-- A priest can serve in more than one zone (e.g. on a temporary transfer):
-- priests.zone_id stays their home zone, and priest_zones lists the other
-- zones they also serve in. Wherever "the priests of a zone" are needed
-- (Activities / Source dropdowns and validation) that means the priests whose
-- home is the zone plus the ones listed here for it.
--
--   mysql -u USER -p DBNAME < migrate/011_priest_zones.sql
--
-- Safe to re-run. It also lists the zones that existing source rows already
-- use a priest in, so those rows stay valid.

CREATE TABLE IF NOT EXISTS priest_zones (
  priest_id INT UNSIGNED NOT NULL,
  zone_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (priest_id, zone_id),
  CONSTRAINT fk_priest_zones_priest FOREIGN KEY (priest_id) REFERENCES priests(id) ON DELETE CASCADE,
  CONSTRAINT fk_priest_zones_zone FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO priest_zones (priest_id, zone_id)
SELECT DISTINCT p.id, s.zone_id
FROM source s
JOIN priests p ON p.name = s.priest
WHERE p.zone_id <> s.zone_id;
