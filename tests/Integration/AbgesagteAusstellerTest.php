<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\AutoAssign;
use App\Services\Capacity;
use App\Services\Notifications;
use App\Services\Waitlist;
use Tests\Support\DatabaseTestCase;

/**
 * Ein abgesagter Aussteller darf keine Schüler:innen mehr bekommen.
 *
 * Regressionstest für den schwersten gefundenen Fehler: Das Ausfallmanagement
 * bucht nur ZUGETEILTE Anmeldungen um und deaktiviert dann den Aussteller —
 * die offenen Wünsche auf dessen Warteliste bleiben stehen. Phase 1 der
 * Zuteilung filterte nicht auf `active` (Phase 2 dagegen schon), sodass ein
 * erneuter Zuteilungslauf genau diese Wünsche erfüllte. Am Messetag standen
 * die Betroffenen dann vor einem leeren Raum.
 */
final class AbgesagteAusstellerTest extends DatabaseTestCase
{
    private const EDITION = 1;
    private const SLOT = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->run("INSERT INTO schools (id, name, slug) VALUES (1, 'Testschule', 'test')");
        $this->db->run(
            "INSERT INTO messe_editions (id, school_id, name, year, status)
             VALUES (1, 1, 'Testmesse', 2026, 'active')",
        );
        $this->db->run(
            "INSERT INTO timeslots (id, edition_id, slot_number, slot_name, start_time, end_time, is_managed, is_break)
             VALUES (1, 1, 1, 'Block 1', '09:00:00', '09:45:00', 1, 0)",
        );

        // Aussteller 1 hat abgesagt, Aussteller 2 ist regulär dabei.
        $this->db->run(
            "INSERT INTO exhibitors (id, edition_id, name, total_slots, active, pipeline_status)
             VALUES (1, 1, 'AbgesagtAG', 10, 0, 'cancelled'),
                    (2, 1, 'AktivGmbH', 10, 1, 'confirmed')",
        );

        $this->db->run(
            "INSERT INTO users (id, school_id, edition_id, username, firstname, lastname, role)
             VALUES (1, 1, 1, 'anna', 'Anna', 'Test', 'student'),
                    (2, 1, 1, 'ben', 'Ben', 'Test', 'student')",
        );
    }

    private function wunsch(int $userId, int $exhibitorId): int
    {
        $this->db->run(
            'INSERT INTO registrations (edition_id, user_id, exhibitor_id, timeslot_id, priority)
             VALUES (?, ?, ?, NULL, 1)',
            [self::EDITION, $userId, $exhibitorId],
        );

        return $this->db->lastInsertId();
    }

    private function slotVon(int $registrationId): ?int
    {
        $value = $this->db->fetchValue('SELECT timeslot_id FROM registrations WHERE id = ?', [$registrationId]);

        return $value === null ? null : (int) $value;
    }

    public function testZuteilungUebergehtAbgesagteAussteller(): void
    {
        $beimAbgesagten = $this->wunsch(1, 1);

        $autoAssign = new AutoAssign($this->db, new Capacity($this->db));
        $stats = $autoAssign->assignPending(self::EDITION);

        self::assertSame(0, $stats['assigned'], 'Ein abgesagter Aussteller darf keine Zuteilung erhalten.');
        self::assertNull(
            $this->slotVon($beimAbgesagten),
            'Der Wunsch beim abgesagten Aussteller muss unzugeteilt bleiben.',
        );
    }

    public function testAktiveAusstellerWerdenWeiterhinZugeteilt(): void
    {
        $beimAbgesagten = $this->wunsch(1, 1);
        $beimAktiven = $this->wunsch(2, 2);

        $autoAssign = new AutoAssign($this->db, new Capacity($this->db));
        $stats = $autoAssign->assignPending(self::EDITION);

        self::assertSame(1, $stats['assigned']);
        self::assertNull($this->slotVon($beimAbgesagten));
        self::assertSame(self::SLOT, $this->slotVon($beimAktiven));
    }

    /** Auch das Nachrücken darf niemanden zu einem abgesagten Aussteller schicken. */
    public function testKeinNachrueckenBeiAbgesagtemAussteller(): void
    {
        $wartend = $this->wunsch(1, 1);

        $waitlist = new Waitlist($this->db, new Capacity($this->db), new Notifications($this->db));
        $promoted = $waitlist->promote(self::EDITION, 1, self::SLOT, 1);

        self::assertNull($promoted, 'In einen abgesagten Aussteller rückt niemand nach.');
        self::assertNull($this->slotVon($wartend));
        self::assertSame(
            0,
            (int) $this->db->fetchValue('SELECT COUNT(*) FROM login_notifications'),
            'Es darf auch keine Benachrichtigung verschickt werden.',
        );
    }

    /** Die Kapazitätsmatrix darf abgesagten Ausstellern keine Plätze mehr zurechnen. */
    public function testAbgesagterAusstellerHatKeineFreienPlaetze(): void
    {
        $capacity = new Capacity($this->db);

        self::assertFalse($capacity->hasFree(self::EDITION, 1, self::SLOT));
        self::assertTrue($capacity->hasFree(self::EDITION, 2, self::SLOT));
    }
}
