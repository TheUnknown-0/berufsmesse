<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\SecurityCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Auswertungslogik des Sicherheitschecks, ohne Netz und ohne Datenbank.
 */
final class SecurityCheckTest extends TestCase
{
    /** @return array<string, array{string, ?float}> */
    public static function cvssVektoren(): array
    {
        return [
            'kritisch' => ['CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:H', 9.8],
            'mit Nutzerinteraktion' => ['CVSS:3.1/AV:N/AC:L/PR:N/UI:R/S:U/C:H/I:H/A:H', 8.8],
            'Informationsleck' => ['CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:L/I:N/A:N', 5.3],
            'Scope geändert' => ['CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:C/C:H/I:H/A:H', 10.0],
            'lokal mit Rechten, Scope geändert' => ['CVSS:3.0/AV:L/AC:L/PR:L/UI:N/S:C/C:H/I:N/A:N', 6.5],
            'ohne Auswirkung' => ['CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:N/I:N/A:N', 0.0],
            'CVSS 4 wird nicht berechnet' => ['CVSS:4.0/AV:N/AC:H/AT:P/PR:N/UI:N/VC:H/VI:H/VA:H/SC:N/SI:N/SA:N', null],
            'unvollständig' => ['CVSS:3.1/AV:N/AC:L', null],
        ];
    }

    #[DataProvider('cvssVektoren')]
    public function testCvss3Basiswert(string $vector, ?float $expected): void
    {
        self::assertSame($expected, SecurityCheck::cvss3BaseScore($vector));
    }

    /** @return list<array<string, mixed>> */
    private function advisories(): array
    {
        $branches = static fn (string ...$patched): array => array_map(
            static fn (string $v): array => ['patched_versions' => $v, 'vulnerable_version_range' => '< ' . $v],
            $patched,
        );

        return [
            ['cve_id' => 'CVE-KRITISCH', 'severity' => 'critical', 'summary' => 'RCE',
                'cvss_severities' => ['cvss_v4' => ['score' => 9.2]], 'vulnerabilities' => $branches('8.2.35', '8.3.36', '8.4.27')],
            ['cve_id' => 'CVE-MITTEL', 'severity' => 'medium', 'summary' => 'Leck',
                'vulnerabilities' => $branches('8.3.35')],
            ['cve_id' => null, 'ghsa_id' => 'GHSA-HOCH', 'severity' => 'high', 'summary' => 'Überlauf',
                'cvss_severities' => ['cvss_v3' => ['score' => 7.5]], 'vulnerabilities' => $branches('8.3.30')],
            ['cve_id' => 'CVE-NUR-84', 'severity' => 'critical', 'summary' => 'nur 8.4',
                'vulnerabilities' => $branches('8.4.20')],
        ];
    }

    public function testPhpAdvisoriesTreffenNurDenEigenenBranchUndHochKritisch(): void
    {
        $findings = SecurityCheck::phpFindings('8.3.29', $this->advisories());

        self::assertSame(['CVE-KRITISCH', 'GHSA-HOCH'], array_column($findings, 'id'));
        self::assertSame(['8.3.36', '8.3.30'], array_column($findings, 'fixed'));
        self::assertSame(9.2, $findings[0]['score']);
    }

    public function testGepatchteVersionIstNichtBetroffen(): void
    {
        self::assertSame([], SecurityCheck::phpFindings('8.3.36', $this->advisories()));
    }

    public function testDpkgStatusLiefertQuellpaketeMitVersion(): void
    {
        $status = <<<'TXT'
            Package: libssl3t64
            Status: install ok installed
            Source: openssl
            Version: 3.5.7-1~deb13u3

            Package: libpng16-16t64
            Status: install ok installed
            Source: libpng1.6 (1.6.48-1+deb13u6)
            Version: 1.6.48-1+deb13u6+b1

            Package: apache2
            Status: install ok installed
            Version: 2.4.69-1~deb13u1

            Package: entfernt
            Status: deinstall ok config-files
            Version: 1.0
            TXT;

        self::assertSame(
            ['apache2' => '2.4.69-1~deb13u1', 'libpng1.6' => '1.6.48-1+deb13u6', 'openssl' => '3.5.7-1~deb13u3'],
            SecurityCheck::parseDpkgStatus($status),
        );
    }

    public function testOsvEintragNimmtFixDerEigenenDebianVersion(): void
    {
        $vuln = [
            'id' => 'DEBIAN-CVE-2025-15467',
            'upstream' => ['CVE-2025-15467'],
            'summary' => 'Stack buffer overflow in CMS',
            'severity' => [['type' => 'CVSS_V3', 'score' => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:R/S:U/C:H/I:H/A:H']],
            'affected' => [
                ['package' => ['ecosystem' => 'Debian:12', 'name' => 'openssl'],
                    'ranges' => [['events' => [['introduced' => '0'], ['fixed' => '3.0.18-1~deb12u2']]]]],
                ['package' => ['ecosystem' => 'Debian:13', 'name' => 'openssl'],
                    'ecosystem_specific' => ['urgency' => 'not yet assigned'],
                    'ranges' => [['events' => [['introduced' => '0'], ['fixed' => '3.5.4-1~deb13u2']]]]],
            ],
        ];

        $entry = SecurityCheck::summarizeOsv($vuln, 'Debian:13', 'openssl');

        self::assertSame('CVE-2025-15467', $entry['cve']);
        self::assertSame('3.5.4-1~deb13u2', $entry['fixed']);
        self::assertSame(8.8, $entry['score']);
    }

    public function testOsvEintragOhneFixInDerEigenenVersion(): void
    {
        $vuln = [
            'id' => 'DEBIAN-CVE-2007-0086',
            'affected' => [['package' => ['ecosystem' => 'Debian:13', 'name' => 'apache2'],
                'ecosystem_specific' => ['urgency' => 'unimportant'],
                'ranges' => [['events' => [['introduced' => '0']]]]]],
        ];

        $entry = SecurityCheck::summarizeOsv($vuln, 'Debian:13', 'apache2');

        self::assertNull($entry['fixed']);
        self::assertNull($entry['score']);
        self::assertSame('unimportant', $entry['urgency']);
    }
}
