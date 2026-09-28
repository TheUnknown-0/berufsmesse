<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Auth;
use App\Core\Permissions;
use App\Core\Session;
use App\Services\EditionCloner;
use App\Services\OrgaAccess;
use App\Services\OrgaChat;
use Tests\Support\DatabaseTestCase;

/**
 * Zugriffsregeln des Orga-Tools (OrgaAccess), Erwähnungen, Lesestand und
 * die Übernahme beim Klonen einer Edition.
 */
final class OrgaToolTest extends DatabaseTestCase
{
    private const SCHOOL = 1;
    private const EDITION = 1;
    private const OTHER_EDITION = 2;

    private const LEADER = 10;
    private const MEMBER = 11;
    private const OUTSIDER = 12;
    private const SCHOOL_ADMIN = 13;
    private const VIEWER = 14;

    private const TEAM = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->run("INSERT INTO schools (id, name, slug) VALUES (1, 'Testschule', 'test')");
        $this->db->run("INSERT INTO messe_editions (id, school_id, name, year, status) VALUES (1, 1, 'Messe 2026', 2026, 'active')");
        $this->db->run("INSERT INTO messe_editions (id, school_id, name, year, status) VALUES (2, 1, 'Messe 2027', 2027, 'draft')");

        foreach ([
            [self::LEADER, 'leitung', 'orga'],
            [self::MEMBER, 'mitglied', 'orga'],
            [self::OUTSIDER, 'aussen', 'orga'],
            [self::SCHOOL_ADMIN, 'sadmin', 'school_admin'],
            [self::VIEWER, 'einsicht', 'orga'],
        ] as [$id, $username, $role]) {
            $this->db->run(
                'INSERT INTO users (id, school_id, username, firstname, lastname, role) VALUES (?, 1, ?, ?, ?, ?)',
                [$id, $username, ucfirst($username), 'Test', $role],
            );
        }
        $this->db->run(
            'INSERT INTO user_permissions (user_id, permission) VALUES (?, ?)',
            [self::VIEWER, Permissions::ORGA_TOOL_ALLE_SEHEN],
        );

