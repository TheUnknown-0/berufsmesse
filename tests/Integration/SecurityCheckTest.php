<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Notifications;
use App\Services\SecurityCheck;
use App\Services\Settings;
use Tests\Support\DatabaseTestCase;

/**
 * Sicherheitscheck mit nachgestellten Quellen: Funde landen im gespeicherten
 * Ergebnis und gehen genau einmal an die Global-Admins.
 */
final class SecurityCheckTest extends DatabaseTestCase
{
    private string $dir;

    /** @var list<string> */
    private array $requested = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->run("INSERT INTO schools (id, name, slug) VALUES (1, 'Testschule', 'test')");
        $this->db->run(
            "INSERT INTO users (id, school_id, username, role) VALUES
                (1, NULL, 'admin1', 'admin'),
                (2, NULL, 'admin2', 'admin'),
                (3, 1, 'lehrer', 'teacher')",
        );

        $this->dir = sys_get_temp_dir() . '/security-check-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/status', "Package: libssl3t64\nStatus: install ok installed\nSource: openssl\nVersion: 3.5.0-1\n");
        file_put_contents($this->dir . '/os-release', "ID=debian\nVERSION_ID=\"13\"\n");
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
        parent::tearDown();
    }

    private function check(?callable $http = null): SecurityCheck
    {
        return new SecurityCheck(
            $this->db,
            new Settings($this->db),
            new Notifications($this->db),
            '/global-admin',
            $http ?? $this->stub(...),
            $this->dir . '/status',
            $this->dir . '/os-release',
            $this->dir . '/cache.json',
        );
    }

    /** Nachgestellte Antworten von GitHub, php.net, OSV und mariadb.org. */
    private function stub(string $url, ?string $body): string
    {
        $this->requested[] = $url;
        $branch = SecurityCheck::branch(PHP_VERSION);

        $response = match (true) {
            str_contains($url, 'api.github.com') => [[
                'cve_id' => 'CVE-PHP-TEST', 'severity' => 'critical', 'summary' => 'RCE',
                'cvss_severities' => ['cvss_v4' => ['score' => 9.2]],
                'vulnerabilities' => [['patched_versions' => $branch . '.999']],
            ]],
            str_contains($url, 'php.net') => ['supported_versions' => [$branch]],
            str_contains($url, 'querybatch') => ['results' => [['vulns' => [['id' => 'DEBIAN-CVE-TEST', 'modified' => '2026-10-01T00:00:00Z']]]]],
            str_contains($url, 'osv.dev/v1/vulns/') => [
                'id' => 'DEBIAN-CVE-TEST',
                'upstream' => ['CVE-OPENSSL-TEST'],
                'summary' => 'Pufferüberlauf',
                'severity' => [['type' => 'CVSS_V3', 'score' => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:R/S:U/C:H/I:H/A:H']],
                'affected' => [['package' => ['ecosystem' => 'Debian:13', 'name' => 'openssl'],
                    'ranges' => [['events' => [['introduced' => '0'], ['fixed' => '3.5.4-1']]]]]],
            ],
            str_contains($url, 'mariadb.org') => ['releases' => ['99.0.1' => []]],
        };

        return json_encode($response);
    }

    private function notificationCount(int $userId): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM login_notifications WHERE user_id = ?', [$userId]);
    }

    public function testFundeWerdenGespeichertUndGemeldet(): void
    {
        $state = $this->check()->run();

        self::assertSame([], $state['errors']);
        self::assertSame(['CVE-PHP-TEST', 'CVE-OPENSSL-TEST'], array_column($state['findings'], 'id'));
        self::assertSame('openssl 3.5.0-1', $state['findings'][1]['component']);
        self::assertSame(2, $state['notified']);

        $saved = SecurityCheck::lastState(new Settings($this->db));
        self::assertSame(array_column($state['findings'], 'id'), array_column($saved['findings'], 'id'));

        self::assertSame(1, $this->notificationCount(1));
        self::assertSame(1, $this->notificationCount(2));
        self::assertSame(0, $this->notificationCount(3), 'Nur Global-Admins werden benachrichtigt.');
        self::assertStringContainsString(
            'CVE-PHP-TEST',
            (string) $this->db->fetchValue('SELECT message FROM login_notifications WHERE user_id = 1'),
        );
    }

    public function testBekannteFundeWerdenNichtErneutGemeldet(): void
    {
        $this->check()->run();
        $state = $this->check()->run();

        self::assertSame(0, $state['notified']);
        self::assertSame(1, $this->notificationCount(1));
    }

    public function testCacheSpartErneutenDetailabruf(): void
    {
        $this->check()->run();
        $this->requested = [];
        $this->check()->run();

        self::assertSame([], array_filter($this->requested, static fn (string $u): bool => str_contains($u, '/v1/vulns/')));
    }

    /** Ohne Netz kein „alles in Ordnung“: Die Fehler stehen im Ergebnis. */
    public function testNetzfehlerWerdenFestgehalten(): void
    {
        $state = $this->check(static function (): string {
            throw new \RuntimeException('keine Verbindung');
        })->run();

        self::assertCount(3, $state['errors']);
        self::assertSame([], $state['findings']);
        self::assertNotNull(SecurityCheck::lastState(new Settings($this->db)));
    }
}
