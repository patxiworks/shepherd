-- Labores and sub-labores, and the labores each centre runs.
--
--   labores         a labor (parent_id NULL, e.g. "sm") or one of its sub-labores
--                   (parent_id = the labor's id, e.g. "agd"). One level of nesting.
--   centre_labores  the labores a centre runs. labor_id is the most specific
--                   entry: a sub-labor, or a labor that has no sub-labores.
--   sections.colour the section's colour (#rrggbb), shown in the admin and
--                   served to the public app by api/sections.php.
--
-- activities/source/multiday_activities.labor keep storing text: the labor's
-- name, followed by the sub-labor's when it has one ("sm agd"). The old,
-- flat `labors` table is dropped; its values are not carried over. Safe to re-run.
--
--   mysql -u USER -p DBNAME < migrate/020_labores.sql

CREATE TABLE IF NOT EXISTS labores (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(30) NOT NULL,
  parent_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_labor_parent (parent_id, name),
  CONSTRAINT fk_labores_parent FOREIGN KEY (parent_id) REFERENCES labores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS centre_labores (
  centre_id INT UNSIGNED NOT NULL,
  labor_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (centre_id, labor_id),
  CONSTRAINT fk_centre_labores_centre FOREIGN KEY (centre_id) REFERENCES centres(id) ON DELETE CASCADE,
  CONSTRAINT fk_centre_labores_labor FOREIGN KEY (labor_id) REFERENCES labores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @add = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE sections ADD COLUMN colour VARCHAR(7) NULL AFTER name',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sections' AND COLUMN_NAME = 'colour');
PREPARE stmt FROM @add;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


DROP TABLE IF EXISTS labors;

-- "labor sublabor" can be longer than a single name (30 + space + 30).
ALTER TABLE activities MODIFY labor VARCHAR(61) NULL;
ALTER TABLE source MODIFY labor VARCHAR(61) NULL;
ALTER TABLE multiday_activities MODIFY labor VARCHAR(61) NULL;
