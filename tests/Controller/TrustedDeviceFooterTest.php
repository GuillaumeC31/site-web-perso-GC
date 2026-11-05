<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\SiteSettingsRepository;
use App\Security\TrustedDeviceCookie;
use App\Tests\Support\AdminTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Cookie as HttpCookie;

/**
 * Lien vers l'admin sur le cœur du pied de page : seulement pour un appareil de confiance (cookie signé valide).
 *
 * Couvre l'absence totale de l'adresse de l'admin sans cookie (ou avec un cookie falsifié, expiré ou révoqué), la
 * présence du lien avec un cookie valide, les en-têtes de cache (jamais de mise en cache partagée d'une page qui
 * contient le lien), les attributs du cookie et la révocation depuis l'admin.
 *
 * Liens :
 * - Couvre : Security\TrustedDeviceCookie, Twig\AdminLinkExtension, EventSubscriber\TrustedDeviceCacheSubscriber,
 *   Admin\SecurityKeyController::revokeTrustedDevices(), templates/_partials/_footer.html.twig
 * - Utilise : tests/Support/AdminTestTrait
 */
final class TrustedDeviceFooterTest extends WebTestCase
{
    use AdminTestTrait;

    /** Adresse publique testée (HTTPS : le cookie est « Secure »). */
    private const PAGE = 'https://localhost/';

