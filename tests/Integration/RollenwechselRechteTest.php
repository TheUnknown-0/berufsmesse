<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Auth;
use App\Core\Permissions;
use App\Core\Session;
use Tests\Support\DatabaseTestCase;

/**
 * Regressionstest: Rechte enden mit der Rolle.
 *
 * Granulare Berechtigungen gibt es nur für orga und teacher
 * (Permissions::GRANULAR_ROLES). Zuvor prüfte Auth nur, ob ein Recht
 * zugewiesen ist — nicht, ob die Rolle es überhaupt tragen darf. Ein
 * Orga-Mitglied, das zu student herabgestuft wurde, behielt damit alle
 * Rechte; entziehen ließen sie sich nicht mehr, weil die Rechtevergabe
 * nur orga- und teacher-Konten anzeigt.
 */
final class RollenwechselRechteTest extends DatabaseTestCase
{
    private const SCHOOL_ID = 1;

    private const USER_ID = 10;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->run("INSERT INTO schools (id, name, slug) VALUES (1, 'Testschule', 'test')");
        $this->db->run(
            "INSERT INTO users (id, school_id, username, firstname, lastname, role)
             VALUES (?, ?, 'orga1', 'Ola', 'Orga', 'orga')",
            [self::USER_ID, self::SCHOOL_ID],
        );

        $_SESSION['user_id'] = self::USER_ID;
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user_id']);
        parent::tearDown();
    }

    public function testOrgaMitgliedNutztSeinDirektesRecht(): void
    {
        $this->grantDirect(Permissions::BENUTZER_SEHEN);

        self::assertTrue(
            $this->auth()->can(Permissions::BENUTZER_SEHEN, self::SCHOOL_ID),
            'Ein erteiltes Recht muss für die Rolle orga greifen.',
        );
    }

    public function testDirektesRechtWirktNachDemRollenwechselNichtMehr(): void
    {
        $this->grantDirect(Permissions::BENUTZER_SEHEN);
        $this->setRole('student');

        self::assertFalse(
            $this->auth()->can(Permissions::BENUTZER_SEHEN, self::SCHOOL_ID),
            'Nach der Herabstufung darf ein zurückgebliebenes Recht nicht mehr greifen.',
        );
    }

    public function testRechtAusEinerGruppeWirktNachDemRollenwechselNichtMehr(): void
    {
        $this->grantViaGroup(Permissions::BENUTZER_SEHEN);
        self::assertTrue(
            $this->auth()->can(Permissions::BENUTZER_SEHEN, self::SCHOOL_ID),
            'Ein Recht aus einer Gruppe muss für die Rolle orga greifen.',
        );

        $this->setRole('student');

        self::assertFalse(
            $this->auth()->can(Permissions::BENUTZER_SEHEN, self::SCHOOL_ID),
            'Die Gruppenzuweisung darf nach der Herabstufung nicht weiterwirken.',
        );
    }

    /** Lehrkräfte behalten ihre Rechte — sie sind eine rechtefähige Rolle. */
    public function testLehrkraftBehaeltIhreRechte(): void
    {
        $this->grantDirect(Permissions::BENUTZER_SEHEN);
        $this->setRole('teacher');

        self::assertTrue(
            $this->auth()->can(Permissions::BENUTZER_SEHEN, self::SCHOOL_ID),
            'teacher gehört zu den rechtefähigen Rollen.',
        );
    }

    // ---------- Helfer ----------

    /** Frisches Auth-Objekt: Benutzer und Rechte werden pro Instanz einmal geladen. */
    private function auth(): Auth
    {
        return new Auth(new Session(), $this->db);
    }

    private function grantDirect(string $permission): void
    {
        $this->db->run(
            'INSERT INTO user_permissions (user_id, permission) VALUES (?, ?)',
            [self::USER_ID, $permission],
        );
    }

    private function grantViaGroup(string $permission): void
    {
        $this->db->run(
            "INSERT INTO permission_groups (id, school_id, name) VALUES (1, ?, 'Orga-Team')",
            [self::SCHOOL_ID],
        );
        $this->db->run(
            'INSERT INTO permission_group_items (group_id, permission) VALUES (1, ?)',
            [$permission],
        );
        $this->db->run(
            'INSERT INTO user_permission_groups (user_id, group_id) VALUES (?, 1)',
            [self::USER_ID],
        );
    }

    private function setRole(string $role): void
    {
        $this->db->run('UPDATE users SET role = ? WHERE id = ?', [$role, self::USER_ID]);
    }
}
