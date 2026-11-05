<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Tests\Support\AdminTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Diagnostic IP temporaire de l'admin (v2.0.3) : 404 tant que IP_DIAGNOSTIC n'est pas à 1 ; sinon, page privée
 * (jamais en cache) qui affiche REMOTE_ADDR, la chaîne X-Forwarded-For et l'adresse retenue par Symfony ;
 * inaccessible sans connexion complète.
 *
 * Liens :
 * - Couvre : Controller\Admin\IpDiagnosticController, templates/admin/ip_diagnostic/show.html.twig
 */
final class IpDiagnosticControllerTest extends WebTestCase
{
    use AdminTestTrait;

    /**
     * Retire la variable posée par un test.
     */
    protected function tearDown(): void
    {
        unset($_SERVER['IP_DIAGNOSTIC'], $_ENV['IP_DIAGNOSTIC']);
        parent::tearDown();
    }

    /**
     * Désactivé par défaut : 404, même pour l'admin connecté.
     */
    public function testDisabledByDefault(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);

        $client->request('GET', $this->adminUrl('/diagnostic-ip'));

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Activé : chaîne reçue et adresse retenue (visiteur derrière Cloudflare), page jamais mise en cache.
     */
    public function testEnabledShowsChain(): void
    {
        $_SERVER['IP_DIAGNOSTIC'] = $_ENV['IP_DIAGNOSTIC'] = '1';
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $client->setServerParameters(['REMOTE_ADDR' => '162.158.12.34', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7', 'HTTP_CF_CONNECTING_IP' => '203.0.113.7']);

        $client->request('GET', $this->adminUrl('/diagnostic-ip'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="client-ip"]', '203.0.113.7');
        self::assertSelectorTextContains('body', '162.158.12.34 (REMOTE_ADDR)');
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
    }

    /**
     * Activé mais sans connexion : redirection vers la connexion de l'admin.
     */
    public function testRequiresAdmin(): void
    {
        $_SERVER['IP_DIAGNOSTIC'] = $_ENV['IP_DIAGNOSTIC'] = '1';
        $client = self::createClient();

        $client->request('GET', $this->adminUrl('/diagnostic-ip'));

        self::assertResponseRedirects();
        self::assertStringContainsString('/connexion', (string) $client->getResponse()->headers->get('Location'));
    }
}