        $this->db->run("INSERT INTO orga_teams (id, edition_id, name) VALUES (1, 1, 'Akquise')");
        $this->db->run('INSERT INTO orga_team_members (team_id, user_id, is_leader) VALUES (1, ?, 1), (1, ?, 0)', [self::LEADER, self::MEMBER]);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user_id']);
        parent::tearDown();
    }

    public function testLeitungUndMitgliedschaft(): void
    {
        $leader = $this->access(self::LEADER);
        $member = $this->access(self::MEMBER);
        $outsider = $this->access(self::OUTSIDER);

        self::assertTrue($leader->canLeadTeam(self::TEAM));
        self::assertTrue($member->canViewTeam(self::TEAM));
        self::assertFalse($member->canLeadTeam(self::TEAM), 'Mitglieder bearbeiten keine Ziele und Punkte.');
        self::assertFalse($outsider->canViewTeam(self::TEAM), 'Fremde Orga-Mitglieder sehen das Team nicht.');
    }

    public function testMitgliedArbeitetNurAnEigenenPunkten(): void
    {
        $member = $this->access(self::MEMBER);
        $own = ['team_id' => self::TEAM, 'assignee_id' => self::MEMBER];
        $foreign = ['team_id' => self::TEAM, 'assignee_id' => self::LEADER];
        $open = ['team_id' => self::TEAM, 'assignee_id' => null];

        self::assertTrue($member->canWorkOnTask($own));
        self::assertFalse($member->canWorkOnTask($foreign));
        self::assertFalse($member->canWorkOnTask($open));
        self::assertTrue($this->access(self::LEADER)->canWorkOnTask($foreign), 'Die Leitung darf jeden Punkt ihres Teams bearbeiten.');
    }

    public function testEinsichtOhneSchreibrecht(): void
    {
        $viewer = $this->access(self::VIEWER);
        $space = $viewer->space('team-' . self::TEAM);

        self::assertNotNull($space);
        self::assertTrue($viewer->canReadSpace($space), 'Einsicht liest alle Team-Chats.');
        self::assertFalse($viewer->canWriteSpace($space), 'Reines Einsehen erlaubt kein Mitschreiben.');
        self::assertFalse($viewer->moderates());
    }

    public function testSchulAdminDarfAllesImOrgaTool(): void
    {
        $admin = $this->access(self::SCHOOL_ADMIN);
        $space = $admin->space('team-' . self::TEAM);

        self::assertTrue($admin->canWriteSpace($space), 'Admins senden überall mit.');
        self::assertTrue($admin->moderates());
        self::assertTrue($admin->manages());
        self::assertTrue($admin->canLeadTeam(self::TEAM));
        self::assertTrue($admin->canReadSpace($admin->space('leitung')));
    }

    public function testLeitungsrundeNurFuerLeitungen(): void
    {
        self::assertTrue($this->access(self::LEADER)->canReadSpace($this->access(self::LEADER)->space('leitung')));
        $member = $this->access(self::MEMBER);
        self::assertFalse($member->canReadSpace($member->space('leitung')));
        self::assertTrue($member->canWriteSpace($member->space('gesamt')), 'Der Gesamtchat steht allen Orga-Mitgliedern offen.');
    }

    public function testTeamEinerAnderenEditionIstNichtErreichbar(): void
    {
        $this->db->run("INSERT INTO orga_teams (id, edition_id, name) VALUES (2, 2, 'Nächstes Jahr')");

        self::assertNull($this->access(self::SCHOOL_ADMIN)->space('team-2'));
        self::assertNull($this->access(self::SCHOOL_ADMIN)->findTeam(2));
    }

    public function testErwaehnungenNurAusDerZielgruppe(): void
    {
        $leader = $this->access(self::LEADER);
        $audience = $leader->audience($leader->space('team-' . self::TEAM));

        $ids = OrgaChat::mentionedUserIds('Hallo @mitglied und @aussen, bitte an @Leitung.', $audience);
        sort($ids);

        self::assertSame([self::LEADER, self::MEMBER], $ids, 'Wer den Raum nicht lesen darf, wird nicht benachrichtigt.');
    }

    public function testUngelesenZaehltNurFremdeNachrichtenNachDemLesestand(): void
    {
        $chat = new OrgaChat($this->db);
        $leader = $this->access(self::LEADER);
        $space = $leader->space('team-' . self::TEAM);

        $first = $chat->send(self::EDITION, $space, self::MEMBER, 'Erste', null, null);
        $chat->send(self::EDITION, $space, self::MEMBER, 'Zweite', null, null);
        $chat->send(self::EDITION, $space, self::LEADER, 'Eigene', null, null);

        self::assertSame(2, $this->access(self::LEADER)->unreadCounts()['t1']);

        $chat->markRead(self::LEADER, self::EDITION, $space, $first);
        self::assertSame(1, $this->access(self::LEADER)->unreadCounts()['t1']);

        // Der Lesestand wandert nie zurück.
        $chat->markRead(self::LEADER, self::EDITION, $space, 1);
        self::assertSame(1, $this->access(self::LEADER)->unreadCounts()['t1']);
    }

    public function testKlonenUebernimmtVorlageOhneDurchfuehrungsdaten(): void
    {
        $this->db->run("INSERT INTO orga_goals (id, team_id, title, is_done, due_date) VALUES (1, 1, 'Ziel', 1, '2026-05-01')");
        $this->db->run(
            "INSERT INTO orga_tasks (id, team_id, goal_id, title, status, assignee_id, due_date)
             VALUES (1, 1, 1, 'Punkt', 'done', ?, '2026-05-01')",
            [self::MEMBER],
        );
        $this->db->run("INSERT INTO orga_task_checklist (task_id, label, is_done) VALUES (1, 'Schritt', 1)");
        $this->db->run("INSERT INTO orga_task_comments (task_id, user_id, body) VALUES (1, ?, 'Kommentar')", [self::MEMBER]);
        $this->db->run(
            "INSERT INTO orga_messages (edition_id, channel, team_id, user_id, body) VALUES (1, 'team', 1, ?, 'Hallo')",
            [self::MEMBER],
        );

        $stats = (new EditionCloner($this->db))->copy(self::EDITION, self::OTHER_EDITION, ['orga_tool']);

        self::assertSame(1, $stats['orga_tool']);
        $team = $this->db->fetchOne('SELECT * FROM orga_teams WHERE edition_id = 2');
        self::assertNotNull($team);

        $task = $this->db->fetchOne('SELECT * FROM orga_tasks WHERE team_id = ?', [(int) $team['id']]);
        self::assertSame('open', $task['status'], 'Übernommene Punkte starten offen.');
        self::assertNull($task['due_date'], 'Fristen des Vorjahrs werden nicht übernommen.');
        self::assertNull($task['assignee_id'], 'Ohne Mitglieder gibt es keine Zuständigkeit.');
        self::assertNotNull($task['goal_id'], 'Die Verknüpfung zum Ziel bleibt erhalten.');

        $goal = $this->db->fetchOne('SELECT * FROM orga_goals WHERE id = ?', [(int) $task['goal_id']]);
        self::assertSame(0, (int) $goal['is_done']);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT is_done FROM orga_task_checklist WHERE task_id = ?', [(int) $task['id']]));

        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM orga_team_members WHERE team_id = ?', [(int) $team['id']]));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM orga_task_comments WHERE task_id = ?', [(int) $task['id']]));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM orga_messages WHERE edition_id = 2'));
    }

    public function testKlonenMitMitgliedernBehaeltLeitungUndZustaendigkeit(): void
    {
        $this->db->run("INSERT INTO orga_tasks (id, team_id, title, assignee_id) VALUES (1, 1, 'Punkt', ?)", [self::MEMBER]);

        $stats = (new EditionCloner($this->db))->copy(self::EDITION, self::OTHER_EDITION, ['orga_tool', 'orga_tool_members']);

        self::assertSame(2, $stats['orga_tool_members']);
        $teamId = (int) $this->db->fetchValue('SELECT id FROM orga_teams WHERE edition_id = 2');
        self::assertSame(1, (int) $this->db->fetchValue(
            'SELECT is_leader FROM orga_team_members WHERE team_id = ? AND user_id = ?',
            [$teamId, self::LEADER],
        ));
        self::assertSame(self::MEMBER, (int) $this->db->fetchValue('SELECT assignee_id FROM orga_tasks WHERE team_id = ?', [$teamId]));
    }

    // ---------- Helfer ----------

    private function access(int $userId): OrgaAccess
    {
        $_SESSION['user_id'] = $userId;
        $auth = new Auth(new Session(), $this->db);
        // Sofort laden: Auth liest die Session sonst erst beim ersten Zugriff,
        // und mehrere Objekte in einem Test sähen alle den zuletzt gesetzten Nutzer.
        $auth->user();

        return new OrgaAccess($this->db, $auth, self::SCHOOL, self::EDITION);
    }
}
