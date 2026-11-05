<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Une seule source par en-tête de sécurité (v2.0.1 : Apache et Symfony les envoyaient tous les deux).
 *
 * - Réponses de Symfony (page HTML, 404, PDF) : chaque en-tête exactement une fois, HSTS compris en HTTPS ;
 * - public/.htaccess : les en-têtes d'Apache ne visent que les fichiers statiques (STATIC_FILE, posé au premier
 *   passage de la réécriture seulement), jamais les réponses de PHP.
 *
 * Le comportement réel d'Apache a été vérifié avec Apache 2.4 + FastCGI (doublons avant, une fois après) ; en
 * ligne, scripts/security/check-prod-headers.sh contrôle la page, un fichier de build/, une 404 et un PDF.
 *
 * Liens :
 * - Couvre : EventSubscriber\SecurityHeadersSubscriber, EventSubscriber\ErrorResponseCacheSubscriber, public/.htaccess
 * - Voir aussi : tests/Security/CheckProdHeadersScriptTest.php, tests/Security/SecurityHeadersTest.php
 */
final class SingleSecurityHeadersTest extends WebTestCase
{
    /** En-têtes qui ne doivent apparaître qu'une fois. */
    private const HEADERS = [
        'Strict-Transport-Security',
        'X-Content-Type-Options',
        'X-Frame-Options',
        'Referrer-Policy',
        'Content-Security-Policy',
        'Permissions-Policy',
    ];

    /**
     * Chaque en-tête de sécurité est présent exactement une fois sur une réponse de Symfony.
     *
     * @param string $url            adresse demandée (HTTPS : HSTS n'est envoyé qu'en HTTPS)
     * @param int    $expectedStatus code HTTP attendu
     */
    #[DataProvider('provideResponses')]
    public function testEachHeaderIsSentOnce(string $url, int $expectedStatus): void
    {
        // Sans débogage, comme en production : la page d'erreur de débogage de Symfony retire la CSP
        $client = self::createClient(['debug' => false]);
        $client->request('GET', $url);

        self::assertResponseStatusCodeSame($expectedStatus);
        $headers = $client->getResponse()->headers;
        foreach (self::HEADERS as $name) {
            self::assertCount(1, $headers->all($name), $name.' doit être présent une seule fois ('.$url.')');
        }
    }

    /**
     * Réponses de Symfony à contrôler : page HTML, page 404, PDF public.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function provideResponses(): iterable
    {
        yield 'page HTML' => ['https://localhost/', 200];
        yield 'page 404' => ['https://localhost/page-qui-n-existe-pas', 404];
        yield '404 dans un sous-dossier' => ['https://localhost/cv/page-inexistante', 404];
        yield '404 sous /build/ (fichier absent)' => ['https://localhost/build/assets/absent-0000.js', 404];
        yield 'PDF public' => ['https://localhost/cv/pdf/ats', 200];
    }

    /**
     * Une réponse d'erreur n'est jamais mise en cache (no-store) : le cache de l’hébergeur (cache de l’hébergeur) gardait les 404
     * malgré « no-cache, private », et resservait une 404 d'une ancienne version (en-têtes en double, même nonce).
     *
     * @param string $url adresse en erreur
     */
    #[DataProvider('provideErrorUrls')]
    public function testErrorResponsesAreNeverCached(string $url): void
    {
        $client = self::createClient(['debug' => false]);
        $client->request('GET', $url);

        $response = $client->getResponse();
        self::assertGreaterThanOrEqual(400, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'), 'no-store attendu ('.$url.')');
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertFalse($response->headers->hasCacheControlDirective('public'));

        // Deux 404 successives : nonce différent (rien n'est resservi depuis un cache de l'application)
        $client->request('GET', $url);
        self::assertNotSame($response->headers->get('Content-Security-Policy'), $client->getResponse()->headers->get('Content-Security-Policy'));
    }

    /**
     * Adresses en erreur : 404 simple, 404 dans un sous-dossier.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideErrorUrls(): iterable
    {
        yield 'page 404' => ['https://localhost/page-qui-n-existe-pas'];
        yield '404 dans un sous-dossier' => ['https://localhost/cv/page-inexistante'];
    }

    /**
     * Les pages normales gardent leur politique de cache : seule une erreur passe en no-store.
     */
    public function testSuccessfulPageIsNotForcedToNoStore(): void
    {
        $client = self::createClient(['debug' => false]);
        $client->request('GET', 'https://localhost/mentions-legales');

        self::assertResponseIsSuccessful();
        self::assertFalse($client->getResponse()->headers->hasCacheControlDirective('no-store'));
    }

    /**
     * Le .htaccess ne pose les en-têtes d'Apache que pour les fichiers statiques, repérés au premier passage.
     */
    public function testHtaccessLimitsApacheHeadersToStaticFiles(): void
    {
        $htaccess = (string) file_get_contents(\dirname(__DIR__, 2).'/public/.htaccess');

        // Le repère n'est posé qu'au premier passage (sinon index.php, qui existe, serait pris pour un statique)
        self::assertMatchesRegularExpression('/RewriteCond %\{ENV:REDIRECT_STATUS\} =""\s+RewriteCond %\{REQUEST_FILENAME\} -f\s+RewriteRule \^ - \[L,E=STATIC_FILE:1\]/', $htaccess);
        // L'ancienne condition, vraie aussi pour index.php, a disparu
        self::assertStringNotContainsString('<If "-f %{REQUEST_FILENAME}">', $htaccess);

        // HSTS compris (v2.0.3) : chacun une seule fois, sur les fichiers statiques seulement
        foreach (['X-Content-Type-Options', 'Referrer-Policy', 'X-Frame-Options', 'Strict-Transport-Security'] as $name) {
            self::assertMatchesRegularExpression('/^\s*Header set '.$name.' "[^"]+" env=STATIC_FILE$/m', $htaccess, $name.' : fichiers statiques seulement');
            self::assertSame(1, substr_count($htaccess, 'Header set '.$name.' '), $name.' : une seule déclaration');
        }
        // Jamais dans un bloc <If> qui teste STATIC_FILE : évalué avant la réécriture, il était toujours faux (HSTS
        // absent des fichiers de build/ en v2.0.2)
        self::assertDoesNotMatchRegularExpression('/<If[^>]*STATIC_FILE/', $htaccess);
        self::assertStringNotContainsString('Content-Security-Policy', $htaccess, 'La CSP à nonce reste gérée par l\'application.');
    }
}
