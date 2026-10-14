-- ------------------------------------------------------------
-- Branchen gehören einer Schule
--
-- Bisher war `industries` eine globale Liste, die jede Schule mit dem
-- Recht „Branchen bearbeiten“ umbenennen oder löschen konnte. Eine
-- Umbenennung zog aber nur in der eigenen Edition nach; alle anderen
-- Schulen behielten den alten Namen, der dann als „unbekannt“ auftauchte.
--
-- Jede bestehende Schule bekommt eine eigene Kopie der bisherigen Liste.
-- Die Zuordnung der Aussteller (Namen in exhibitors.categories) bleibt
-- dadurch unverändert gültig.
-- ------------------------------------------------------------

ALTER TABLE industries
    ADD COLUMN school_id INT UNSIGNED NULL AFTER id,
    DROP INDEX uq_industries_name;

INSERT INTO industries (school_id, name, sort_order, created_at)
SELECT s.id, i.name, i.sort_order, i.created_at
FROM schools s
CROSS JOIN industries i
WHERE i.school_id IS NULL;

DELETE FROM industries WHERE school_id IS NULL;

ALTER TABLE industries
    MODIFY COLUMN school_id INT UNSIGNED NOT NULL,
    ADD UNIQUE KEY uq_industries_school_name (school_id, name),
    ADD CONSTRAINT fk_industries_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE;
