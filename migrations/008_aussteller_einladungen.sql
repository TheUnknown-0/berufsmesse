-- ------------------------------------------------------------
-- Aussteller-Einladungen: Die Schule lädt ein Unternehmen ein, kein Konto
--
-- Bisher legte die Schule beim Einladen selbst ein Konto an (Benutzername
-- frei wählbar) und hängte den Einladungstoken an die Verknüpfung in
-- `exhibitor_users`. Folgen: Schulen sahen und verknüpften Konten anderer
-- Schulen, und wer einen offenen Link hatte, konnte das Passwort eines
-- bereits eingerichteten Kontos neu setzen.
--
-- Jetzt gehört eine Einladung nur zum Unternehmen. Erst wer den Link
-- öffnet, meldet sich mit einem bestehenden Aussteller-Konto an oder legt
-- ein neues an; danach entsteht die Verknüpfung. Ein Link gilt einmal.
-- ------------------------------------------------------------

CREATE TABLE exhibitor_invitations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    exhibitor_id INT UNSIGNED NOT NULL,
    -- NULL, sobald angenommen oder zurückgezogen: Der Link ist dann tot.
    token CHAR(64) NULL,
    expires_at DATETIME NOT NULL,
    -- Freitext nur für die Schule, z. B. an wen der Link ging.
    note VARCHAR(200) NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    accepted_at DATETIME NULL,
    accepted_by INT UNSIGNED NULL,
    -- 1 = beim Annehmen wurde ein neues Konto angelegt (fürs Audit sichtbar)
    created_account TINYINT(1) NOT NULL DEFAULT 0,
    revoked_at DATETIME NULL,
    UNIQUE KEY uq_einv_token (token),
    KEY idx_einv_exhibitor (exhibitor_id),
    CONSTRAINT fk_einv_exhibitor FOREIGN KEY (exhibitor_id) REFERENCES exhibitors(id) ON DELETE CASCADE,
    CONSTRAINT fk_einv_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_einv_acceptor FOREIGN KEY (accepted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Offene Einladungen alter Art übernehmen: Token und Ablauf bleiben gültig,
-- der vorgegebene Benutzername wandert in die Notiz.
INSERT INTO exhibitor_invitations (exhibitor_id, token, expires_at, note, created_at)
SELECT eu.exhibitor_id,
       eu.invite_token,
       COALESCE(eu.invite_expires, NOW() + INTERVAL 14 DAY),
       LEFT(CONCAT('Vorher eingeladen als ', u.username), 200),
       eu.assigned_at
FROM exhibitor_users eu
JOIN users u ON u.id = eu.user_id
WHERE eu.invite_accepted = 0 AND eu.status = 'active' AND eu.invite_token IS NOT NULL;

-- Nicht angenommene Verknüpfungen gibt es im neuen Modell nicht mehr.
DELETE FROM exhibitor_users WHERE invite_accepted = 0;

-- Platzhalter-Konten, die nur für eine Einladung angelegt und nie
-- eingerichtet wurden (kein Passwort, keine Verknüpfung mehr), blockieren
-- sonst den Benutzernamen, den sich der Empfänger aussuchen möchte.
DELETE u FROM users u
WHERE u.role = 'exhibitor'
  AND u.school_id IS NULL
  AND u.password IS NULL
  AND NOT EXISTS (SELECT 1 FROM exhibitor_users eu WHERE eu.user_id = u.id);

ALTER TABLE exhibitor_users
    DROP INDEX uq_eu_invite_token,
    DROP COLUMN invite_token,
    DROP COLUMN invite_expires;

-- ------------------------------------------------------------
-- Portal angleichen: `file_type` enthält überall die Dateiendung.
-- Uploads aus dem Portal hatten bisher den MIME-Typ gespeichert.
-- ------------------------------------------------------------

UPDATE exhibitor_documents
SET file_type = LOWER(SUBSTRING_INDEX(original_name, '.', -1))
WHERE file_type LIKE '%/%' AND original_name LIKE '%.%';
