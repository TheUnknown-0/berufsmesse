<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Changelog;
use PHPUnit\Framework\TestCase;

/** Parser für CHANGELOG.md: eigene Datei und fremder Text von GitHub. */
final class ChangelogTest extends TestCase
{
    public function testParstVersionenDatumUndPunkte(): void
    {
        $entries = Changelog::parse(<<<'MD'
            # Änderungen

            Einleitung, wird ignoriert.

            ## 1.2.0 (2026-11-06)

            - Erster Punkt
              geht weiter.
            - Zweiter Punkt

            ## v1.1.0

            * Ohne Datum
            MD);

        self::assertSame([
            ['version' => '1.2.0', 'date' => '2026-11-06', 'items' => ['Erster Punkt geht weiter.', 'Zweiter Punkt']],
            ['version' => '1.1.0', 'date' => null, 'items' => ['Ohne Datum']],
        ], $entries);
    }

    public function testAndereUeberschriftBeendetAbschnitt(): void
    {
        $entries = Changelog::parse("## 1.0.0\n- a\n## Anhang\n- gehört nicht dazu\n");

        self::assertSame([['version' => '1.0.0', 'date' => null, 'items' => ['a']]], $entries);
    }

    public function testUnbrauchbarerTextErgibtNichts(): void
    {
        self::assertSame([], Changelog::parse("<html>404: Not Found</html>"));
    }

    public function testNeuereEintraege(): void
    {
        $entries = Changelog::parse("## 1.10.0\n- c\n## 1.9.0\n- b\n## 1.2.0\n- a\n");

        self::assertSame(['1.10.0', '1.9.0'], array_column(Changelog::newerThan($entries, '1.2.0'), 'version'));
        self::assertSame([], Changelog::newerThan($entries, '1.10.0'));
    }

    public function testPunktWirdEscaped(): void
    {
        self::assertSame(
            '<code>git pull</code> &lt;b&gt; <strong>fett</strong>',
            Changelog::itemHtml('`git pull` <b> **fett**'),
        );
    }

    public function testDatumAufDeutsch(): void
    {
        self::assertSame('6. November 2026', Changelog::formatDate('2026-11-06'));
        self::assertNull(Changelog::formatDate('2026-13-01'));
        self::assertNull(Changelog::formatDate(null));
    }

    public function testEigeneDateiHatEineGueltigeVersion(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Changelog::currentVersion());
        self::assertNotSame('0.0.0', Changelog::currentVersion());
        $versions = array_column(Changelog::local(), 'version');
        $sorted = $versions;
        usort($sorted, static fn (string $a, string $b): int => version_compare($b, $a));
        self::assertSame($sorted, $versions, 'CHANGELOG.md: neueste Version muss oben stehen.');
    }
}
