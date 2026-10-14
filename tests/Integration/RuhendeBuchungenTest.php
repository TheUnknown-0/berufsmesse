<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Auth;
use App\Core\Context;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;
use App\Services\Audit;
use App\Services\AutoAssign;
use App\Services\CancellationService;
use App\Services\Capacity;
use App\Services\Notifications;
use App\Services\Rebooking;
use App\Services\Settings;
use App\Services\Waitlist;
use Tests\Support\DatabaseTestCase;

/**
 * Absagen löschen keine Schülerbuchungen, sie stellen sie ruhend.
 *
 * Abgesichert wird die Entscheidung: Nach einer Absage gilt die Person als
 * ungebucht, die alte Buchung bleibt aber erhalten. Gelöscht wird sie erst,
 * wenn im selben Slot neu zugeteilt wird; nimmt die Schule die Absage
 * zurück, kommt sie wieder.
 */
final class RuhendeBuchungenTest extends DatabaseTestCase
{
    private Context $ctx;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->run("INSERT INTO schools (id, name, slug) VALUES (1, 'Testschule', 'test')");
        $this->db->run(
            "INSERT INTO messe_editions (id, school_id, name, year, status, event_date)
             VALUES (1, 1, 'Testmesse', 2026, 'active', '2099-06-01')",
        );
        $this->db->run(
            "INSERT INTO timeslots (id, edition_id, slot_number, slot_name, start_time, end_time, is_managed, is_break)
             VALUES (1, 1, 1, 'Block 1', '09:00:00', '09:45:00', 1, 0),
                    (2, 1, 2, 'Block 2', '10:00:00', '10:45:00', 1, 0)",
        );
        // 1 sagt ab, 2 hat genau einen freien Platz, 3 ist voll (Kapazität 0).
        $this->db->run(
            "INSERT INTO exhibitors (id, edition_id, name, total_slots, active, pipeline_status)
             VALUES (1, 1, 'AbsageAG', 10, 1, 'confirmed'),
                    (2, 1, 'ErsatzGmbH', 1, 1, 'confirmed'),
                    (3, 1, 'VollKG', 0, 1, 'confirmed')",
        );
        $this->db->run(
            "INSERT INTO users (id, school_id, edition_id, username, firstname, lastname, role)
             VALUES (1, 1, 1, 'anna', 'Anna', 'A', 'student'),
                    (2, 1, 1, 'ben', 'Ben', 'B', 'student'),
                    (9, NULL, NULL, 'firma', 'Firma', 'Konto', 'exhibitor')",
        );
        // Anna und Ben sind im Block 1 bei AbsageAG gebucht.
        $this->db->run(
            'INSERT INTO registrations (edition_id, user_id, exhibitor_id, timeslot_id, priority)
             VALUES (1, 1, 1, 1, 1), (1, 2, 1, 1, 2)',
        );
        $this->db->run(
            "INSERT INTO exhibitor_users (user_id, exhibitor_id, invite_accepted, status) VALUES (9, 1, 1, 'active')",
        );

