-- ------------------------------------------------------------
-- Feedback-Bögen (Google-Forms-artig)
--
-- Ein Bogen gehört zu einer Messe-Edition. Zielgruppen werden je Bogen
-- gesetzt; die Freischaltung läuft über Status + optionales Zeitfenster.
-- Anonymität ist je Bogen wählbar: bei anonymen Bögen hält
-- feedback_participants NUR fest, DASS jemand abgegeben hat — die
-- Antworten selbst sind dann keiner Person zugeordnet.
-- ------------------------------------------------------------

CREATE TABLE feedback_forms (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    edition_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    status ENUM('draft','open','closed') NOT NULL DEFAULT 'draft',
    opens_at DATETIME NULL,  -- NULL = ab sofort (sobald Status = open)
    closes_at DATETIME NULL, -- NULL = kein Ende
    is_anonymous TINYINT(1) NOT NULL DEFAULT 1,
    audience_students TINYINT(1) NOT NULL DEFAULT 1,
    audience_teachers TINYINT(1) NOT NULL DEFAULT 0,
    audience_exhibitors TINYINT(1) NOT NULL DEFAULT 0,
    thank_you_text VARCHAR(1000) NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ff_edition FOREIGN KEY (edition_id) REFERENCES messe_editions(id) ON DELETE CASCADE,
    CONSTRAINT fk_ff_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    KEY idx_ff_edition_status (edition_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE feedback_questions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    form_id INT UNSIGNED NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    type ENUM('short_text','long_text','single_choice','multi_choice','dropdown','scale','yes_no') NOT NULL DEFAULT 'short_text',
    label VARCHAR(500) NOT NULL,
    help_text VARCHAR(500) NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    scale_min TINYINT UNSIGNED NOT NULL DEFAULT 1,
    scale_max TINYINT UNSIGNED NOT NULL DEFAULT 5,
    scale_min_label VARCHAR(100) NULL,
    scale_max_label VARCHAR(100) NULL,
    CONSTRAINT fk_fq_form FOREIGN KEY (form_id) REFERENCES feedback_forms(id) ON DELETE CASCADE,
    KEY idx_fq_form_order (form_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE feedback_options (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    question_id INT UNSIGNED NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    label VARCHAR(300) NOT NULL,
    CONSTRAINT fk_fo_question FOREIGN KEY (question_id) REFERENCES feedback_questions(id) ON DELETE CASCADE,
    KEY idx_fo_question_order (question_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- user_id ist bei anonymen Bögen immer NULL.
CREATE TABLE feedback_responses (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    form_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    role ENUM('student','teacher','exhibitor','orga','school_admin','admin') NOT NULL DEFAULT 'student',
    class VARCHAR(50) NULL,
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_fr_form FOREIGN KEY (form_id) REFERENCES feedback_forms(id) ON DELETE CASCADE,
    CONSTRAINT fk_fr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    KEY idx_fr_form (form_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mehrfachauswahl erzeugt mehrere Zeilen je (response, question) —
-- deshalb bewusst KEIN UNIQUE-Index darauf.
CREATE TABLE feedback_answers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    response_id INT UNSIGNED NOT NULL,
    question_id INT UNSIGNED NOT NULL,
    option_id INT UNSIGNED NULL,   -- gesetzt bei Auswahl-Fragen
    value_text TEXT NULL,          -- Freitext
    value_number SMALLINT NULL,    -- Skala, Ja/Nein (1/0)
    CONSTRAINT fk_fa_response FOREIGN KEY (response_id) REFERENCES feedback_responses(id) ON DELETE CASCADE,
    CONSTRAINT fk_fa_question FOREIGN KEY (question_id) REFERENCES feedback_questions(id) ON DELETE CASCADE,
    CONSTRAINT fk_fa_option FOREIGN KEY (option_id) REFERENCES feedback_options(id) ON DELETE CASCADE,
    KEY idx_fa_question (question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hält nur fest, DASS jemand abgegeben hat (verhindert Mehrfachabgabe,
-- auch bei anonymen Bögen — ohne Verknüpfung zu den Antworten).
CREATE TABLE feedback_participants (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    form_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fp_form_user (form_id, user_id),
    CONSTRAINT fk_fp_form FOREIGN KEY (form_id) REFERENCES feedback_forms(id) ON DELETE CASCADE,
    CONSTRAINT fk_fp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
