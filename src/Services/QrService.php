<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDOException;
use RuntimeException;

/**
 * QR-Tokens, Gültigkeitsfenster und Rate-Limiting für den Check-in.
 *
 * Zwei Token-Arten:
 *  - Slot-Token (qr_tokens): Aussteller × Zeitslot, hängt am Stand/Raum.
 *    12 Zeichen aus einem Alphabet ohne verwechselbare Zeichen, damit der
 *    Code notfalls abgetippt werden kann.
 *  - Schüler-Token (student_qr_tokens): persönlich, Format 'S-' + 32 Hex.
 *
 * Alle Lookups laufen ZWINGEND über die edition_id (Schul-/Jahrgangs-Isolation).
 */
final class QrService
{
    /** Ohne I, O, 0, 1 — beim Abtippen nicht verwechselbar. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const TOKEN_LENGTH = 12;

    /** Rate-Limit: max. Fehlversuche je Zeitfenster (User ODER IP). */
    private const MAX_FAILS = 30;
    private const WINDOW_SECONDS = 60;

    /** Standard-Zeitpuffer in Minuten (siehe ARCHITECTURE.md). */
    private const DEFAULT_BEFORE = 10;
    private const DEFAULT_AFTER = 15;
    private const DEFAULT_TEACHER_BEFORE = 20;
    private const DEFAULT_TEACHER_AFTER = 30;

    /** @var array<int, array<string, mixed>|null> Cache der Editionsdaten. */
    private array $editionCache = [];

    public function __construct(
        private readonly Database $db,
        private readonly Settings $settings,
    ) {
    }

    // ---------------------------------------------------------------- Tokens

    /**
     * Liefert den Slot-Token für Aussteller × Zeitslot und erzeugt ihn bei
     * Bedarf (lazy). $force erzeugt einen neuen Token für dasselbe Paar.
     *
     * @return string|null null, wenn Aussteller/Slot nicht zur Edition gehören
     *                     oder der Slot eine Pause ist.
     */
    public function tokenFor(int $exhibitorId, int $timeslotId, int $editionId, bool $force = false): ?string
    {
        $slot = $this->db->fetchOne(
            'SELECT id, start_time, end_time, is_break FROM timeslots WHERE id = ? AND edition_id = ?',
            [$timeslotId, $editionId],
        );
        if ($slot === null || (int) $slot['is_break'] === 1) {
            return null;
        }

        $exhibitorExists = (bool) $this->db->fetchValue(
            'SELECT 1 FROM exhibitors WHERE id = ? AND edition_id = ?',
            [$exhibitorId, $editionId],
        );
        if (!$exhibitorExists) {
            return null;
        }

        $expiresAt = $this->expiresAtFor($slot, $editionId);

        if (!$force) {
            $existing = $this->db->fetchValue(
                'SELECT token FROM qr_tokens WHERE exhibitor_id = ? AND timeslot_id = ? AND edition_id = ?',
                [$exhibitorId, $timeslotId, $editionId],
            );
            if (is_string($existing) && $existing !== '') {
                // Ablaufzeit an geänderte Einstellungen/Messedaten angleichen
                $this->db->run(
                    'UPDATE qr_tokens SET expires_at = ? WHERE exhibitor_id = ? AND timeslot_id = ? AND edition_id = ?',
                    [$expiresAt, $exhibitorId, $timeslotId, $editionId],
                );

                return $existing;
            }
        }

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $token = self::randomToken();
            if ($this->db->fetchValue('SELECT 1 FROM qr_tokens WHERE token = ?', [$token]) !== null) {
                continue;
            }
            try {
                $this->db->run(
                    'INSERT INTO qr_tokens (edition_id, exhibitor_id, timeslot_id, token, expires_at)
                     VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at),
                                             created_at = CURRENT_TIMESTAMP',
                    [$editionId, $exhibitorId, $timeslotId, $token, $expiresAt],
                );

                return $token;
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }

