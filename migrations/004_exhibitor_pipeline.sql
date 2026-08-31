-- ------------------------------------------------------------
-- Aussteller-Pipeline & Jahresbezug
--
-- `previous_exhibitor_id` verkettet dasselbe Unternehmen über die
-- Editionen hinweg (wird beim Klonen einer Edition gesetzt) — Basis für
-- „seit wann dabei“ und den Jahresvergleich.
--
-- `pipeline_status` ist der Akquise-Stand. Er steuert NICHT die
-- Sichtbarkeit — das bleibt `active`; beide werden aber gekoppelt
-- gepflegt (confirmed → sichtbar, declined/cancelled → unsichtbar).
-- ------------------------------------------------------------

ALTER TABLE exhibitors
    ADD COLUMN previous_exhibitor_id INT UNSIGNED NULL AFTER edition_id,
    ADD COLUMN pipeline_status ENUM('lead','contacted','confirmed','declined','cancelled')
        NOT NULL DEFAULT 'confirmed' AFTER active,
    ADD COLUMN follow_up_at DATE NULL AFTER pipeline_status,
    ADD CONSTRAINT fk_exhibitors_previous
        FOREIGN KEY (previous_exhibitor_id) REFERENCES exhibitors(id) ON DELETE SET NULL,
    ADD KEY idx_exhibitors_pipeline (edition_id, pipeline_status);

-- Gesprächsverlauf je Unternehmen: wer hat wann was mit wem besprochen.
CREATE TABLE exhibitor_notes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    exhibitor_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    body VARCHAR(2000) NOT NULL,
    -- Statuswechsel werden als Notiz mitprotokolliert (status_from/to gesetzt)
    status_from ENUM('lead','contacted','confirmed','declined','cancelled') NULL,
    status_to ENUM('lead','contacted','confirmed','declined','cancelled') NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_enotes_exhibitor FOREIGN KEY (exhibitor_id) REFERENCES exhibitors(id) ON DELETE CASCADE,
    CONSTRAINT fk_enotes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    KEY idx_enotes_exhibitor_time (exhibitor_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
