-- Schnittstelle für die Android-App (/api/v1/...).
--
-- api_tokens:   Anmeldung der App per Bearer-Token. Gespeichert wird nur der
--               SHA-256-Hash; das Token selbst kennt allein das Gerät.
-- app_handoffs: Einmal-Codes, mit denen die App eine Web-Seite angemeldet
--               öffnet (Funktionen, die es in der App noch nicht nativ gibt).
--               Ein Code gilt 60 Sekunden und genau einmal.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS api_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    device_name VARCHAR(100) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    ip_address VARCHAR(45) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_api_tokens_hash (token_hash),
    KEY idx_api_tokens_user (user_id),
    CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_handoffs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    token_id BIGINT UNSIGNED NULL, -- App-Token, das den Code angefordert hat
    code_hash CHAR(64) NOT NULL,
    target_path VARCHAR(500) NOT NULL,
    theme VARCHAR(5) NULL, -- Theme der App (light/dark) für die eingebetteten Seiten
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_app_handoffs_hash (code_hash),
    KEY idx_app_handoffs_user (user_id),
    CONSTRAINT fk_app_handoffs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_app_handoffs_token FOREIGN KEY (token_id) REFERENCES api_tokens(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
