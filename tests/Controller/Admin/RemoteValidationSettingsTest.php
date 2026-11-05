<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Enum\SecurityEventType;
use App\Repository\SiteSettingsRepository;
use App\Security\SecurityEventLogger;
use App\Tests\Support\AdminTestTrait;
use App\Tests\Support\MockHttpResponseFactory;
use App\Tests\Support\RemoteDecisionTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\PasswordHasher\Hasher\NativePasswordHasher;

/**
 * Paramètres des notifications et de la validation à distance : état des canaux, notification de test par
 * canal (toast de réussite, erreur claire), interrupteur désactivé par défaut, code PIN haché (6 chiffres au
 * moins, jamais réaffiché) ; fiche du recruteur (fonction, site web en texte) ; tuile Sécurité.
 *
 * Liens :
 * - Couvre : Admin\SiteSettingsController (edit, testChannel), SiteSettingsType, DashboardMetrics (tuile Sécurité)
 */
final class RemoteValidationSettingsTest extends WebTestCase
{
    use AdminTestTrait;
    use RemoteDecisionTestTrait;

    /**
     * Vide la simulation HTTP.
     */
    protected function setUp(): void
    {
        MockHttpResponseFactory::reset();
    }

    /**
     * Vérifie l'interrupteur (désactivé par défaut) et le code PIN (Communication › Validation à distance) : haché,
     * jamais réaffiché, vide = inchangé, refusé s'il a moins de 6 chiffres ou si les deux saisies diffèrent.
     */
    public function testSwitchAndPinAreSaved(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $crawler = $client->request('GET', $this->adminUrl('/communication/validation-a-distance'));
        self::assertSelectorTextContains('[data-testid="remote-readiness"]', 'Telegram : conversation et compte autorisés connus');
        self::assertSelectorTextContains('[data-testid="remote-readiness"]', 'Aucun code PIN');
        self::assertFalse(static::getContainer()->get(SiteSettingsRepository::class)->getOrCreate()->isRemoteValidationEnabled());

        $client->submit($crawler->selectButton('Enregistrer')->form(['remote_validation[remotePin][first]' => '12345', 'remote_validation[remotePin][second]' => '12345']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Le code PIN doit compter 6 à 12 chiffres.');

        $crawler = $client->request('GET', $this->adminUrl('/communication/validation-a-distance'));
        $client->submit($crawler->selectButton('Enregistrer')->form(['remote_validation[remotePin][first]' => self::TEST_PIN, 'remote_validation[remotePin][second]' => '999999']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Les deux codes PIN ne correspondent pas.');
        self::assertNull($this->pinHash());

        $crawler = $client->request('GET', $this->adminUrl('/communication/validation-a-distance'));
        $form = $crawler->selectButton('Enregistrer')->form(['remote_validation[remotePin][first]' => self::TEST_PIN, 'remote_validation[remotePin][second]' => self::TEST_PIN]);
        $switch = $form['remote_validation[remoteValidationEnabled]'];
        self::assertInstanceOf(ChoiceFormField::class, $switch);
        $switch->tick();
        $client->submit($form);
        self::assertResponseRedirects();
        $hash = $this->pinHash();
        self::assertNotNull($hash);
        self::assertStringNotContainsString(self::TEST_PIN, $hash);
        self::assertTrue((new NativePasswordHasher())->verify($hash, self::TEST_PIN));

        // Champ vide : code inchangé ; le code n'est jamais réaffiché
        $crawler = $client->request('GET', $this->adminUrl('/communication/validation-a-distance'));
        self::assertStringNotContainsString(self::TEST_PIN, (string) $client->getResponse()->getContent());
        self::assertSelectorTextContains('[data-testid="remote-readiness"]', 'Code PIN défini (Telegram et Discord)');
        $client->submit($crawler->selectButton('Enregistrer')->form());
        self::assertSame($hash, $this->pinHash());
    }

    /**
     * Vérifie le bouton « Envoyer une notification de test » : réussite en toast, erreur claire dans la page.
     */
    public function testChannelTestButtons(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $crawler = $client->request('GET', $this->adminUrl('/communication/notifications'));
        self::assertSelectorCount(3, '[data-testid="notification-channels"] form[id^="notification-test-"] button');

        $client->submit($crawler->filter('form#notification-test-telegram')->form());
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('[data-notify-target="toast"]', 'Notification de test envoyée sur Telegram.');
        self::assertCount(1, MockHttpResponseFactory::telegramCalls('sendMessage'));

        MockHttpResponseFactory::$discordStatus = 404;
        $client->submit($client->getCrawler()->filter('form#notification-test-discord')->form());
        $client->followRedirect();
        self::assertSelectorTextContains('[data-testid="flash-messages"]', 'Discord : notification de test non envoyée, Discord a refusé l\'envoi (HTTP 404 : webhook inconnu ou supprimé).');
        self::assertSelectorNotExists('[data-testid="flash-messages"] [data-notify-target="toast"]');

        $client->request('POST', $this->adminUrl('/communication/notifications/telegram/test'), ['_token' => 'faux']);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Vérifie la fiche du recruteur (fonction, site web en texte simple) et la tuile Sécurité (refus de la
     * validation à distance en alerte).
     */
    public function testRecruiterPageAndSecurityTile(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $recruiter = $this->createPendingRequest()->setCompanyWebsite('https://www.acme.example');
        static::getContainer()->get('doctrine')->getManager()->flush();

        $client->request('GET', $this->adminUrl('/recruteurs/'.$recruiter->getId()));
        self::assertSelectorTextContains('main', 'Chargée de recrutement');
        self::assertSelectorTextContains('[data-testid="recruiter-website"]', 'https://www.acme.example');
        self::assertSelectorNotExists('a[href="https://www.acme.example"]');

        $this->enableRemoteValidation();
        static::getContainer()->get(SecurityEventLogger::class)->log(SecurityEventType::RemoteDecisionRejected, 'telegram secret_invalid');
        static::getContainer()->get('cache.dashboard')->clear();
        $client->request('GET', $this->adminUrl());
        self::assertSelectorTextContains('main', 'Validation à distance sur 7 jours : 0 décision(s), 1 refus de sécurité');
    }

    /**
     * Relit l'empreinte du code PIN en base.
     */
    private function pinHash(): ?string
    {
        static::getContainer()->get('doctrine')->getManager()->clear();

        return static::getContainer()->get(SiteSettingsRepository::class)->getOrCreate()->getRemotePinHash();
    }
}
