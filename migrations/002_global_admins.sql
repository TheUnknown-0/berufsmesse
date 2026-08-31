-- ------------------------------------------------------------
-- Global-Administratoren
--
-- Konten der Rolle `admin` werden ausschliesslich im Global-Admin
-- verwaltet (/global-admin/administratoren) und tauchen in der
-- Benutzerliste einer Schule nur auf, wenn das Flag gesetzt ist.
-- ------------------------------------------------------------

ALTER TABLE users
    ADD COLUMN visible_in_school_list TINYINT(1) NOT NULL DEFAULT 0 AFTER role;
