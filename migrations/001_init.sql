-- ============================================================
-- Berufsmesse — Initiales Schema (Neubau)
-- Rekonstruiert und bereinigt aus dem Original-Datenmodell.
-- Konventionen: InnoDB, utf8mb4, FKs mit expliziten Lösch-Regeln.
-- Anmerkung zu UNIQUE mit NULL-Spalten: MariaDB behandelt NULL als
-- verschieden; wo Eindeutigkeit auch für NULL gelten muss, wird eine
-- generierte Spalte (COALESCE(...,0)) mit in den Index genommen.
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- Mandanten & Editionen
-- ------------------------------------------------------------

CREATE TABLE schools (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    logo VARCHAR(255) NULL,
    address VARCHAR(500) NULL,
    contact_email VARCHAR(255) NULL,
    contact_phone VARCHAR(50) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_schools_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Messe-Jahrgänge. Single Source of Truth für Einschreibezeitraum,
-- Veranstaltungsdatum und Anmelde-Maximum (im Original zusätzlich in
-- settings dupliziert — hier bewusst nur noch hier).
CREATE TABLE messe_editions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    school_id INT UNSIGNED NOT NULL,
    name VARCHAR(200) NOT NULL,
    year SMALLINT UNSIGNED NOT NULL,
    status ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
    registration_start DATETIME NULL,
    registration_end DATETIME NULL,
    event_date DATE NULL,
    max_registrations_per_student TINYINT UNSIGNED NOT NULL DEFAULT 3,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_editions_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    KEY idx_editions_school_status (school_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Benutzer & Rechte
-- ------------------------------------------------------------

-- school_id NULL = globaler Nutzer (Rolle admin oder exhibitor).
-- edition_id bindet Schüler/Lehrer-Konten an einen Jahrgang.
CREATE TABLE users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    school_id INT UNSIGNED NULL,
    edition_id INT UNSIGNED NULL,
    username VARCHAR(100) NOT NULL,
    email VARCHAR(255) NULL,
    password VARCHAR(255) NULL, -- NULL = Konto ohne Passwort (Login gesperrt, Passwort kommt z. B. aus Zugangsdaten-PDF)
    firstname VARCHAR(100) NOT NULL DEFAULT '',
    lastname VARCHAR(100) NOT NULL DEFAULT '',
    class VARCHAR(50) NULL,
    role ENUM('admin','school_admin','orga','teacher','student','exhibitor') NOT NULL DEFAULT 'student',
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    school_key INT UNSIGNED AS (COALESCE(school_id, 0)) STORED,
    UNIQUE KEY uq_users_username_school (username, school_key),
    CONSTRAINT fk_users_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_users_edition FOREIGN KEY (edition_id) REFERENCES messe_editions(id) ON DELETE SET NULL,
    KEY idx_users_school_role (school_id, role),
    KEY idx_users_class (school_id, class)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_permissions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    permission VARCHAR(50) NOT NULL,
    granted_by INT UNSIGNED NULL,
    granted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_permission (user_id, permission),
    CONSTRAINT fk_uperm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_uperm_granter FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permission_groups (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    school_id INT UNSIGNED NULL, -- NULL = systemweite Vorlage
    name VARCHAR(100) NOT NULL,
    description VARCHAR(500) NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    school_key INT UNSIGNED AS (COALESCE(school_id, 0)) STORED,
    UNIQUE KEY uq_pgroups_name (name, school_key),
    CONSTRAINT fk_pgroups_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_pgroups_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permission_group_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id INT UNSIGNED NOT NULL,
    permission VARCHAR(50) NOT NULL,
    UNIQUE KEY uq_pgroup_item (group_id, permission),
    CONSTRAINT fk_pgitems_group FOREIGN KEY (group_id) REFERENCES permission_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_permission_groups (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    group_id INT UNSIGNED NOT NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_upgroup (user_id, group_id),
    CONSTRAINT fk_upgroups_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_upgroups_group FOREIGN KEY (group_id) REFERENCES permission_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Räume & Zeitslots
-- ------------------------------------------------------------

CREATE TABLE rooms (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    edition_id INT UNSIGNED NOT NULL,
    room_number VARCHAR(50) NOT NULL,
    room_name VARCHAR(200) NULL,
    building VARCHAR(100) NULL,
    floor VARCHAR(50) NULL,
    capacity SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    equipment VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rooms_edition FOREIGN KEY (edition_id) REFERENCES messe_editions(id) ON DELETE CASCADE,
    KEY idx_rooms_edition (edition_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- is_managed = fester Zuteilungsslot (Voranmeldung + Auto-Zuteilung);
-- nicht-managed Slots sind freie Wahl vor Ort (Check-in schreibt ein).
-- Freie Slots werden IMMER hierüber bestimmt, nie hartcodiert.
CREATE TABLE timeslots (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    edition_id INT UNSIGNED NOT NULL,
    slot_number TINYINT UNSIGNED NOT NULL,
    slot_name VARCHAR(100) NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    is_managed TINYINT(1) NOT NULL DEFAULT 1,
    is_break TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_timeslots_number (edition_id, slot_number),
    CONSTRAINT fk_timeslots_edition FOREIGN KEY (edition_id) REFERENCES messe_editions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE room_slot_capacities (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    room_id INT UNSIGNED NOT NULL,
    timeslot_id INT UNSIGNED NOT NULL,
    capacity SMALLINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_room_slot (room_id, timeslot_id),
    CONSTRAINT fk_rsc_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    CONSTRAINT fk_rsc_timeslot FOREIGN KEY (timeslot_id) REFERENCES timeslots(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Branchen & Aussteller
-- ------------------------------------------------------------

CREATE TABLE industries (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_industries_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exhibitors (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    edition_id INT UNSIGNED NOT NULL,
    name VARCHAR(200) NOT NULL,
    short_description VARCHAR(500) NULL,
    description TEXT NULL,
    categories JSON NULL, -- Array von Branchennamen
    logo VARCHAR(255) NULL,
    contact_person VARCHAR(200) NULL,
    email VARCHAR(255) NULL,
    phone VARCHAR(50) NULL,
    website VARCHAR(255) NULL,
    jobs TEXT NULL,
    features TEXT NULL,
    offer_types JSON NULL, -- {"selected": [...], "custom": "..."}
    equipment VARCHAR(500) NULL,
    visible_fields JSON NULL, -- Sichtbarkeitssteuerung einzelner Profilfelder
    total_slots SMALLINT UNSIGNED NOT NULL DEFAULT 25,
    room_id INT UNSIGNED NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_exhibitors_edition FOREIGN KEY (edition_id) REFERENCES messe_editions(id) ON DELETE CASCADE,
    CONSTRAINT fk_exhibitors_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL,
    KEY idx_exhibitors_edition_active (edition_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exhibitor_documents (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    exhibitor_id INT UNSIGNED NOT NULL,
    filename VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    file_type VARCHAR(100) NULL,
    file_size INT UNSIGNED NOT NULL DEFAULT 0,
    visible_for_students TINYINT(1) NOT NULL DEFAULT 0,
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_edocs_exhibitor FOREIGN KEY (exhibitor_id) REFERENCES exhibitors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- N:M Aussteller-Konto (users.role='exhibitor') ↔ Unternehmen, inkl. Einladung
CREATE TABLE exhibitor_users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    exhibitor_id INT UNSIGNED NOT NULL,
    can_edit_profile TINYINT(1) NOT NULL DEFAULT 1,
    can_manage_documents TINYINT(1) NOT NULL DEFAULT 1,
    invite_token VARCHAR(64) NULL,
    invite_expires DATETIME NULL,
    invite_accepted TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('active','cancelled_by_exhibitor','cancelled_by_school','removed_by_admin') NOT NULL DEFAULT 'active',
    cancelled_at DATETIME NULL,
    cancel_reason VARCHAR(500) NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_eu_pair (user_id, exhibitor_id),
    UNIQUE KEY uq_eu_invite_token (invite_token),
    CONSTRAINT fk_eu_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_eu_exhibitor FOREIGN KEY (exhibitor_id) REFERENCES exhibitors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Orga-Nutzer, die nur bestimmte Aussteller betreuen dürfen
CREATE TABLE exhibitor_orga_team (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    exhibitor_id INT UNSIGNED NOT NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_orga_pair (user_id, exhibitor_id),
    CONSTRAINT fk_eot_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_eot_exhibitor FOREIGN KEY (exhibitor_id) REFERENCES exhibitors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cancellation_requests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    exhibitor_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    school_id INT UNSIGNED NOT NULL,
    requested_by ENUM('exhibitor','school') NOT NULL,
    reason VARCHAR(500) NULL,
    status ENUM('pending','confirmed','rejected') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_at DATETIME NULL,
    confirmed_by INT UNSIGNED NULL,
    CONSTRAINT fk_cr_exhibitor FOREIGN KEY (exhibitor_id) REFERENCES exhibitors(id) ON DELETE CASCADE,
    CONSTRAINT fk_cr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_cr_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_cr_confirmer FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Ausstattung
-- ------------------------------------------------------------

CREATE TABLE equipment_options (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    school_id INT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    description VARCHAR(500) NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_eqopt_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exhibitor_equipment_requests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    exhibitor_id INT UNSIGNED NOT NULL,
    edition_id INT UNSIGNED NOT NULL,
    equipment_option_id INT UNSIGNED NULL, -- NULL = Freitextwunsch
    custom_text VARCHAR(500) NULL,
    quantity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('pending','approved','denied') NOT NULL DEFAULT 'pending',
    admin_notes VARCHAR(500) NULL,
    requested_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_eqreq_exhibitor FOREIGN KEY (exhibitor_id) REFERENCES exhibitors(id) ON DELETE CASCADE,
    CONSTRAINT fk_eqreq_edition FOREIGN KEY (edition_id) REFERENCES messe_editions(id) ON DELETE CASCADE,
    CONSTRAINT fk_eqreq_option FOREIGN KEY (equipment_option_id) REFERENCES equipment_options(id) ON DELETE SET NULL,
    CONSTRAINT fk_eqreq_requester FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Einschreibung & Anwesenheit
-- ------------------------------------------------------------

CREATE TABLE registrations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    edition_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    exhibitor_id INT UNSIGNED NOT NULL,
    timeslot_id INT UNSIGNED NULL, -- NULL = noch nicht zugeteilt
    registration_type ENUM('manual','automatic','qr_checkin') NOT NULL DEFAULT 'manual',
    priority TINYINT UNSIGNED NULL, -- 1=hoch, 2=mittel, 3=niedrig
    registered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_reg_user_exhibitor (user_id, exhibitor_id),
    CONSTRAINT fk_reg_edition FOREIGN KEY (edition_id) REFERENCES messe_editions(id) ON DELETE CASCADE,
    CONSTRAINT fk_reg_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_reg_exhibitor FOREIGN KEY (exhibitor_id) REFERENCES exhibitors(id) ON DELETE CASCADE,
    CONSTRAINT fk_reg_timeslot FOREIGN KEY (timeslot_id) REFERENCES timeslots(id) ON DELETE SET NULL,
    KEY idx_reg_exhibitor_slot (exhibitor_id, timeslot_id),
    KEY idx_reg_edition (edition_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attendance (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    edition_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    exhibitor_id INT UNSIGNED NOT NULL,
    timeslot_id INT UNSIGNED NOT NULL,
    qr_token VARCHAR(40) NULL,
    checkin_method ENUM('self_scan','teacher_scan','manual') NOT NULL DEFAULT 'self_scan',
    checked_in_by INT UNSIGNED NULL, -- Lehrer/Admin bei Fremd-Check-in
    actual_room_id INT UNSIGNED NULL,
    wrong_room TINYINT(1) NOT NULL DEFAULT 0,
    checked_in_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_att_user_exh_slot (user_id, exhibitor_id, timeslot_id),
    CONSTRAINT fk_att_edition FOREIGN KEY (edition_id) REFERENCES messe_editions(id) ON DELETE CASCADE,
    CONSTRAINT fk_att_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_att_exhibitor FOREIGN KEY (exhibitor_id) REFERENCES exhibitors(id) ON DELETE CASCADE,
    CONSTRAINT fk_att_timeslot FOREIGN KEY (timeslot_id) REFERENCES timeslots(id) ON DELETE CASCADE,
    CONSTRAINT fk_att_checker FOREIGN KEY (checked_in_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_att_room FOREIGN KEY (actual_room_id) REFERENCES rooms(id) ON DELETE SET NULL,
    KEY idx_att_slot (edition_id, timeslot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- QR-Token je Aussteller × Slot (Schüler scannt Aussteller-Code)
CREATE TABLE qr_tokens (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    edition_id INT UNSIGNED NOT NULL,
    exhibitor_id INT UNSIGNED NOT NULL,
    timeslot_id INT UNSIGNED NOT NULL,
    token VARCHAR(20) NOT NULL,
    expires_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_qrt_token (token),
    UNIQUE KEY uq_qrt_pair (exhibitor_id, timeslot_id),
    CONSTRAINT fk_qrt_edition FOREIGN KEY (edition_id) REFERENCES messe_editions(id) ON DELETE CASCADE,
    CONSTRAINT fk_qrt_exhibitor FOREIGN KEY (exhibitor_id) REFERENCES exhibitors(id) ON DELETE CASCADE,
    CONSTRAINT fk_qrt_timeslot FOREIGN KEY (timeslot_id) REFERENCES timeslots(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Persönlicher Schüler-Token (Lehrer scannt Schüler-Code)
CREATE TABLE student_qr_tokens (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    edition_id INT UNSIGNED NOT NULL,
    token VARCHAR(40) NOT NULL, -- Format: S-<32 hex>
    revoked TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sqt_token (token),
    UNIQUE KEY uq_sqt_user_edition (user_id, edition_id),
    CONSTRAINT fk_sqt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_sqt_edition FOREIGN KEY (edition_id) REFERENCES messe_editions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rate-Limiting für Check-in-Versuche
CREATE TABLE checkin_attempts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    ip_address VARCHAR(45) NOT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ca_ip_time (ip_address, attempted_at),
    KEY idx_ca_user_time (user_id, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Aufsichtsplan: Lehrer × Raum × Slot. timeslot_id NULL = Ganztags-Aufsicht.
CREATE TABLE teacher_room_assignments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    edition_id INT UNSIGNED NOT NULL,
    teacher_id INT UNSIGNED NOT NULL,
    room_id INT UNSIGNED NOT NULL,
    timeslot_id INT UNSIGNED NULL,
    assigned_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    timeslot_key INT UNSIGNED AS (COALESCE(timeslot_id, 0)) STORED,
    UNIQUE KEY uq_tra_teacher_slot (teacher_id, edition_id, timeslot_key),
    CONSTRAINT fk_tra_edition FOREIGN KEY (edition_id) REFERENCES messe_editions(id) ON DELETE CASCADE,
    CONSTRAINT fk_tra_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_tra_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    CONSTRAINT fk_tra_timeslot FOREIGN KEY (timeslot_id) REFERENCES timeslots(id) ON DELETE CASCADE,
    CONSTRAINT fk_tra_assigner FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Betrieb: Settings, Audit, Ankündigungen, Benachrichtigungen
-- ------------------------------------------------------------

-- school_id NULL = globale Einstellung (z. B. Seitenpasswort)
CREATE TABLE settings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    school_id INT UNSIGNED NULL,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    school_key INT UNSIGNED AS (COALESCE(school_id, 0)) STORED,
    UNIQUE KEY uq_settings_key_school (setting_key, school_key),
    CONSTRAINT fk_settings_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    school_id INT UNSIGNED NULL, -- NULL = systemweit
    user_id INT UNSIGNED NULL,
    username VARCHAR(100) NULL,
    action VARCHAR(255) NOT NULL,
    severity ENUM('info','warning','error') NOT NULL DEFAULT 'info',
    details TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE SET NULL,
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    KEY idx_audit_school_time (school_id, created_at),
    KEY idx_audit_severity (severity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE announcements (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    school_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    body TEXT NULL,
    type ENUM('info','warning','success','error') NOT NULL DEFAULT 'info',
    target_role ENUM('all','student','teacher','admin') NOT NULL DEFAULT 'all',
    expires_at DATETIME NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ann_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_ann_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_la_username_time (username, attempted_at),
    KEY idx_la_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_notifications (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    school_id INT UNSIGNED NULL,
    message VARCHAR(500) NOT NULL,
    type ENUM('exhibitor_cancelled','school_cancelled','cancellation_request','info') NOT NULL DEFAULT 'info',
    related_id INT UNSIGNED NULL,
    action_url VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    CONSTRAINT fk_ln_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ln_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    KEY idx_ln_user_unread (user_id, read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
