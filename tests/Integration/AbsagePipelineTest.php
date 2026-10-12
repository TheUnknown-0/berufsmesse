<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Auth;
use App\Core\Context;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;
use App\Services\Audit;
use App\Services\CancellationService;
use App\Services\Settings;
use Tests\Support\DatabaseTestCase;

/**
 * Eine wirksame Absage setzt den Aussteller auch in der Pipeline auf „Storniert“.
 *
 * Regressionstest: Die Absage deaktivierte nur `active`, `pipeline_status`
 * blieb auf „confirmed“ stehen. Die Pipeline zeigte das Unternehmen danach
 * weiter als zugesagt an, und ein erneutes Speichern der Stufe hätte es
 * wieder für Schüler:innen sichtbar gemacht.
 */
final class AbsagePipelineTest extends DatabaseTestCase
{
    private Context $ctx;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->run("INSERT INTO schools (id, name, slug) VALUES (1, 'Testschule', 'test')");
        $this->db->run(
            "INSERT INTO messe_editions (id, school_id, name, year, status, event_date, registration_end)
             VALUES (1, 1, 'Testmesse', 2026, 'active', '2099-06-01', '2000-01-01 00:00:00')",
        );
        $this->db->run(
            "INSERT INTO timeslots (id, edition_id, slot_number, slot_name, start_time, end_time, is_managed, is_break)
             VALUES (1, 1, 1, 'Block 1', '09:00:00', '09:45:00', 1, 0)",
        );
        $this->db->run(
            "INSERT INTO exhibitors (id, edition_id, name, total_slots, active, pipeline_status)
             VALUES (1, 1, 'AbsageAG', 10, 1, 'confirmed')",
        );
        $this->db->run(
            "INSERT INTO users (id, school_id, edition_id, username, firstname, lastname, role)
             VALUES (1, 1, 1, 'anna', 'Anna', 'Test', 'student')",
        );
        $this->db->run(
            'INSERT INTO registrations (edition_id, user_id, exhibitor_id, timeslot_id, priority) VALUES (1, 1, 1, 1, 1)',
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

    public function testAbsageSetztPipelineAufStorniert(): void
    {
        $exhibitor = $this->db->fetchOne('SELECT * FROM exhibitors WHERE id = 1');
        $edition = $this->db->fetchOne('SELECT * FROM messe_editions WHERE id = 1');

        $affected = (new CancellationService($this->ctx))->execute($exhibitor, $edition, 1, 'Personalausfall');

        $row = $this->db->fetchOne('SELECT active, pipeline_status FROM exhibitors WHERE id = 1');
        self::assertSame(1, $affected);
        self::assertSame(0, (int) $row['active']);
        self::assertSame('cancelled', $row['pipeline_status']);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM registrations WHERE exhibitor_id = 1'));
    }
}
