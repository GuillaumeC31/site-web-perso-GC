<?php

declare(strict_types=1);

namespace App\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Nonce CSP stable sous Turbo : une requête Turbo qui renvoie le nonce de la page d'origine (X-Csp-Nonce)
 * reçoit ce même nonce dans sa CSP et ses scripts ; toute autre requête reçoit un nonce neuf ; une valeur
 * au mauvais format est ignorée. Turnstile reste autorisé (script-src, frame-src), le reste inchangé.
 *
 * Liens :
 * - Couvre : App\Security\CspNonceGenerator, SecurityHeadersSubscriber, templates/base.html.twig (meta csp-nonce)
 * - Voir aussi : assets/lib/csp_nonce.js (envoi de l'en-tête), assets/lib/csp_nonce.test.js
 */
final class CspNonceTest extends WebTestCase
{
    /** Nonce au format exact d'un nonce du site (base64 de 16 octets). */
    private const PAGE_NONCE = 'AbCdEfGhIjKlMnOpQrStUw==';

    /**
     * Vérifie la reprise du nonce de la page d'origine pour une requête Turbo.
     */
    public function testTurboRequestKeepsTheOriginalPageNonce(): void
    {
        $client = self::createClient();
        $client->request('GET', '/cv', server: ['HTTP_X_TURBO_REQUEST_ID' => 'abc', 'HTTP_X_CSP_NONCE' => self::PAGE_NONCE]);

        self::assertResponseIsSuccessful();
        $csp = (string) $client->getResponse()->headers->get('Content-Security-Policy');
        self::assertStringContainsString("'nonce-".self::PAGE_NONCE."'", $csp);
        self::assertSelectorExists('meta[name="csp-nonce"][content="'.self::PAGE_NONCE.'"]');
        self::assertMatchesRegularExpression('#script-src [^;]*https://challenges\.cloudflare\.com#', $csp);
        self::assertMatchesRegularExpression('#frame-src https://challenges\.cloudflare\.com#', $csp);
        self::assertStringNotContainsString("'unsafe-inline'", (string) preg_replace('/style-src[^;]*;/', '', $csp), 'Scripts : jamais unsafe-inline.');
    }

    /**
     * Vérifie qu'une requête ordinaire, ou une valeur au mauvais format, reçoit un nonce neuf.
     *
     * @param array<string, string> $server en-têtes de la requête
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideIgnoredHeaders')]
    public function testNonceIsNewOtherwise(array $server): void
    {
        $client = self::createClient();
        $client->request('GET', '/cv', server: $server);

        $csp = (string) $client->getResponse()->headers->get('Content-Security-Policy');
        self::assertMatchesRegularExpression("#'nonce-[A-Za-z0-9+/]{22}=='#", $csp);
        self::assertStringNotContainsString(self::PAGE_NONCE, $csp);
        self::assertDoesNotMatchRegularExpression('#script-src [^;]*unsafe-inline#', $csp, 'Valeur refusée : jamais recopiée dans la CSP.');
    }

    /**
     * Requêtes dont le nonce envoyé est ignoré.
     *
     * @return iterable<string, array{array<string, string>}>
     */
    public static function provideIgnoredHeaders(): iterable
    {
        yield 'requête ordinaire (pas Turbo)' => [['HTTP_X_CSP_NONCE' => self::PAGE_NONCE]];
        yield 'mauvais format' => [['HTTP_X_TURBO_REQUEST_ID' => 'abc', 'HTTP_X_CSP_NONCE' => "x' 'unsafe-inline"]];
        yield 'trop court' => [['HTTP_X_TURBO_REQUEST_ID' => 'abc', 'HTTP_X_CSP_NONCE' => 'abc==']];
    }
}
