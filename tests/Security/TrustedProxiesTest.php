<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\CloudflareIpRanges;
use App\Tests\Support\MockHttpResponseFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Adresse IP réelle des visiteurs derrière Cloudflare (v2.0.3) : seules les plages officielles de Cloudflare (et les
 * mandataires de SYMFONY_TRUSTED_PROXIES) peuvent fournir l'adresse du visiteur par X-Forwarded-For ; un faux
 * en-tête envoyé directement est ignoré ; la limite de débit du formulaire de contact s'applique par visiteur ; la
 * vérification Turnstile transmet l'adresse du visiteur (remoteip).
 *
 * Liens :
 * - Couvre : DependencyInjection\TrustedProxiesEnvVarProcessor, Security\CloudflareIpRanges,
 *   config/packages/framework.yaml (trusted_proxies, trusted_headers), ContactController (limite par IP),
 *   Security\TurnstileVerifier (remoteip)
 */
final class TrustedProxiesTest extends WebTestCase
{
    /** Adresse d'un serveur de Cloudflare (plage 162.158.0.0/15). */
    private const CLOUDFLARE_IP = '162.158.12.34';

    /** Adresse d'un client qui contacte le serveur directement (hors Cloudflare). */
    private const DIRECT_IP = '198.51.100.9';

    /** Valeurs valides du formulaire de contact (personne fictive). */
    private const CONTACT_VALUES = [
        'contact[name]' => 'Camille Exemple', 'contact[email]' => 'camille@acme.test', 'contact[company]' => 'Acme',
        'contact[subject]' => 'technical', 'contact[message]' => 'Bonjour, une question sur le dimensionnement d\'une chambre froide.', 'contact[consent]' => '1',
    ];

    /**
     * Remet les réponses simulées et la variable des mandataires supplémentaires à zéro.
     */
    protected function setUp(): void
    {
        MockHttpResponseFactory::reset();
        unset($_SERVER['SYMFONY_TRUSTED_PROXIES'], $_ENV['SYMFONY_TRUSTED_PROXIES']);
    }

    /**
     * Retire la variable posée par un test.
     */
    protected function tearDown(): void
    {
        unset($_SERVER['SYMFONY_TRUSTED_PROXIES'], $_ENV['SYMFONY_TRUSTED_PROXIES']);
        parent::tearDown();
    }

    /**
     * Requête relayée par Cloudflare : l'adresse du visiteur (X-Forwarded-For) est retenue, HTTPS aussi.
     */
    public function testVisitorIpBehindCloudflareIsUsed(): void
    {
        $client = $this->clientFrom(self::CLOUDFLARE_IP, '203.0.113.7', 'https');

        $client->request('GET', '/mentions-legales');

        self::assertResponseIsSuccessful();
        self::assertSame('203.0.113.7', $client->getRequest()->getClientIp());
        self::assertTrue($client->getRequest()->isSecure());
    }

    /**
     * Requête directe (hors Cloudflare) avec un faux en-tête : en-tête ignoré, adresse de connexion retenue.
     */
    public function testForgedHeaderFromDirectClientIsIgnored(): void
    {
        $client = $this->clientFrom(self::DIRECT_IP, '203.0.113.7', 'https');

        $client->request('GET', '/mentions-legales');

        self::assertSame(self::DIRECT_IP, $client->getRequest()->getClientIp());
        self::assertFalse($client->getRequest()->isSecure(), 'X-Forwarded-Proto ignoré hors mandataire de confiance.');
    }

    /**
     * Faux X-Forwarded-For envoyé à Cloudflare : Cloudflare ajoute la vraie adresse à la fin, c'est elle qui est
     * retenue (lecture de droite à gauche), jamais l'adresse inventée.
     */
    public function testForgedHeaderRelayedByCloudflareIsIgnored(): void
    {
        $client = $this->clientFrom(self::CLOUDFLARE_IP, '10.0.0.1, 203.0.113.7', 'https');

        $client->request('GET', '/mentions-legales');

        self::assertSame('203.0.113.7', $client->getRequest()->getClientIp());
    }

    /**
     * X-Forwarded-Host n'est jamais cru (Cloudflare transmet les en-têtes du client tels quels).
     */
    public function testForwardedHostIsNeverTrusted(): void
    {
        $client = self::createClient([], ['REMOTE_ADDR' => self::CLOUDFLARE_IP, 'HTTP_X_FORWARDED_FOR' => '203.0.113.7', 'HTTP_X_FORWARDED_HOST' => 'pirate.example.org']);

        $client->request('GET', '/mentions-legales');

        self::assertSame('localhost', $client->getRequest()->getHost());
    }