        throw new RuntimeException('QR-Token konnte nicht erzeugt werden.');
    }

    /**
     * Zieht die Ablaufzeiten aller vorhandenen Tokens einer Edition nach —
     * nötig, wenn sich Messedatum, Slot-Zeiten oder QR-Einstellungen ändern
     * (die Tokens selbst bleiben gültig, gedruckte QR-Codes funktionieren weiter).
     */
    public function refreshExpirations(int $editionId): int
    {
        $slots = $this->db->fetchAll(
            'SELECT id, start_time, end_time, is_break FROM timeslots WHERE edition_id = ?',
            [$editionId],
        );

        $updated = 0;
        foreach ($slots as $slot) {
            $stmt = $this->db->run(
                'UPDATE qr_tokens SET expires_at = ? WHERE edition_id = ? AND timeslot_id = ?',
                [$this->expiresAtFor($slot, $editionId), $editionId, (int) $slot['id']],
            );
            $updated += $stmt->rowCount();
        }

        return $updated;
    }

    /**
     * Erzeugt Slot-Tokens für alle aktiven Aussteller × alle Nicht-Pausen-Slots.
     *
     * @return int Anzahl der neu erzeugten Tokens.
     */
    public function generateAll(int $editionId, bool $force = false): int
    {
        $exhibitors = $this->db->fetchAll(
            'SELECT id FROM exhibitors WHERE edition_id = ? AND active = 1 ORDER BY name',
            [$editionId],
        );
        $slots = $this->db->fetchAll(
            'SELECT id FROM timeslots WHERE edition_id = ? AND is_break = 0 ORDER BY slot_number',
            [$editionId],
        );

        $created = 0;
        foreach ($exhibitors as $exhibitor) {
            foreach ($slots as $slot) {
                $existed = !$force && $this->db->fetchValue(
                    'SELECT 1 FROM qr_tokens WHERE exhibitor_id = ? AND timeslot_id = ? AND edition_id = ?',
                    [(int) $exhibitor['id'], (int) $slot['id'], $editionId],
                ) !== null;

                $token = $this->tokenFor((int) $exhibitor['id'], (int) $slot['id'], $editionId, $force);
                if ($token !== null && !$existed) {
                    $created++;
                }
            }
        }

        return $created;
    }

    /**
     * Persönlicher Schüler-Token (lazy erzeugt), Format 'S-' + 32 Hex-Zeichen.
     */
    public function studentTokenFor(int $userId, int $editionId): string
    {
        $existing = $this->db->fetchValue(
            'SELECT token FROM student_qr_tokens
             WHERE user_id = ? AND edition_id = ? AND revoked = 0
             ORDER BY id DESC LIMIT 1',
            [$userId, $editionId],
        );
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $token = 'S-' . bin2hex(random_bytes(16));
            try {
                $this->db->run(
                    'INSERT INTO student_qr_tokens (user_id, edition_id, token) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE token = VALUES(token), revoked = 0',
                    [$userId, $editionId, $token],
                );

                return $token;
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }

        throw new RuntimeException('Schüler-Token konnte nicht erzeugt werden.');
    }

    // -------------------------------------------------------------- Auflösen

    /**
     * Löst einen gescannten Slot-Token innerhalb der Edition auf.
     *
     * @return array<string, mixed>|null
     */
    public function resolveSlotToken(string $token, int $editionId): ?array
    {
        return $this->db->fetchOne(
            'SELECT qt.token, qt.exhibitor_id, qt.timeslot_id, qt.expires_at,
                    e.name AS exhibitor_name, e.room_id, e.active,
                    t.slot_name, t.slot_number, t.start_time, t.end_time,
                    t.is_managed, t.is_break,
                    r.room_number, r.room_name
             FROM qr_tokens qt
             JOIN exhibitors e ON e.id = qt.exhibitor_id AND e.edition_id = qt.edition_id
             JOIN timeslots  t ON t.id = qt.timeslot_id  AND t.edition_id = qt.edition_id
             LEFT JOIN rooms r ON r.id = e.room_id AND r.edition_id = qt.edition_id
             WHERE qt.token = ? AND qt.edition_id = ?',
            [$token, $editionId],
        );
    }

    /**
     * Löst einen persönlichen Schüler-Token auf (Edition + Schule erzwungen).
     *
     * @return array<string, mixed>|null
     */
    public function resolveStudentToken(string $token, int $editionId, int $schoolId): ?array
    {
        return $this->db->fetchOne(
            'SELECT u.id, u.firstname, u.lastname, u.class, u.school_id
             FROM student_qr_tokens st
             JOIN users u ON u.id = st.user_id
             WHERE st.token = ? AND st.revoked = 0 AND st.edition_id = ?
               AND u.school_id = ? AND u.role = \'student\'
             LIMIT 1',
            [$token, $editionId, $schoolId],
        );
    }

    /**
     * Extrahiert einen Token aus Rohdaten: entweder direkt der Token oder
     * eine gescannte Check-in-URL (…/checkin?token=XYZ).
     */
    public static function extractToken(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        if (str_contains($raw, 'token=')) {
            $query = parse_url($raw, PHP_URL_QUERY);
            if (is_string($query)) {
                parse_str($query, $parts);
                if (isset($parts['token']) && is_string($parts['token'])) {
                    return trim($parts['token']);
                }
            }
        }

        return $raw;
    }

    // ------------------------------------------------------ Gültigkeitsfenster

    /**
     * Ablaufzeitpunkt eines Slot-Tokens: Slot-Ende am Messetag + Nachlauf.
     * null = unbegrenzt (Gültigkeitsprüfung deaktiviert oder kein Messedatum).
     *
     * @param array<string, mixed> $slot
     */
    public function expiresAtFor(array $slot, int $editionId): ?string
    {
        $edition = $this->edition($editionId);
        if ($edition === null) {
            return null;
        }

        $schoolId = (int) $edition['school_id'];
        if (!$this->settings->getBool('qr_validity_enabled', $schoolId, true)) {
            return null;
        }

        $eventDate = $edition['event_date'] ?? null;
        $endTime = $slot['end_time'] ?? null;
        if (!is_string($eventDate) || $eventDate === '' || !is_string($endTime) || $endTime === '') {
            return null;
        }

        $end = strtotime($eventDate . ' ' . $endTime);
        if ($end === false) {
            return null;
        }

        $after = $this->settings->getInt('qr_validity_after', $schoolId, self::DEFAULT_AFTER);

        return date('Y-m-d H:i:s', $end + $after * 60);
    }

    /**
     * Prüft das Gültigkeitsfenster eines Slots.
     *
     * @param array<string, mixed> $slot   Zeitslot (start_time/end_time)
     * @param bool                 $teacher Lehrer-Scan (großzügigeres Fenster)
     * @return string|null Fehlermeldung oder null, wenn gültig.
     */
    public function windowError(array $slot, int $editionId, bool $teacher = false): ?string
    {
        $edition = $this->edition($editionId);
        if ($edition === null) {
            return null;
        }

        $schoolId = (int) $edition['school_id'];
        $enabledKey = $teacher ? 'qr_validity_teacher_enabled' : 'qr_validity_enabled';
        if (!$this->settings->getBool($enabledKey, $schoolId, true)) {
            return null;
        }

        $eventDate = $edition['event_date'] ?? null;
        $startTime = $slot['start_time'] ?? null;
        $endTime = $slot['end_time'] ?? null;
        if (!is_string($eventDate) || $eventDate === ''
            || !is_string($startTime) || $startTime === ''
            || !is_string($endTime) || $endTime === '') {
            return null;
        }

        $start = strtotime($eventDate . ' ' . $startTime);
        $end = strtotime($eventDate . ' ' . $endTime);
        if ($start === false || $end === false) {
            return null;
        }

        $before = $this->settings->getInt(
            $teacher ? 'qr_validity_teacher_before' : 'qr_validity_before',
            $schoolId,
            $teacher ? self::DEFAULT_TEACHER_BEFORE : self::DEFAULT_BEFORE,
        );
        $after = $this->settings->getInt(
            $teacher ? 'qr_validity_teacher_after' : 'qr_validity_after',
            $schoolId,
            $teacher ? self::DEFAULT_TEACHER_AFTER : self::DEFAULT_AFTER,
        );

        $now = time();
        if ($now < $start - $before * 60) {
            return 'Der Check-in für diesen Zeitslot ist noch nicht möglich. Bitte kurz vor Slotbeginn erneut versuchen.';
        }
        if ($now > $end + $after * 60) {
            return 'Das Zeitfenster für diesen Zeitslot ist abgelaufen.';
        }

        return null;
    }

    // ------------------------------------------------------------ Rate-Limit

    /** Zu viele Fehlversuche in den letzten 60 Sekunden (je User ODER IP)? */
    public function isRateLimited(?int $userId, string $ip): bool
    {
        // Das Zeitfenster ist eine Klassenkonstante (kein Request-Wert); MySQL
        // erlaubt in INTERVAL keinen Platzhalter, daher hier fest eingesetzt.
        $sql = sprintf(
            'SELECT COUNT(*) FROM checkin_attempts
             WHERE success = 0
               AND attempted_at > (NOW() - INTERVAL %d SECOND)
               AND (user_id <=> ? OR ip_address = ?)',
            self::WINDOW_SECONDS,
        );
        $count = $this->db->fetchValue($sql, [$userId, $ip]);

        return (int) $count >= self::MAX_FAILS;
    }

    /** Protokolliert einen Check-in-Versuch (Grundlage des Rate-Limits). */
    public function recordAttempt(?int $userId, string $ip, bool $success): void
    {
        $this->db->run(
            'INSERT INTO checkin_attempts (user_id, ip_address, success) VALUES (?, ?, ?)',
            [$userId, $ip, $success ? 1 : 0],
        );
    }

    // ------------------------------------------------------------------- URL

    /**
     * Im QR-Code kodierte Check-in-URL.
     * Basis aus Setting `qr_code_url`; ist sie leer, wird $fallbackBase
     * (aus dem Request-Host gebaut) verwendet.
     */
    public function checkinUrl(?int $schoolId, string $schoolSlug, string $token, string $fallbackBase): string
    {
        $base = trim((string) ($this->settings->get('qr_code_url', $schoolId) ?? ''));
        if ($base === '') {
            $base = $fallbackBase;
        }

        return rtrim($base, '/') . '/' . rawurlencode($schoolSlug) . '/checkin?token=' . rawurlencode($token);
    }

    // -------------------------------------------------------------- Internes

    private static function randomToken(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $token = '';
        for ($i = 0; $i < self::TOKEN_LENGTH; $i++) {
            $token .= self::ALPHABET[random_int(0, $max)];
        }

        return $token;
    }

    /** @return array<string, mixed>|null */
    private function edition(int $editionId): ?array
    {
        if (!array_key_exists($editionId, $this->editionCache)) {
            $this->editionCache[$editionId] = $this->db->fetchOne(
                'SELECT id, school_id, event_date FROM messe_editions WHERE id = ?',
                [$editionId],
            );
        }

        return $this->editionCache[$editionId];
    }
}