        $session = new Session();
        $auth = new Auth($session, $this->db);
        $this->ctx = new Context(
            ['app' => ['env' => 'production', 'base_url' => '', 'secure_cookies' => false]],
            $this->db,
            $session,
            new Csrf($session),
            $auth,
            new View(dirname(__DIR__, 2) . '/templates'),
            new Settings($this->db),
            new Audit($this->db, $auth),
        );
    }

    private function absagen(string $cause = 'cancelled_by_school'): int
    {
        return (new CancellationService($this->ctx))->execute(
            $this->db->fetchOne('SELECT * FROM exhibitors WHERE id = 1'),
            $this->db->fetchOne('SELECT * FROM messe_editions WHERE id = 1'),
            1,
            'Testabsage',
            $cause,
        );
    }

    private function ruhend(int $userId): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM registrations_on_hold WHERE user_id = ?', [$userId]);
    }

    public function testAbsageStelltBuchungenRuhendStattSieZuLoeschen(): void
    {
        self::assertSame(2, $this->absagen());

        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM registrations WHERE exhibitor_id = 1'));
        $held = $this->db->fetchOne('SELECT * FROM registrations_on_hold WHERE user_id = 1');
        self::assertNotNull($held, 'Die alte Buchung muss erhalten bleiben.');
        self::assertSame(1, (int) $held['timeslot_id']);
        self::assertSame(1, (int) $held['priority']);
        self::assertSame('cancelled_by_school', $held['cause']);

        $exhibitor = $this->db->fetchOne('SELECT active, pipeline_status FROM exhibitors WHERE id = 1');
        self::assertSame(0, (int) $exhibitor['active']);
        self::assertSame('cancelled', $exhibitor['pipeline_status']);
        self::assertSame('cancelled_by_school', $this->db->fetchValue('SELECT status FROM exhibitor_users WHERE user_id = 9'));
        // Zwei Schüler:innen plus das Firmenkonto werden benachrichtigt.
        self::assertSame(3, (int) $this->db->fetchValue('SELECT COUNT(*) FROM login_notifications'));
    }

    public function testNeuzuteilungImSelbenSlotLoeschtDieRuhendeBuchung(): void
    {
        $this->absagen();

        // Auffüll-Lauf der Schule: ErsatzGmbH hat je Slot einen Platz. Anna
        // bekommt ihn in Block 1, Ben nur noch in Block 2.
        (new AutoAssign($this->db, new Capacity($this->db)))->fillIncomplete(1, 1);

        self::assertSame(2, (int) $this->db->fetchValue('SELECT exhibitor_id FROM registrations WHERE user_id = 1 AND timeslot_id = 1'));
        self::assertSame(0, $this->ruhend(1), 'Neu zugeteilt im selben Slot: ruhende Buchung ist überholt.');
        self::assertSame(1, $this->ruhend(2), 'Ben hat in Block 1 nichts Neues bekommen: seine Buchung ruht weiter.');
    }

    public function testNachrueckenLoeschtDieRuhendeBuchung(): void
    {
        $this->absagen();
        // Anna wartet bei ErsatzGmbH; dort wird Block 1 frei.
        $this->db->run('INSERT INTO registrations (edition_id, user_id, exhibitor_id, timeslot_id, priority) VALUES (1, 1, 2, NULL, 1)');

        $waitlist = new Waitlist($this->db, new Capacity($this->db), new Notifications($this->db));
        self::assertNotNull($waitlist->promote(1, 2, 1, 1));

        self::assertSame(0, $this->ruhend(1));
        self::assertSame(1, $this->ruhend(2), 'Ben ist nicht betroffen und behält seine ruhende Buchung.');
    }

    public function testZuruecknehmenHoltBuchungenUndKontoZurueck(): void
    {
        $this->absagen();

        $result = (new CancellationService($this->ctx))->restore(
            $this->db->fetchOne('SELECT * FROM exhibitors WHERE id = 1'),
            $this->db->fetchOne('SELECT * FROM messe_editions WHERE id = 1'),
            1,
        );

        self::assertSame(['restored' => 2, 'blocked' => 0], $result);
        self::assertSame(2, (int) $this->db->fetchValue('SELECT COUNT(*) FROM registrations WHERE exhibitor_id = 1 AND timeslot_id = 1'));
        self::assertSame(1, (int) $this->db->fetchValue('SELECT priority FROM registrations WHERE user_id = 1'));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM registrations_on_hold'));
        $exhibitor = $this->db->fetchOne('SELECT active, pipeline_status FROM exhibitors WHERE id = 1');
        self::assertSame(1, (int) $exhibitor['active']);
        self::assertSame('confirmed', $exhibitor['pipeline_status']);
        self::assertSame('active', $this->db->fetchValue('SELECT status FROM exhibitor_users WHERE user_id = 9'));
    }

    public function testZuruecknehmenUeberschreibtKeineNeueBuchung(): void
    {
        $this->absagen();
        // Ben hat inzwischen (an clearSlot vorbei) eine andere Buchung im Slot.
        $this->db->run('INSERT INTO registrations (edition_id, user_id, exhibitor_id, timeslot_id) VALUES (1, 2, 2, 1)');

        $result = (new CancellationService($this->ctx))->restore(
            $this->db->fetchOne('SELECT * FROM exhibitors WHERE id = 1'),
            $this->db->fetchOne('SELECT * FROM messe_editions WHERE id = 1'),
            1,
        );

        self::assertSame(['restored' => 1, 'blocked' => 1], $result);
        self::assertSame(2, (int) $this->db->fetchValue('SELECT exhibitor_id FROM registrations WHERE user_id = 2'));
        self::assertSame(1, $this->ruhend(2), 'Die nicht wiederherstellbare Buchung bleibt ruhend statt zu verschwinden.');
    }

    public function testUmbuchungLaesstUnplatzierteRuhen(): void
    {
        $this->absagen('failover');

        $rebooking = new Rebooking($this->db, new Capacity($this->db), new Notifications($this->db));
        $result = $rebooking->execute(1, 1, 1, 'AbsageAG');

        // ErsatzGmbH hat einen Platz, VollKG keinen: eine Person umgebucht, eine bleibt ruhend.
        self::assertSame(1, $result['moved']);
        self::assertSame(1, $result['unplaced']);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM registrations WHERE exhibitor_id = 2 AND timeslot_id = 1'));
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM registrations_on_hold'));
    }
}
