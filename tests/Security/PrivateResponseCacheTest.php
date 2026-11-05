<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Enum\RecruiterStatus;
use App\Tests\Support\AdminTestTrait;
use App\Tests\Support\RecruiterTestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Aucune page privée ou personnalisée n'est mise en cache (v2.0.3) : « no-store, private » et « Vary: Cookie » sur
 * l'admin, l'espace recruteur (pages et PDF du CV complet), la connexion et la déconnexion, et les pages qui
 * contiennent un jeton CSRF ; une page publique sans formulaire garde sa politique habituelle.
 *
 * Liens :
 * - Couvre : EventSubscriber\PrivateResponseCacheSubscriber
 * - Voir aussi : tests/Security/SingleSecurityHeadersTest (réponses d'erreur), tests/Controller/TrustedDeviceFooterTest
 */
final class PrivateResponseCacheTest extends WebTestCase
{
    use AdminTestTrait;
    use RecruiterTestTrait;

    /**
     * Pages avec un formulaire et un jeton CSRF (contact, demande d'accès) : jamais en cache.
     */
    public function testFormPagesAreNeverCached(): void
    {
        $client = self::createClient();
        foreach (['/contact', '/espace-recruteur'] as $url) {
            $client->request('GET', $url);
            self::assertResponseIsSuccessful();
            $this->assertNeverCached($client, $url);
        }
    }

    /**
     * Admin : page de connexion, tableau de bord, déconnexion.
     */
    public function testAdminResponsesAreNeverCached(): void
    {
        $client = self::createClient();
        $client->request('GET', $this->adminUrl('/connexion'));
        $this->assertNeverCached($client, 'connexion admin');

        $this->loginFullyAsAdmin($client);
        $client->request('GET', $this->adminUrl());
        self::assertResponseIsSuccessful();
        $this->assertNeverCached($client, 'tableau de bord');

        $client->request('GET', $this->adminUrl('/deconnexion'));
        $this->assertNeverCached($client, 'déconnexion admin');
    }

    /**
     * Espace recruteur connecté : accueil privé et PDF du CV complet ; page privée sans connexion (redirection).
     */
    public function testRecruiterAreaIsNeverCached(): void
    {
        $client = self::createClient();
        $client->request('GET', '/espace-recruteur/accueil');
        self::assertResponseRedirects('/espace-recruteur');
        $this->assertNeverCached($client, 'accueil privé sans connexion');

        $client->loginUser($this->createRecruiter('rh@acme.test', RecruiterStatus::Approved, '+30 days'), 'recruiter');
        $client->request('GET', '/espace-recruteur/accueil');
        self::assertResponseIsSuccessful();
        $this->assertNeverCached($client, 'accueil privé');

        $client->request('GET', '/espace-recruteur/cv/design');
        self::assertResponseIsSuccessful();
        $this->assertNeverCached($client, 'PDF du CV complet');
    }

    /**
     * Page publique sans formulaire ni session : politique habituelle (pas de no-store imposé).
     */
    public function testPublicPageWithoutFormKeepsItsPolicy(): void
    {
        $client = self::createClient();
        $client->request('GET', '/mentions-legales');

        self::assertResponseIsSuccessful();
        self::assertFalse($client->getResponse()->headers->hasCacheControlDirective('no-store'));
    }

    /**
     * Vérifie « no-store, private » et « Vary: Cookie » sur la dernière réponse.
     *
     * @param KernelBrowser $client client de test
     * @param string        $label  page vérifiée (message d'échec)
     */
    private function assertNeverCached(KernelBrowser $client, string $label): void
    {
        $response = $client->getResponse();
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'), $label.' : no-store');
        self::assertTrue($response->headers->hasCacheControlDirective('private'), $label.' : private');
        self::assertFalse($response->headers->hasCacheControlDirective('public'), $label.' : jamais public');
        self::assertContains('Cookie', $response->getVary(), $label.' : Vary: Cookie');
    }
}
