<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\ApiTokens;
use Tests\Support\DatabaseTestCase;

/**
 * App-Schnittstelle: Tokens gelten nur, solange sie weder widerrufen noch
 * abgelaufen sind, und ein Übergabe-Code lässt sich genau einmal einlösen.
 */
final class AppApiTokensTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->db->run("INSERT INTO schools (id, name, slug) VALUES (1, 'Testschule', 'test')");
        $this->db->run(
            "INSERT INTO users (id, school_id, username, firstname, lastname, role)
             VALUES (10, 1, 'mia', 'Mia', 'Muster', 'student')",
        );
    }

    public function testGueltigesTokenWirdErkanntUndNurAlsHashGespeichert(): void
    {
        $tokens = new ApiTokens($this->db);
        $token = $tokens->issue(10, 'Pixel 7');

        $row = $tokens->resolve($token);
        self::assertNotNull($row);
        self::assertSame(10, (int) $row['user_id']);
        self::assertSame(hash('sha256', $token), $row['token_hash']);
        self::assertNull($this->db->fetchValue('SELECT 1 FROM api_tokens WHERE token_hash = ?', [$token]));
    }

    public function testWiderrufenesAbgelaufenesUndFremdesTokenGeltenNicht(): void
    {
        $tokens = new ApiTokens($this->db);

        $revoked = $tokens->issue(10, 'A');
        $tokens->revoke((int) $tokens->resolve($revoked)['id']);
        self::assertNull($tokens->resolve($revoked), 'Widerrufen');

        $expired = $tokens->issue(10, 'B');
        $this->db->run('UPDATE api_tokens SET expires_at = NOW() - INTERVAL 1 SECOND WHERE token_hash = ?', [hash('sha256', $expired)]);
        self::assertNull($tokens->resolve($expired), 'Abgelaufen');

        self::assertNull($tokens->resolve('bm_' . str_repeat('0', 64)), 'Unbekannt');
        self::assertNull($tokens->resolve('kein-token'), 'Falsches Format');
    }

    public function testAlleTokensEinesKontosLassenSichWiderrufen(): void
    {
        $tokens = new ApiTokens($this->db);
        $a = $tokens->issue(10, 'A');
        $b = $tokens->issue(10, 'B');

        $tokens->revokeAllForUser(10);

        self::assertNull($tokens->resolve($a));
        self::assertNull($tokens->resolve($b));
    }

    public function testUebergabeCodeGiltGenauEinmal(): void
    {
        $tokens = new ApiTokens($this->db);
        $code = $tokens->createHandoff(10, '/test/tagesplan');

        self::assertSame(
            ['user_id' => 10, 'token_id' => null, 'target_path' => '/test/tagesplan', 'theme' => null],
            $tokens->consumeHandoff($code),
        );
        self::assertNull($tokens->consumeHandoff($code), 'Zweites Einlösen muss scheitern.');
    }

    /** Passwortwechsel in der App: dieses Gerät bleibt angemeldet, alle anderen nicht. */
    public function testWiderrufAllerTokensVerschontDasAusloesendeGeraet(): void
    {
        $tokens = new ApiTokens($this->db);
        $hier = $tokens->issue(10, 'Hier');
        $anderes = $tokens->issue(10, 'Anderes');
        $hierId = (int) $tokens->resolve($hier)['id'];

        $tokens->revokeAllForUser(10, $hierId);

        self::assertNotNull($tokens->resolve($hier));
        self::assertNull($tokens->resolve($anderes));
    }

    public function testUebergabeMerktSichTokenUndTheme(): void
    {
        $tokens = new ApiTokens($this->db);
        $tokenId = (int) $tokens->resolve($tokens->issue(10, 'A'))['id'];

        $code = $tokens->createHandoff(10, '/test/', $tokenId, 'dark');
        $handoff = $tokens->consumeHandoff($code);
        self::assertSame($tokenId, $handoff['token_id']);
        self::assertSame('dark', $handoff['theme']);

        $fremd = $tokens->consumeHandoff($tokens->createHandoff(10, '/test/', null, 'lila'));
        self::assertNull($fremd['theme'], 'Nur light/dark sind erlaubt.');
    }

    public function testAbgelaufenerUebergabeCodeGiltNicht(): void
    {
        $tokens = new ApiTokens($this->db);
        $code = $tokens->createHandoff(10, '/test/');
        $this->db->run('UPDATE app_handoffs SET expires_at = NOW() - INTERVAL 1 SECOND');

        self::assertNull($tokens->consumeHandoff($code));
    }

    public function testNurPfadeInnerhalbDerAnwendungSindErlaubt(): void
    {
        self::assertSame('/test/aussteller?x=1', ApiTokens::safePath('/test/aussteller?x=1'));
        self::assertNull(ApiTokens::safePath('//evil.example/'));
        self::assertNull(ApiTokens::safePath('https://evil.example/'));
        self::assertNull(ApiTokens::safePath('/\\evil.example'));
        self::assertNull(ApiTokens::safePath("/test\r\nLocation: x"));
        self::assertNull(ApiTokens::safePath(''));
        self::assertNull(ApiTokens::safePath(null));
    }
}
