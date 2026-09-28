-- ------------------------------------------------------------
-- Orga-Tool: Teams, Ziele, Organisationspunkte, Chats, Protokolle
--
-- Alles hängt an der Messe-Edition (Orga-Teams gelten pro Jahr).
-- Mitglieder sind ausschließlich Konten mit der Rolle „orga“; die
-- Teamleitung ist ein Merkmal der Mitgliedschaft (is_leader), kein
-- eigenes Recht. Wer was darf, entscheidet allein Services\OrgaAccess.
--
-- Chat und Protokolle kennen drei Räume (channel):
--   team  = ein Team (team_id gesetzt)
--   all   = Orga-Gesamtchat der Edition
--   leads = Leitungsrunde (alle Teamleitungen + Verwaltung)
-- ------------------------------------------------------------

-- Abschlusstermin der Orga-Arbeit (Countdown), je Edition.
CREATE TABLE orga_settings (
    edition_id INT UNSIGNED NOT NULL PRIMARY KEY,
    deadline DATETIME NULL,
    deadline_label VARCHAR(150) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_os_edition FOREIGN KEY (edition_id) REFERENCES messe_editions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orga_teams (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    edition_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    color CHAR(7) NOT NULL DEFAULT '#c96442',
    description TEXT NULL,
    deadline DATETIME NULL, -- eigener Abschlusstermin; NULL = der der Edition
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ot_edition FOREIGN KEY (edition_id) REFERENCES messe_editions(id) ON DELETE CASCADE,
    CONSTRAINT fk_ot_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    KEY idx_ot_edition (edition_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orga_team_members (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    team_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    is_leader TINYINT(1) NOT NULL DEFAULT 0,
    added_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_otm_team_user (team_id, user_id),
    CONSTRAINT fk_otm_team FOREIGN KEY (team_id) REFERENCES orga_teams(id) ON DELETE CASCADE,
    CONSTRAINT fk_otm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    KEY idx_otm_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ziele eines Teams: einzelne Punkte, die die Teamleitung abhakt.
CREATE TABLE orga_goals (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    team_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    due_date DATE NULL,
    is_done TINYINT(1) NOT NULL DEFAULT 0,
    done_at DATETIME NULL,
    done_by INT UNSIGNED NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_og_team FOREIGN KEY (team_id) REFERENCES orga_teams(id) ON DELETE CASCADE,
    CONSTRAINT fk_og_done_by FOREIGN KEY (done_by) REFERENCES users(id) ON DELETE SET NULL,
    KEY idx_og_team (team_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Organisationspunkte (Aufgaben). goal_id und exhibitor_id sind optionale
-- Verknüpfungen; exhibitor_id muss zur Edition des Teams gehören.
CREATE TABLE orga_tasks (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    team_id INT UNSIGNED NOT NULL,
    goal_id INT UNSIGNED NULL,
    exhibitor_id INT UNSIGNED NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    status ENUM('open','in_progress','blocked','done') NOT NULL DEFAULT 'open',
    priority ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
    due_date DATE NULL,
    assignee_id INT UNSIGNED NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_otask_team FOREIGN KEY (team_id) REFERENCES orga_teams(id) ON DELETE CASCADE,
    CONSTRAINT fk_otask_goal FOREIGN KEY (goal_id) REFERENCES orga_goals(id) ON DELETE SET NULL,
    CONSTRAINT fk_otask_exhibitor FOREIGN KEY (exhibitor_id) REFERENCES exhibitors(id) ON DELETE SET NULL,
    CONSTRAINT fk_otask_assignee FOREIGN KEY (assignee_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_otask_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    KEY idx_otask_team_status (team_id, status),
    KEY idx_otask_assignee (assignee_id, status),
    KEY idx_otask_exhibitor (exhibitor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orga_task_checklist (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    task_id INT UNSIGNED NOT NULL,
    label VARCHAR(300) NOT NULL,
    is_done TINYINT(1) NOT NULL DEFAULT 0,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_otc_task FOREIGN KEY (task_id) REFERENCES orga_tasks(id) ON DELETE CASCADE,
    KEY idx_otc_task (task_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orga_task_comments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    task_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_otcm_task FOREIGN KEY (task_id) REFERENCES orga_tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_otcm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    KEY idx_otcm_task (task_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Beschlüsse und Protokolle je Raum (Team, Gesamt, Leitungsrunde).
CREATE TABLE orga_notes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    edition_id INT UNSIGNED NOT NULL,
    channel ENUM('team','all','leads') NOT NULL,
    team_id INT UNSIGNED NULL,
    kind ENUM('protocol','decision') NOT NULL DEFAULT 'protocol',
    title VARCHAR(200) NOT NULL,
    body MEDIUMTEXT NOT NULL,
    meeting_date DATE NULL,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_on_edition FOREIGN KEY (edition_id) REFERENCES messe_editions(id) ON DELETE CASCADE,
    CONSTRAINT fk_on_team FOREIGN KEY (team_id) REFERENCES orga_teams(id) ON DELETE CASCADE,
    CONSTRAINT fk_on_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_on_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    KEY idx_on_space (edition_id, channel, team_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chatnachrichten. updated_at (Millisekunden) treibt den Abgleich per
-- Polling: Bearbeiten, Löschen, Anheften und Reaktionen setzen ihn neu.
-- Gelöschte Nachrichten bleiben als Platzhalter stehen (deleted_at).
CREATE TABLE orga_messages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    edition_id INT UNSIGNED NOT NULL,
    channel ENUM('team','all','leads') NOT NULL,
    team_id INT UNSIGNED NULL,
    user_id INT UNSIGNED NULL,
    reply_to_id INT UNSIGNED NULL,
    body TEXT NOT NULL,
    attachment_file VARCHAR(64) NULL,
    attachment_name VARCHAR(255) NULL,
    attachment_mime VARCHAR(100) NULL,
    attachment_size INT UNSIGNED NULL,
    is_pinned TINYINT(1) NOT NULL DEFAULT 0,
    edited_at DATETIME NULL,
    edited_by INT UNSIGNED NULL,
    deleted_at DATETIME NULL,
    deleted_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    CONSTRAINT fk_om_edition FOREIGN KEY (edition_id) REFERENCES messe_editions(id) ON DELETE CASCADE,
    CONSTRAINT fk_om_team FOREIGN KEY (team_id) REFERENCES orga_teams(id) ON DELETE CASCADE,
    CONSTRAINT fk_om_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_om_reply FOREIGN KEY (reply_to_id) REFERENCES orga_messages(id) ON DELETE SET NULL,
    CONSTRAINT fk_om_editor FOREIGN KEY (edited_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_om_deleter FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
    KEY idx_om_space (edition_id, channel, team_id, id),
    KEY idx_om_space_updated (edition_id, channel, team_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orga_message_reactions (
    message_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    emoji VARCHAR(16) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (message_id, user_id, emoji),
    CONSTRAINT fk_omr_message FOREIGN KEY (message_id) REFERENCES orga_messages(id) ON DELETE CASCADE,
    CONSTRAINT fk_omr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lesestand je Nutzer und Raum (space_key: 'all', 'leads', 't<team_id>').
CREATE TABLE orga_chat_reads (
    user_id INT UNSIGNED NOT NULL,
    edition_id INT UNSIGNED NOT NULL,
    space_key VARCHAR(20) NOT NULL,
    last_read_id INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, edition_id, space_key),
    CONSTRAINT fk_ocr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ocr_edition FOREIGN KEY (edition_id) REFERENCES messe_editions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
