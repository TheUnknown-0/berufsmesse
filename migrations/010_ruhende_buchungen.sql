-- ------------------------------------------------------------
-- Ruhende Buchungen: Absagen löschen keine Schülerbuchungen mehr
--
-- Sagt ein Aussteller ab (im Portal, durch die Schule, über die Pipeline
-- oder als Ausfall am Messetag), wandern seine Buchungen aus
-- `registrations` hierher. Für alle übrigen Programmteile gilt der Slot
-- damit sofort als frei; die Schülerin bzw. der Schüler ist „ungebucht“.
--
-- Gelöscht wird eine ruhende Buchung erst, wenn die Person in genau diesem
-- Slot eine neue Buchung bekommt (Zuteilung, manuell, Nachrücken,
-- Umbuchung, Check-in): Services\HeldRegistrations::clearSlot(). Wird die
-- Absage zurückgenommen, kommen alle noch vorhandenen Einträge zurück.
--
-- Eigene Tabelle statt Markierung in `registrations`: `registrations` wird
-- an sehr vielen Stellen gelesen. Mit einer Markierung müsste jede davon
-- filtern, und jede vergessene Stelle schickte Schüler:innen zu einem
-- Aussteller, der nicht kommt.
-- ------------------------------------------------------------

CREATE TABLE registrations_on_hold (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    edition_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    exhibitor_id INT UNSIGNED NOT NULL,
    -- NULL = war noch ein offener Wunsch (Warteliste)
    timeslot_id INT UNSIGNED NULL,
    registration_type ENUM('manual','automatic','qr_checkin') NOT NULL DEFAULT 'manual',
    priority TINYINT UNSIGNED NULL,
    registered_at TIMESTAMP NULL,
    cause ENUM('cancelled_by_exhibitor','cancelled_by_school','failover') NOT NULL,
    reason VARCHAR(500) NULL,
    held_by INT UNSIGNED NULL,
    held_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_hold_user_exhibitor (user_id, exhibitor_id),
    KEY idx_hold_user_slot (edition_id, user_id, timeslot_id),
    KEY idx_hold_exhibitor (exhibitor_id),
    CONSTRAINT fk_hold_edition FOREIGN KEY (edition_id) REFERENCES messe_editions(id) ON DELETE CASCADE,
    CONSTRAINT fk_hold_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_hold_exhibitor FOREIGN KEY (exhibitor_id) REFERENCES exhibitors(id) ON DELETE CASCADE,
    CONSTRAINT fk_hold_timeslot FOREIGN KEY (timeslot_id) REFERENCES timeslots(id) ON DELETE SET NULL,
    CONSTRAINT fk_hold_by FOREIGN KEY (held_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bestehender Datenstand: Ausgeblendete Aussteller, die noch Buchungen
-- tragen (Pipeline-Status und Sichtbarkeits-Haken setzten bisher nur
-- `active = 0`), werden nachträglich in ruhende Buchungen überführt.
-- Sonst stünden diese Schüler:innen weiter mit einem Termin bei einem
-- Aussteller im Tagesplan, der nicht kommt. Wird so ein Aussteller in der
-- Pipeline wieder auf „Zugesagt“ gesetzt, kommen die Buchungen zurück.
INSERT INTO registrations_on_hold
    (edition_id, user_id, exhibitor_id, timeslot_id, registration_type, priority, registered_at, cause, reason)
SELECT r.edition_id, r.user_id, r.exhibitor_id, r.timeslot_id, r.registration_type, r.priority, r.registered_at,
       'cancelled_by_school', 'Bei der Umstellung auf ruhende Buchungen übernommen'
FROM registrations r
JOIN exhibitors e ON e.id = r.exhibitor_id
WHERE e.active = 0;

DELETE r FROM registrations r
JOIN exhibitors e ON e.id = r.exhibitor_id
WHERE e.active = 0;
