-- Adds role-based access to admin_users: 'super' (everything), 'zone'
-- (scoped to one zone), 'centre' (scoped to one centre). Run this if you
-- already created your database from an earlier copy of schema.sql.
--
--   mysql -u USER -p DBNAME < migrate/002_admin_roles.sql

ALTER TABLE admin_users
  ADD COLUMN role ENUM('super', 'zone', 'centre') NOT NULL DEFAULT 'super' AFTER password_hash,
  ADD COLUMN zone_id INT UNSIGNED NULL AFTER role,
  ADD COLUMN centre_id INT UNSIGNED NULL AFTER zone_id,
  ADD CONSTRAINT fk_admin_users_zone FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE CASCADE,
  ADD CONSTRAINT fk_admin_users_centre FOREIGN KEY (centre_id) REFERENCES centres(id) ON DELETE CASCADE;