    /**
     * Mandataire supplémentaire (cache de l’hébergeur) déclaré dans SYMFONY_TRUSTED_PROXIES : la chaîne
     * visiteur → Cloudflare → cache de l’hébergeur donne l'adresse du visiteur ; sans la déclaration, l'adresse du cache.
     */
    public function testExtraProxyFromEnvironment(): void
    {
        $client = $this->clientFrom('127.0.0.1', '203.0.113.7, '.self::CLOUDFLARE_IP, 'https');
        $client->request('GET', '/mentions-legales');
        self::assertSame('127.0.0.1', $client->getRequest()->getClientIp(), 'Cache non déclaré : son adresse est retenue.');
        self::ensureKernelShutdown();

        $_SERVER['SYMFONY_TRUSTED_PROXIES'] = $_ENV['SYMFONY_TRUSTED_PROXIES'] = '127.0.0.1';
        $client = $this->clientFrom('127.0.0.1', '203.0.113.7, '.self::CLOUDFLARE_IP, 'https');
        $client->request('GET', '/mentions-legales');
        self::assertSame('203.0.113.7', $client->getRequest()->getClientIp());
        self::assertContains('127.0.0.1', Request::getTrustedProxies());
        self::assertContains('2606:4700::/32', Request::getTrustedProxies(), 'Plages IPv6 de Cloudflare incluses.');
    }

    /**
     * Limite du formulaire de contact (3 par heure) appliquée par visiteur : un visiteur bloqué ne bloque pas les
     * autres, alors que tous arrivent par la même adresse de Cloudflare ; Turnstile reçoit l'adresse du visiteur.
     */
    public function testRateLimitIsPerVisitorAndTurnstileGetsVisitorIp(): void
    {
        $client = $this->clientFrom(self::CLOUDFLARE_IP, '203.0.113.7', 'https');
        for ($i = 1; $i <= 3; ++$i) {
            $this->sendContact($client);
            self::assertResponseRedirects('/contact');
        }
        $this->sendContact($client);
        self::assertResponseStatusCodeSame(422, 'Quatrième envoi du même visiteur refusé.');

        $body = (string) (MockHttpResponseFactory::requestsTo('challenges.cloudflare.com')[0]['body'] ?? '');
        self::assertStringContainsString('remoteip=203.0.113.7', $body, 'Adresse du visiteur transmise à Turnstile.');

        $client->setServerParameter('HTTP_X_FORWARDED_FOR', '203.0.113.99');
        $this->sendContact($client);
        self::assertResponseRedirects('/contact', null, 'Autre visiteur derrière la même adresse Cloudflare : accepté.');
    }

    /**
     * Le fichier versionné contient des plages IPv4 et IPv6 valides.
     */
    public function testRangesFileIsWellFormed(): void
    {
        $ranges = CloudflareIpRanges::read(\dirname(__DIR__, 2));

        self::assertNotEmpty($ranges['ipv4']);
        self::assertNotEmpty($ranges['ipv6']);
        foreach ([...$ranges['ipv4'], ...$ranges['ipv6']] as $range) {
            self::assertMatchesRegularExpression('#^[0-9a-f:.]+/\d{1,3}$#', $range);
        }
        self::assertTrue(CloudflareIpRanges::contains(\dirname(__DIR__, 2), self::CLOUDFLARE_IP));
        self::assertFalse(CloudflareIpRanges::contains(\dirname(__DIR__, 2), self::DIRECT_IP));
    }

    /**
     * Crée un client dont les requêtes arrivent d'une adresse donnée, avec X-Forwarded-For et X-Forwarded-Proto.
     *
     * @param string $remoteAddr adresse de connexion (REMOTE_ADDR)
     * @param string $forwarded  valeur de X-Forwarded-For
     * @param string $proto      valeur de X-Forwarded-Proto
     */
    private function clientFrom(string $remoteAddr, string $forwarded, string $proto): KernelBrowser
    {
        return self::createClient([], [
            'REMOTE_ADDR' => $remoteAddr,
            'HTTP_X_FORWARDED_FOR' => $forwarded,
            'HTTP_X_FORWARDED_PROTO' => $proto,
        ]);
    }

    /**
     * Ouvre le formulaire de contact et l'envoie (jeton Turnstile valide simulé).
     *
     * @param KernelBrowser $client client de test
     */
    private function sendContact(KernelBrowser $client): void
    {
        $crawler = $client->request('GET', '/contact');
        $form = $crawler->selectButton('Envoyer le message')->form(self::CONTACT_VALUES);
        $parameters = $form->getPhpValues();
        $parameters['cf-turnstile-response'] = 'valid-token';
        // Origine en https : derrière Cloudflare, la requête est vue en HTTPS (X-Forwarded-Proto de confiance), et
        // le jeton CSRF « sans état » est vérifié par l'en-tête Origin
        $client->request('POST', '/contact', $parameters, [], ['HTTP_ORIGIN' => 'https://localhost']);
    }
}
