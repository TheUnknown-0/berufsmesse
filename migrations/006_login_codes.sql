-- Anmeldung per E-Mail-Code (Alternative zum Passwort, deckt auch
-- "Passwort vergessen" ab — der Code allein reicht zum Einloggen) sowie
-- ein Protokoll versendeter E-Mails (Login-Codes, Test-Mails).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS login_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_login_codes_user (user_id, created_at),
    CONSTRAINT fk_login_codes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id INT UNSIGNED NULL,
    user_id INT UNSIGNED NULL,
    recipient VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    template VARCHAR(100) NOT NULL,
    status ENUM('sent','failed') NOT NULL,
    error VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_email_log_school_template (school_id, template),
    KEY idx_email_log_user (user_id),
    CONSTRAINT fk_email_log_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE SET NULL,
    CONSTRAINT fk_email_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
