<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Capacity;
use App\Services\Notifications;
use App\Services\Waitlist;
use Tests\Support\DatabaseTestCase;

/**
 * Nachrücken von der Warteliste.
 *
 * ACHTUNG — Grenze dieser Tests: Sie sichern die fachlichen Regeln ab
 * (Reihenfolge, Kapazität, Doppelbelegung), NICHT die Parallelität. Der
 * eigentliche Fehler, gegen den promote() gehärtet wurde — zwei gleichzeitige
 * Abmeldungen desselben Aussteller-Slots greifen dieselbe wartende Person,
 * und der zweite Durchlauf meldet ein Nachrücken, das nie stattfand — tritt
 * nur bei echter Nebenläufigkeit auf und lässt sich in einem einzelnen
 * PHPUnit-Prozess nicht deterministisch herstellen. Alle Tests hier laufen
 * auch gegen die alte, fehlerhafte Fassung grün.
 *
 * Für einen echten Nachweis braucht es zwei parallele Verbindungen, die sich
 * an der FOR-UPDATE-Sperre begegnen — siehe offener Punkt in der Übergabe.
 */
final class WaitlistTest extends DatabaseTestCase
{
    private int $editionId;
    private int $exhibitorId;
    private int $slotId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->run("INSERT INTO schools (id, name, slug) VALUES (1, 'Testschule', 'test')");
        $this->db->run(
            "INSERT INTO messe_editions (id, school_id, name, year, status)
             VALUES (1, 1, 'Testmesse', 2026, 'active')",
        );
        $this->editionId = 1;

        // Kapazität 1: genau ein Platz, um den konkurriert wird.
        $this->db->run(
            "INSERT INTO exhibitors (id, edition_id, name, total_slots, active)
             VALUES (1, 1, 'TechCorp', 1, 1)",
        );
        $this->exhibitorId = 1;

        $this->db->run(
            "INSERT INTO timeslots (id, edition_id, slot_number, slot_name, start_time, end_time, is_managed, is_break)
             VALUES (1, 1, 1, 'Block 1', '09:00:00', '09:45:00', 1, 0)",
        );
        $this->slotId = 1;

        foreach ([1 => 'anna', 2 => 'ben', 3 => 'cem'] as $id => $name) {
            $this->db->run(
                "INSERT INTO users (id, school_id, edition_id, username, firstname, lastname, role)
                 VALUES (?, 1, 1, ?, ?, 'Test', 'student')",
                [$id, $name, $name],
            );
        }
    }

    private function waitlist(): Waitlist
    {
        return new Waitlist($this->db, new Capacity($this->db), new Notifications($this->db));
    }

    /** Offener Wunsch ohne Zeitslot = Wartelisteneintrag. */
    private function addWish(int $userId, ?int $priority, ?int $timeslotId = null): int
    {
        $this->db->run(
            'INSERT INTO registrations (edition_id, user_id, exhibitor_id, timeslot_id, priority)
             VALUES (?, ?, ?, ?, ?)',
            [$this->editionId, $userId, $this->exhibitorId, $timeslotId, $priority],
        );

        return $this->db->lastInsertId();
    }

    public function testWartendeRueckenBeiFreiemPlatzNach(): void
    {
        $wishId = $this->addWish(1, 1);

        $promoted = $this->waitlist()->promote($this->editionId, $this->exhibitorId, $this->slotId, 1);

        self::assertNotNull($promoted, 'Bei freier Kapazität muss jemand nachrücken.');
        self::assertSame(
            $this->slotId,
            (int) $this->db->fetchValue('SELECT timeslot_id FROM registrations WHERE id = ?', [$wishId]),
            'Der Wunsch muss den frei gewordenen Slot bekommen.',
        );
        self::assertSame(
            1,
            (int) $this->db->fetchValue('SELECT COUNT(*) FROM login_notifications WHERE user_id = 1'),
            'Genau eine Benachrichtigung.',
        );
    }

    public function testHoeherePrioritaetGehtVor(): void
    {
        $this->addWish(1, 3);
        $bevorzugt = $this->addWish(2, 1);

        $this->waitlist()->promote($this->editionId, $this->exhibitorId, $this->slotId, 1);

        self::assertSame(
            $this->slotId,
            (int) $this->db->fetchValue('SELECT timeslot_id FROM registrations WHERE id = ?', [$bevorzugt]),
            'Priorität 1 muss vor Priorität 3 nachrücken.',
        );
    }

    /**
     * Ist der Platz bereits belegt, darf weder zugeteilt noch benachrichtigt
     * werden. Deckt den sequenziellen Fall ab — die Kapazitätsprüfung greift
     * hier schon vor dem UPDATE, der Parallelfall bleibt ungetestet.
     */
    public function testKeinNachrueckenUndKeineMeldungWennDerPlatzSchonBelegtIst(): void
    {
        // Der einzige Platz ist bereits vergeben …
        $this->addWish(1, 1, $this->slotId);
        // … und jemand steht noch auf der Warteliste.
        $wartend = $this->addWish(2, 1);

        $promoted = $this->waitlist()->promote($this->editionId, $this->exhibitorId, $this->slotId, 1);

        self::assertNull($promoted, 'Ohne freien Platz darf promote() keinen Erfolg melden.');
        self::assertNull(
            $this->db->fetchValue('SELECT timeslot_id FROM registrations WHERE id = ?', [$wartend]),
            'Der wartende Wunsch muss offen bleiben.',
        );
        self::assertSame(
            0,
            (int) $this->db->fetchValue('SELECT COUNT(*) FROM login_notifications'),
            'Es darf keine Nachrück-Benachrichtigung verschickt werden.',
        );
    }

    /** Wer im fraglichen Slot schon woanders sitzt, rückt nicht nach. */
    public function testBereitsBelegterSlotSchliesstNachrueckenAus(): void
    {
        $this->db->run(
            "INSERT INTO exhibitors (id, edition_id, name, total_slots, active)
             VALUES (2, 1, 'HandwerkPlus', 5, 1)",
        );
        // Anna ist im Slot schon bei einem anderen Aussteller.
        $this->db->run(
            'INSERT INTO registrations (edition_id, user_id, exhibitor_id, timeslot_id, priority)
             VALUES (?, 1, 2, ?, 1)',
            [$this->editionId, $this->slotId],
        );
        $annasWunsch = $this->addWish(1, 1);
        $bensWunsch = $this->addWish(2, 2);

        $this->waitlist()->promote($this->editionId, $this->exhibitorId, $this->slotId, 1);

        self::assertNull(
            $this->db->fetchValue('SELECT timeslot_id FROM registrations WHERE id = ?', [$annasWunsch]),
            'Anna ist im Slot belegt und darf nicht nachrücken.',
        );
        self::assertSame(
            $this->slotId,
            (int) $this->db->fetchValue('SELECT timeslot_id FROM registrations WHERE id = ?', [$bensWunsch]),
            'Stattdessen muss Ben zum Zug kommen.',
        );
    }

    public function testOhneWartendePassiertNichts(): void
    {
        self::assertNull(
            $this->waitlist()->promote($this->editionId, $this->exhibitorId, $this->slotId, 1),
        );
    }
}
