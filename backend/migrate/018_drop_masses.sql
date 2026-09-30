-- Removes the `masses` table (the manual date -> {Class, Mass} lookup), its admin
-- page and API endpoint. Safe to re-run.
DROP TABLE IF EXISTS masses;
