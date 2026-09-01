-- ------------------------------------------------------------
-- Integrität: Doppelbelegung eines Zeitslots ausschließen
--
-- Bisher war „eine Schüler:in belegt einen Zeitslot höchstens einmal“
-- reine Anwendungslogik — geprüft, bevor geschrieben wird, ohne Sperre.
-- Zwei gleichzeitige Wege in denselben Slot (Selbst-Check-in und Scan
-- durch die Aufsicht) konnten die Prüfung passieren und beide schreiben;
-- danach zählen sämtliche Berichte die Person doppelt.
--
-- MariaDB behandelt NULL-Werte in einem UNIQUE als verschieden. Der
-- Constraint verträgt sich deshalb mit der Warteliste, die genau über
-- `timeslot_id IS NULL` funktioniert: Beliebig viele offene Wünsche
-- bleiben erlaubt, nur zugeteilte Slots werden eindeutig.
--
-- Vor dem Anlegen werden bestehende Doppelbelegungen aufgelöst — die
-- zuletzt angelegte Zuteilung wird zum Wartelisteneintrag zurückgestuft,
-- statt sie zu löschen.
-- ------------------------------------------------------------

UPDATE registrations r
    JOIN (
        SELECT r2.id
        FROM registrations r2
        JOIN registrations r1
          ON r1.user_id = r2.user_id
         AND r1.edition_id = r2.edition_id
         AND r1.timeslot_id = r2.timeslot_id
         AND r1.id < r2.id
        WHERE r2.timeslot_id IS NOT NULL
    ) doppelt ON doppelt.id = r.id
    SET r.timeslot_id = NULL;

ALTER TABLE registrations
    ADD CONSTRAINT uq_reg_user_slot UNIQUE (user_id, edition_id, timeslot_id);

-- ------------------------------------------------------------
-- Aufräumen der Check-in-Versuche
--
-- `login_attempts` wird gelegentlich aus der Anwendung heraus bereinigt,
-- `checkin_attempts` bisher gar nicht — die Tabelle wuchs unbegrenzt.
-- Der Index nach Zeitstempel macht sowohl die Rate-Limit-Abfrage als
-- auch das Aufräumen billig.
-- ------------------------------------------------------------

ALTER TABLE checkin_attempts
    ADD KEY idx_checkin_attempts_time (attempted_at);
