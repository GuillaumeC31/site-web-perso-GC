<?php

declare(strict_types=1);

namespace App\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests des en-têtes HTTP de sécurité (App\EventSubscriber\SecurityHeadersSubscriber).
 */
final class SecurityHeadersTest extends WebTestCase
{
    /**
     * Vérifie la présence des en-têtes de sécurité attendus sur une page publique.
     */
    public function testSecurityHeadersArePresent(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');
        $response = $client->getResponse();

        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        self::assertStringContainsString('camera=()', (string) $response->headers->get('Permissions-Policy'));
    }

    /**
     * Vérifie que la CSP est stricte : scripts limités au site et au nonce, pas de cadre, pas d'eval.
     */
    public function testContentSecurityPolicyIsStrict(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');
        $csp = (string) $client->getResponse()->headers->get('Content-Security-Policy');

        self::assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9+\/=]{20,}' https:\/\/challenges\.cloudflare\.com(;|$)/", $csp);
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
        self::assertStringContainsString("object-src 'none'", $csp);
        self::assertStringNotContainsString('unsafe-eval', $csp);
        self::assertDoesNotMatchRegularExpression("/script-src[^;]*'unsafe-inline'/", $csp);
    }

    /**
     * Vérifie que chaque script en ligne de la page porte le nonce annoncé dans la CSP.
     */
    public function testInlineScriptsCarryTheCspNonce(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');
        $csp = (string) $client->getResponse()->headers->get('Content-Security-Policy');

        self::assertSame(1, preg_match("/'nonce-([^']+)'/", $csp, $matches));
        $scripts = $crawler->filter('script');
        self::assertGreaterThan(0, $scripts->count());

        foreach ($scripts as $script) {
            self::assertInstanceOf(\DOMElement::class, $script);
            self::assertSame($matches[1], $script->getAttribute('nonce'));
        }
    }

    /**
     * Vérifie que le nonce change à chaque réponse (il ne doit pas être prévisible).
     */
    public function testNonceChangesOnEachResponse(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');
        $firstCsp = $client->getResponse()->headers->get('Content-Security-Policy');
        $client->request('GET', '/');
        $secondCsp = $client->getResponse()->headers->get('Content-Security-Policy');

        self::assertNotSame($firstCsp, $secondCsp);
    }
}