    /**
     * Sans cookie : le cœur est un simple caractère et l'adresse de l'admin n'apparaît nulle part.
     */
    public function testWithoutCookieAdminPathIsAbsent(): void
    {
        $client = self::createClient();
        $client->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="footer-admin-link"]');
        $this->assertAdminPathAbsent($client);
    }

    /**
     * Avec un cookie valide : le cœur devient un lien vers l'admin, avec aria-label et title « Administration ».
     */
    public function testWithValidCookieHeartLinksToAdmin(): void
    {
        $client = self::createClient();
        $this->setTrustedCookie($client, $this->signedValue('+30 days'));
        $crawler = $client->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        $link = $crawler->filter('[data-testid="footer-admin-link"]');
        self::assertCount(1, $link);
        self::assertSame($this->adminUrl(), $link->attr('href'));
        self::assertSame('Administration', $link->attr('aria-label'));
        self::assertSame('Administration', $link->attr('title'));
        self::assertSame('❤️', trim($link->text()));
    }

    /**
     * Cookie falsifié (signature modifiée, génération changée sans signer, valeur au hasard) : aucun lien.
     */
    public function testForgedCookieShowsNoLink(): void
    {
        $client = self::createClient();
        $valid = $this->signedValue('+30 days');
        [$expiresAt, $generation, $signature] = explode('.', $valid);
        $forgeries = [
            $expiresAt.'.'.$generation.'.'.strrev($signature),
            $expiresAt.'.'.((int) $generation + 1).'.'.$signature,
            ((int) $expiresAt + 86400).'.'.$generation.'.'.$signature,
            'not-a-cookie',
        ];

        foreach ($forgeries as $forged) {
            $client->getCookieJar()->clear();
            $this->setTrustedCookie($client, $forged);
            $client->request('GET', self::PAGE);

            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('[data-testid="footer-admin-link"]');
            $this->assertAdminPathAbsent($client);
        }
    }

    /**
     * Cookie correctement signé mais expiré : aucun lien.
     */
    public function testExpiredCookieShowsNoLink(): void
    {
        $client = self::createClient();
        $this->setTrustedCookie($client, $this->signedValue('-1 minute'));
        $client->request('GET', self::PAGE);

        self::assertSelectorNotExists('[data-testid="footer-admin-link"]');
        $this->assertAdminPathAbsent($client);
    }

    /**
     * Révocation : après le changement de génération, le cookie de l'ancienne génération n'affiche plus le lien.
     */
    public function testRevokedCookieShowsNoLink(): void
    {
        $client = self::createClient();
        $value = $this->signedValue('+30 days');

        $settings = self::getContainer()->get(SiteSettingsRepository::class)->getOrCreate();
        $settings->revokeTrustedDevices();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($settings);
        $entityManager->flush();

        $this->setTrustedCookie($client, $value);
        $client->request('GET', self::PAGE);

        self::assertSelectorNotExists('[data-testid="footer-admin-link"]');
        $this->assertAdminPathAbsent($client);
    }

    /**
     * Le bouton de l'admin révoque tous les appareils : génération incrémentée, journal noté, lien disparu.
     */
    public function testAdminRevokeButtonInvalidatesCookies(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $value = $this->signedValue('+30 days');
        $this->setTrustedCookie($client, $value);

        $client->request('GET', self::PAGE);
        self::assertSelectorExists('[data-testid="footer-admin-link"]');

        // Admin en HTTP explicite : après une page en HTTPS, une adresse relative resterait en HTTPS, et le jeton
        // CSRF fabriqué par le test (session HTTP) ne serait plus dans le même espace de noms.
        $client->request('GET', 'http://localhost'.$this->adminUrl('/securite/mes-cles'));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="revoke-trusted-devices"]');

        $client->request('POST', 'http://localhost'.$this->adminUrl('/securite/mes-cles/appareils-de-confiance/revocation'), [
            '_token' => $this->generateCsrfToken($client, 'revoke_trusted_devices'),
            '_confirmed' => '1',
        ]);
        self::assertResponseRedirects($this->adminUrl('/securite/mes-cles'));
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Tous les appareils de confiance ont été révoqués.');

        // La déconnexion ne supprime pas le cookie : c'est la révocation qui l'invalide.
        $client->request('GET', self::PAGE);
        self::assertSelectorNotExists('[data-testid="footer-admin-link"]');
        self::assertSame(2, self::getContainer()->get(SiteSettingsRepository::class)->getOrCreate()->getTrustedDeviceGeneration());
    }

    /**
     * Une page qui affiche le lien est privée et jamais mise en cache (no-store), et varie selon les cookies.
     */
    public function testPageWithLinkIsNeverCachedPublicly(): void
    {
        $client = self::createClient();
        $this->setTrustedCookie($client, $this->signedValue('+30 days'));
        $client->request('GET', self::PAGE);

        $response = $client->getResponse();
        self::assertSelectorExists('[data-testid="footer-admin-link"]');
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertFalse($response->headers->hasCacheControlDirective('public'));
        self::assertFalse($response->headers->hasCacheControlDirective('s-maxage'));
        self::assertContains('Cookie', $response->getVary());
    }

    /**
     * Le cookie produit par le service a les attributs attendus (180 jours, HttpOnly, Secure, SameSite=Lax).
     */
    public function testCookieAttributes(): void
    {
        self::bootKernel();
        $cookie = self::getContainer()->get(TrustedDeviceCookie::class)->create();

        self::assertSame(TrustedDeviceCookie::NAME, $cookie->getName());
        self::assertTrue($cookie->isHttpOnly());
        self::assertTrue($cookie->isSecure());
        self::assertSame(HttpCookie::SAMESITE_LAX, $cookie->getSameSite());
        self::assertSame('/', $cookie->getPath());
        self::assertEqualsWithDelta(time() + 180 * 86400, $cookie->getExpiresTime(), 120);
        self::assertMatchesRegularExpression('/^\d+\.1\.[A-Za-z0-9_-]{43}$/', (string) $cookie->getValue());
    }

    /**
     * Fabrique une valeur de cookie signée par le vrai service, pour la génération actuelle.
     *
     * @param string $relativeExpiry décalage de l'expiration (« +30 days », « -1 minute »)
     */
    private function signedValue(string $relativeExpiry): string
    {
        $container = self::getContainer();
        $generation = $container->get(SiteSettingsRepository::class)->getOrCreate()->getTrustedDeviceGeneration();

        return $container->get(TrustedDeviceCookie::class)->sign((new \DateTimeImmutable($relativeExpiry))->getTimestamp(), $generation);
    }

    /**
     * Dépose le cookie « appareil de confiance » dans le navigateur de test (Secure : envoyé en HTTPS seulement).
     *
     * @param KernelBrowser $client navigateur de test
     * @param string        $value  valeur du cookie
     */
    private function setTrustedCookie(KernelBrowser $client, string $value): void
    {
        $client->getCookieJar()->set(new Cookie(TrustedDeviceCookie::NAME, $value, null, '/', 'localhost', true, true));
    }

    /**
     * Vérifie que l'adresse de l'admin (ADMIN_PATH) n'apparaît nulle part dans le HTML de la dernière réponse.
     *
     * @param KernelBrowser $client navigateur de test
     */
    private function assertAdminPathAbsent(KernelBrowser $client): void
    {
        $adminPath = (string) self::getContainer()->getParameter('app.admin_path');
        $html = (string) $client->getResponse()->getContent();

        self::assertStringNotContainsString('"/'.$adminPath, $html);
        self::assertStringNotContainsString('/'.$adminPath.'/', $html);
        self::assertStringNotContainsString('href="/'.$adminPath, $html);
    }
}
