<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\HelpCalls;
use App\Services\Settings;
use Tests\Support\DatabaseTestCase;

/**
 * Hilfe-Ruf: Raumzuteilung, ein offener Ruf pro Raum, Klingel-Runden
 * (neu, mehr Hilfe, niemand hat zugesagt) und Einstellungen pro Schule.
 */
final class HilfeRufTest extends DatabaseTestCase
{
    private HelpCalls $calls;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->run("INSERT INTO schools (id, name, slug) VALUES (1, 'Testschule', 'test')");
        $this->db->run(
            "INSERT INTO messe_editions (id, school_id, name, year, status, event_date)
             VALUES (1, 1, 'Messe', 2027, 'active', CURDATE())",
        );
        $this->db->run(
            "INSERT INTO users (id, school_id, username, firstname, lastname, role) VALUES
             (10, 1, 'lehrer', 'Lena', 'Lehrer', 'teacher'),
             (11, 1, 'orga', 'Otto', 'Orga', 'orga'),
             (12, 1, 'helfer', 'Hanna', 'Helfer', 'orga')",
        );
        $this->db->run(
            "INSERT INTO rooms (id, edition_id, room_number, room_name) VALUES
             (100, 1, 'A101', 'Werkstatt'), (101, 1, 'B202', NULL)",
        );
        $this->db->run(
            "INSERT INTO timeslots (id, edition_id, slot_number, start_time, end_time) VALUES
             (1, 1, 1, '00:00:00', '23:59:59')",
        );
        // Lehrerin ganztags in A101 und im laufenden Slot in B202, Orga nur ganztags in A101
        $this->db->run(
            'INSERT INTO teacher_room_assignments (edition_id, teacher_id, room_id, timeslot_id) VALUES
             (1, 10, 100, NULL), (1, 10, 101, 1), (1, 11, 100, NULL)',
        );
        $this->calls = new HelpCalls($this->db, new Settings($this->db), 1);
    }

    public function testRaeumeDerEinteilungMitVorauswahlDesLaufendenSlots(): void
    {
        self::assertTrue($this->calls->isEventDay());

        $rooms = $this->calls->assignedRooms(10);
        self::assertSame([100, 101], array_column($rooms, 'id'));
        self::assertSame([false, true], array_column($rooms, 'preselected'), 'Raum des laufenden Slots ist vorausgewählt');
        self::assertSame('A101 · Werkstatt', $rooms[0]['label']);

        self::assertSame([100], $this->calls->assignedRoomIds(11), 'Orga-Mitglieder lassen sich ebenso einteilen');
        self::assertSame([], $this->calls->assignedRooms(12));
    }

    public function testHoechstensEinOffenerRufProRaum(): void
    {
        $id = $this->calls->create(10, 100, 'Technik', 'Beamer aus');

        try {
            $this->calls->create(11, 100, 'Erste Hilfe', null);
            self::fail('Zweiter offener Ruf für denselben Raum');
        } catch (\DomainException $e) {
            self::assertStringContainsString('schon ein Hilfe-Ruf offen', $e->getMessage());
        }

        $this->calls->close($id, 10);
        self::assertGreaterThan($id, $this->calls->create(11, 100, 'Erste Hilfe', null), 'Nach dem Schließen geht es wieder');
    }

    public function testNiemandSagtZuDannNeueRundeNachZweiMinuten(): void
    {
        $id = $this->calls->create(10, 100, 'Technik', null);
        $call = $this->calls->find($id);
        $state = HelpCalls::effective($call);
        self::assertSame(1, $state['round']);
        self::assertFalse($state['nobody_coming']);

        $this->db->run(
            'UPDATE help_calls SET created_at = NOW() - INTERVAL 130 SECOND, alert_at = NOW() - INTERVAL 130 SECOND WHERE id = ?',
            [$id],
        );
        $state = HelpCalls::effective($this->calls->find($id));
        self::assertSame(2, $state['round'], 'Erneutes Klingeln ohne Zusage');
        self::assertSame('unanswered', $state['reason']);
        self::assertTrue($state['nobody_coming'], 'Rufende sehen „Noch niemand unterwegs“');

        // Die Zusage hält die berechnete Runde fest: Die Nummer darf danach nicht sinken.
        $this->calls->respond($id, 12);
        $state = HelpCalls::effective($this->calls->find($id));
        self::assertSame(2, $state['round']);
        self::assertTrue($state['answered_since_alert'], 'Klingeln stoppt bei allen anderen');
        self::assertFalse($state['nobody_coming']);

        // Mit Zusage klingelt es nach Ablauf nicht erneut.
        $this->db->run('UPDATE help_calls SET alert_at = NOW() - INTERVAL 300 SECOND WHERE id = ?', [$id]);
        self::assertSame(2, HelpCalls::effective($this->calls->find($id))['round']);
    }

    public function testMehrHilfeKlingeltErneutAuchMitZusage(): void
    {
        $id = $this->calls->create(10, 100, 'Erste Hilfe', null);
        $this->db->run('UPDATE help_calls SET alert_at = NOW() - INTERVAL 10 SECOND WHERE id = ?', [$id]);
        $this->calls->respond($id, 12);
        $this->db->run('UPDATE help_call_responses SET created_at = NOW() - INTERVAL 5 SECOND WHERE call_id = ?', [$id]);
        self::assertFalse($this->calls->respond($id, 12), 'Doppelte Zusage zählt nicht');

        $this->calls->requestMore($id);
        $call = $this->calls->find($id);
        $state = HelpCalls::effective($call);
        self::assertSame(2, $state['round']);
        self::assertSame('more', $state['reason']);
        self::assertFalse($state['answered_since_alert'], 'Ältere Zusagen stoppen das neue Klingeln nicht');
        self::assertSame(1, (int) $call['more_count']);
        self::assertCount(1, $call['responses'], 'Wer schon unterwegs ist, bleibt es');

        $this->calls->respond($id, 11);
        self::assertTrue(HelpCalls::effective($this->calls->find($id))['answered_since_alert'], '„Ich komme auch“');
    }

    public function testProbealarmOhneRaumUndNurEinerGleichzeitig(): void
    {
        $id = $this->calls->createTest(11, 'Bitte bestätigen');
        $call = $this->calls->find($id);
        self::assertSame(1, (int) $call['is_test']);
        self::assertNull($call['room_id']);
        self::assertSame('TEST: Probealarm', HelpCalls::describe($call));

        $this->expectException(\DomainException::class);
        $this->calls->createTest(11, null);
    }

    public function testKategorienProSchuleBereinigt(): void
    {
        self::assertSame(HelpCalls::DEFAULT_CATEGORIES, $this->calls->categories());
        self::assertTrue($this->calls->textEnabled());

        $this->calls->saveSettings(['  Erste   Hilfe ', 'erste hilfe', '', 'Strom'], false);
        $fresh = new HelpCalls($this->db, new Settings($this->db), 1);
        self::assertSame(['Erste Hilfe', 'Strom'], $fresh->categories());
        self::assertFalse($fresh->textEnabled());
    }

    public function testDienstZaehltNurAktuelleGeraete(): void
    {
        $this->calls->touchDuty(11);
        $this->calls->touchDuty(12);
        $this->db->run('UPDATE help_call_duty SET last_seen_at = NOW() - INTERVAL 5 MINUTE WHERE user_id = 12');

        self::assertSame([11], array_column($this->calls->onDuty(), 'id'));
        self::assertTrue($this->calls->isOnDuty(11));
        self::assertFalse($this->calls->isOnDuty(12));
        self::assertTrue($this->calls->endDuty(11));
        self::assertSame([], $this->calls->onDuty());
    }
}
