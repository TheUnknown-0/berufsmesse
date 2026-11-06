<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Notifications;
use App\Services\Settings;
use App\Services\UpdateCheck;
use Tests\Support\DatabaseTestCase;

/**
 * Update-Prüfung mit nachgestellter Quelle: Ergebnis landet in den
 * Einstellungen, eine neue Version geht genau einmal an die Global-Admins,
 * und GitHub wird nicht öfter gefragt als nötig.
 */
final class UpdateCheckTest extends DatabaseTestCase
{
    /** @var array{status: int, body: string, etag: ?string, retryAfter: ?int} */
    private array $response = ['status' => 200, 'body' => '', 'etag' => null, 'retryAfter' => null];

    /** @var list<?string> mitgeschickte ETags, eine Zeile je Abfrage */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->run("INSERT INTO schools (id, name, slug) VALUES (1, 'Testschule', 'test')");
        $this->db->run(
            "INSERT INTO users (id, school_id, username, role) VALUES
                (1, NULL, 'admin1', 'admin'),
                (2, NULL, 'admin2', 'admin'),
                (3, 1, 'schuladmin', 'school_admin')",
        );
    }

    private function check(string $url = 'https://example.org/CHANGELOG.md'): UpdateCheck
    {
        return new UpdateCheck(
            $this->db,
            new Settings($this->db),
            new Notifications($this->db),
            $url,
            '/global-admin#version',
            function (string $url, ?string $etag): array {
                $this->requests[] = $etag;

                return $this->response;
            },
            '1.1.0',
        );
    }

    private function serve(string $body, ?string $etag = null): void
    {
        $this->response = ['status' => 200, 'body' => $body, 'etag' => $etag, 'retryAfter' => null];
    }

    private function notificationsFor(int $userId): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM login_notifications WHERE user_id = ?', [$userId]);
    }

    public function testNeueVersionWirdGespeichertUndEinmalGemeldet(): void
    {
        $this->serve("## 1.2.0 (2026-11-06)\n- Neu\n\n## 1.1.0\n- Alt\n");

        $this->check()->run(1000);
        $state = $this->check()->state();

        self::assertTrue($state['available']);
        self::assertSame('1.2.0', $state['latest']);
        self::assertSame(['1.2.0'], array_column($state['news'], 'version'));
        self::assertNull($state['error']);
        self::assertSame(1, $this->notificationsFor(1));
        self::assertSame(1, $this->notificationsFor(2));
        self::assertSame(0, $this->notificationsFor(3), 'Schul-Admins bekommen keinen Update-Hinweis.');
        self::assertSame(
            '/global-admin#version',
            $this->db->fetchValue('SELECT action_url FROM login_notifications WHERE user_id = 1'),
        );

        // Zweite Prüfung, gleiche Version: keine zweite Meldung.
        $this->check()->run(2000);
        self::assertSame(1, $this->notificationsFor(1));

        // Noch neuere Version: wieder eine Meldung.
        $this->serve("## 1.3.0\n- Neuer\n## 1.2.0\n- Neu\n");
        $this->check()->run(3000);
        self::assertSame(2, $this->notificationsFor(1));
        self::assertSame(['1.3.0', '1.2.0'], array_column($this->check()->state()['news'], 'version'));
    }

    public function testGleicheVersionIstKeinUpdate(): void
    {
        $this->serve("## 1.1.0\n- Alt\n");

        $this->check()->run();
        $state = $this->check()->state();

        self::assertFalse($state['available']);
        self::assertSame([], $state['news']);
        self::assertNotNull($state['checkedAt']);
        self::assertSame(0, $this->notificationsFor(1));
    }

    public function testFehlerWirdGespeichertUndVerdecktAltenStandNicht(): void
    {
        $this->serve("## 1.2.0\n- Neu\n");
        $this->check()->run(1000);

        $this->response = ['status' => 404, 'body' => 'Not Found', 'etag' => null, 'retryAfter' => null];
        $this->check()->run(2000);
        $state = $this->check()->state();

        self::assertSame('example.org antwortete mit HTTP 404.', $state['error']);
        self::assertTrue($state['available'], 'Der zuletzt bekannte Stand bleibt sichtbar.');
    }

    public function testEtagSpartDenInhaltBeiUnveraenderterDatei(): void
    {
        $this->serve("## 1.2.0\n- Neu\n", '"abc"');
        $this->check()->run(1000);

        $this->response = ['status' => 304, 'body' => '', 'etag' => '"abc"', 'retryAfter' => null];
        $this->check()->run(2000);

        self::assertSame([null, '"abc"'], $this->requests);
        $state = $this->check()->state();
        self::assertTrue($state['available'], '304 behält den gespeicherten Stand.');
        self::assertSame(['1.2.0'], array_column($state['news'], 'version'));
        self::assertNull($state['error']);
        self::assertSame(1, $this->notificationsFor(1));
    }

    public function testNach429WirdBisRetryAfterNichtGefragt(): void
    {
        $this->response = ['status' => 429, 'body' => '', 'etag' => null, 'retryAfter' => 600];
        self::assertTrue($this->check()->run(10_000));
        self::assertStringContainsString('begrenzt', (string) $this->check()->state()['error']);

        self::assertFalse($this->check()->run(10_000 + 599), 'Während der Pause keine Abfrage.');
        self::assertCount(1, $this->requests);

        $this->serve("## 1.1.0\n- Alt\n");
        self::assertTrue($this->check()->run(10_000 + 600));
        self::assertCount(2, $this->requests);
        self::assertNull($this->check()->state()['error']);
    }

    public function testStundengrenzeAlsSicherheitsnetz(): void
    {
        $this->serve("## 1.1.0\n- Alt\n");
        for ($i = 0; $i < UpdateCheck::HOURLY_CAP + 5; $i++) {
            $this->check()->run(50_000 + $i * 10);
        }
        self::assertCount(UpdateCheck::HOURLY_CAP, $this->requests);
        self::assertStringContainsString('pro Stunde', (string) $this->check()->state()['error']);

        // Neue Stunde, neues Kontingent.
        self::assertTrue($this->check()->run(50_000 + 3600));
        self::assertCount(UpdateCheck::HOURLY_CAP + 1, $this->requests);
    }

    public function testFaelligkeit(): void
    {
        $check = $this->check();
        self::assertTrue($check->isDue(false), 'Noch nie geprüft.');

        $this->serve("## 1.1.0\n- Alt\n");
        $check->run(1_000_000);

        self::assertFalse($check->isDue(false, 1_000_000 + 3600));
        self::assertTrue($check->isDue(false, 1_000_000 + UpdateCheck::DAILY));
        self::assertFalse($check->isDue(true, 1_000_000 + 5));
        self::assertTrue($check->isDue(true, 1_000_000 + UpdateCheck::LOGIN_GAP));
        self::assertTrue($check->checkedRecently(1_000_000 + 2));
        self::assertFalse($check->checkedRecently(1_000_000 + UpdateCheck::MANUAL_GAP));
    }

    public function testAbgeschaltetFragtNichtNach(): void
    {
        $check = $this->check('off');

        self::assertFalse($check->run());
        self::assertFalse($check->configured());
        self::assertFalse($check->isDue(true));
        self::assertSame([], $this->requests);
        self::assertFalse($check->state()['available']);
    }
}
