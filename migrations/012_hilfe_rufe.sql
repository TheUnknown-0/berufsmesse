-- Hilfe-Ruf am Messetag.
--
-- help_calls:          ein Ruf aus einem Raum (oder ein Probealarm ohne Raum).
--                      alert_round zählt die Klingel-Runden: neuer Ruf, „Brauche
--                      mehr Hilfe“ und das erneute Klingeln, wenn nach 2 Minuten
--                      niemand zugesagt hat. Die App klingelt, sobald die Runde
--                      steigt. open_room_key erzwingt höchstens einen offenen Ruf
--                      pro Raum (NULL zählt bei UNIQUE nicht). Deshalb hat room_id
--                      keinen Fremdschlüssel (MariaDB erlaubt ON DELETE SET NULL
--                      nicht an der Basis einer berechneten Spalte); room_label
--                      hält den Raumnamen für den Verlauf fest.
-- help_call_responses: „Ich komme“ je Person und Ruf.
-- help_call_duty:      wer gerade mit der App „Im Dienst“ ist (letzte Abfrage).
--
-- Kategorien und das optionale Textfeld liegen pro Schule in den Einstellungen
-- (help_call_categories, help_call_text_enabled), siehe Services\HelpCalls.
-- Wer Räumen zugeteilt ist, steht weiter in teacher_room_assignments; die
-- Spalte teacher_id nimmt seit dieser Version auch Orga-Mitglieder auf.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS help_calls (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id INT UNSIGNED NOT NULL,
    edition_id INT UNSIGNED NOT NULL,
    room_id INT UNSIGNED NULL,
    room_label VARCHAR(255) NULL,
    caller_id INT UNSIGNED NULL,
    category VARCHAR(100) NOT NULL,
    message VARCHAR(200) NULL,
    is_test TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('open','closed') NOT NULL DEFAULT 'open',
    alert_round SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    alert_reason ENUM('new','more','unanswered') NOT NULL DEFAULT 'new',
    alert_at DATETIME NOT NULL,
    more_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    closed_at DATETIME NULL,
    closed_by INT UNSIGNED NULL,
    open_room_key INT UNSIGNED AS (IF(status = 'open', room_id, NULL)) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uq_help_calls_open_room (open_room_key),
    KEY idx_help_calls_school_status (school_id, status),
    KEY idx_help_calls_edition (edition_id, created_at),
    KEY idx_help_calls_room (room_id),
    CONSTRAINT fk_help_calls_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_help_calls_edition FOREIGN KEY (edition_id) REFERENCES messe_editions(id) ON DELETE CASCADE,
    CONSTRAINT fk_help_calls_caller FOREIGN KEY (caller_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_help_calls_closer FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS help_call_responses (
    call_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (call_id, user_id),
    CONSTRAINT fk_help_responses_call FOREIGN KEY (call_id) REFERENCES help_calls(id) ON DELETE CASCADE,
    CONSTRAINT fk_help_responses_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS help_call_duty (
    user_id INT UNSIGNED NOT NULL,
    school_id INT UNSIGNED NOT NULL,
    started_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, school_id),
    KEY idx_help_duty_school (school_id, last_seen_at),
    CONSTRAINT fk_help_duty_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_help_duty_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
