<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Enum\EmailType;
use App\Enum\NotificationEvent;
use App\Repository\SiteSettingsRepository;
use App\Service\Mail\EmailTexts;
use App\Service\Notification\Notification;
use App\Service\Notification\NotificationDispatcher;
use App\Tests\Support\AdminTestTrait;
use App\Tests\Support\ConfirmationTrait;
use App\Tests\Support\MockHttpResponseFactory;
use App\Tests\Support\VisibleTextAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\Mime\Email;

/**
 * Page « Communication » de l'admin : six onglets (avec encadré explicatif), secrets jamais affichés, adresses de
 * réception et e-mail de test par catégorie, routage des notifications par catégorie, ancienne adresse des
 * notifications redirigée, contenu des e-mails (aperçu avant d'enregistrer, refus des accolades inconnues, retour
 * au texte d'origine), notification « Maintenance » vers « Général du site ».
 *
 * Liens :
 * - Couvre : Admin\CommunicationController, Admin\SiteSettingsController (redirection, maintenance),
 *   templates/admin/communication/*.html.twig
 */
final class CommunicationControllerTest extends WebTestCase
{
    use AdminTestTrait;
    use ConfirmationTrait;
    use VisibleTextAssertionsTrait;

    /** Valeurs secrètes de .env.test : jamais dans les pages. */
    private const SECRETS = ['jeton-de-test-du-bot', 'secret-de-test-du-webhook', '123:test', '555f469c22188e90015bdd85b889678d459f86bdf8f7bd34971b10b9b3858609', 'api/webhooks/1/abc'];

    /**
     * Vide la simulation HTTP.
     */
    protected function setUp(): void
    {
        MockHttpResponseFactory::reset();
    }

    /**
     * Vérifie chaque onglet : réponse 200, onglet actif (aria-current), encadré explicatif, aucun secret.
     */
    public function testTabsHaveInfoBoxAndNoSecret(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $tabs = ['' => 'Vue d\'ensemble', '/e-mails' => 'E-mails', '/notifications' => 'Notifications', '/discord' => 'Discord', '/telegram' => 'Telegram', '/validation-a-distance' => 'Validation à distance'];
        foreach ($tabs as $suffix => $label) {
            $crawler = $client->request('GET', $this->adminUrl('/communication'.$suffix));
            self::assertResponseIsSuccessful($label);
            self::assertSame($label, trim($crawler->filter('[data-testid="communication-tabs"] [aria-current="page"]')->text()));
            self::assertCount(1, $crawler->filter('[data-testid="info-box"]'), $label);
            self::assertStringContainsString('À quoi ça sert', $crawler->filter('[data-testid="info-box"]')->text());
            $html = (string) $client->getResponse()->getContent();
            foreach (self::SECRETS as $secret) {
                self::assertStringNotContainsString($secret, $html, $label);
            }
        }
        // Menu de l'admin et état « configuré » (sans valeur)
        $crawler = $client->request('GET', $this->adminUrl('/communication/discord'));
        self::assertGreaterThanOrEqual(1, $crawler->filter('nav a[href="'.$this->adminUrl('/communication').'"]')->count());
        self::assertSelectorTextContains('[data-testid="discord-state"]', 'Configuré');
        self::assertSelectorTextContains('[data-testid="discord-mode"]', 'Boutons dans Discord');
        self::assertSelectorExists('[data-testid="help"] summary');
    }

    /**
     * Vérifie l'enregistrement des adresses puis l'e-mail de test de chaque catégorie, chacun à son adresse ; la
     * Calculatrice vide se replie sur « Général du site ».
     */
    public function testAddressesAndTestEmails(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $crawler = $client->request('GET', $this->adminUrl('/communication/e-mails'));
        $client->submit($crawler->filter('#adresses form')->form([
            'communication_addresses[recruitmentEmail]' => 'Recrutement@example.test',
            'communication_addresses[generalEmail]' => 'general@example.test',
            'communication_addresses[mailFromName]' => 'Guillaume Couget',
        ]));
        self::assertResponseRedirects();
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('[data-testid="address-calculator"]', 'repli sur Général du site');

        $expected = ['Recrutement' => 'recrutement@example.test', 'Général du site' => 'general@example.test', 'Calculatrice' => 'general@example.test'];
        foreach ($expected as $label => $address) {
            $client->submit($crawler->selectButton('Tester « '.$label.' »')->form());
            self::assertEmailCount(1);
            $email = self::getMailerMessage();
            self::assertInstanceOf(Email::class, $email);
            self::assertSame($address, $email->getTo()[0]->getAddress(), $label);
            self::assertSame('Guillaume Couget', $email->getFrom()[0]->getName());
            self::assertSame('noreply@example.test', $email->getFrom()[0]->getAddress());
            $crawler = $client->followRedirect();
            self::assertSelectorTextContains('[data-notify-target="toast"]', 'E-mail de test « '.$label.' » envoyé à '.$address);
        }
        // Historique : trois envois, destinataires masqués
        self::assertSelectorTextContains('[data-testid="email-log"]', 'r***@guillaume-couget.fr');
        self::assertSelectorTextNotContains('[data-testid="email-log"]', 'recrutement@example.test');
        self::assertSelectorTextContains('[data-testid="deliverability-na"]', 'Non applicable en local');
    }

    /**
     * Vérifie le routage des notifications par catégorie : contact vers « Recrutement », maintenance vers « Général ».
     */
    public function testNotificationsAreRoutedByCategory(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $this->saveAddresses('recrutement@example.test', 'general@example.test');
        $dispatcher = static::getContainer()->get(NotificationDispatcher::class);

        $dispatcher->notify(new Notification(NotificationEvent::Contact, 'Nouveau message de contact', ['Nom' => 'Camille'], 'https://localhost/admin'));
        $dispatcher->notify(new Notification(NotificationEvent::Maintenance, 'Mode maintenance activé', [], 'https://localhost/admin'));
        self::assertEmailCount(2);
        $contact = self::getMailerMessage(0);
        $maintenance = self::getMailerMessage(1);
        self::assertInstanceOf(Email::class, $contact);
        self::assertInstanceOf(Email::class, $maintenance);
        self::assertSame('recrutement@example.test', $contact->getTo()[0]->getAddress());
        self::assertSame('[Site] Nouveau message de contact', $contact->getSubject());
        self::assertSame('general@example.test', $maintenance->getTo()[0]->getAddress());

        // Le tableau affiche la catégorie de chaque événement
        $crawler = $client->request('GET', $this->adminUrl('/communication/notifications'));
        self::assertStringContainsString('Recrutement', $crawler->filter('tr[data-event="contact"] [data-testid="event-category"]')->text());
        self::assertStringContainsString('Général du site', $crawler->filter('tr[data-event="maintenance"] [data-testid="event-category"]')->text());
    }

    /**
     * Vérifie l'ancienne adresse des notifications (redirection permanente) et la carte de renvoi de Paramètres.
     */
    public function testOldNotificationsAddressRedirects(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $client->request('GET', $this->adminUrl('/parametres/notifications'));
        self::assertResponseStatusCodeSame(301);
        self::assertResponseRedirects($this->adminUrl('/communication/notifications'));

        $client->request('GET', $this->adminUrl('/parametres'));
        self::assertSelectorExists('#notifications a[href="'.$this->adminUrl('/communication/notifications').'"]');
        self::assertSelectorNotExists('input[name^="site_settings[channels_"]');
        self::assertSelectorNotExists('input[name="site_settings[remotePin]"]');
    }

    /**
     * Vérifie l'enregistrement des canaux par événement dans le nouvel onglet.
     */
    public function testChannelsAreSaved(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $crawler = $client->request('GET', $this->adminUrl('/communication/notifications'));
        $form = $crawler->selectButton('Enregistrer')->form();
        $channels = $form['communication_notifications[channels_maintenance]'];
        self::assertIsArray($channels);
        foreach ($channels as $checkbox) {
            self::assertInstanceOf(ChoiceFormField::class, $checkbox);
            if ('email' !== $checkbox->availableOptionValues()[0]) {
                $checkbox->untick();
            }
        }
        $client->submit($form);
        self::assertResponseRedirects();
        static::getContainer()->get('doctrine')->getManager()->clear();
        $settings = static::getContainer()->get(SiteSettingsRepository::class)->getOrCreate();
        self::assertSame(['email'], array_map(static fn ($c): string => $c->value, $settings->getChannels(NotificationEvent::Maintenance)));
    }

    /**
     * Vérifie le contenu d'un e-mail : aperçu avant d'enregistrer (rien n'est gardé), accolade inconnue refusée,
     * enregistrement, historique, puis retour au texte d'origine (confirmation).
     */
    public function testEmailTextPreviewSaveAndReset(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $crawler = $client->request('GET', $this->adminUrl('/communication/e-mails/contenu/refusal'));
        self::assertSelectorTextContains('[data-testid="preview-subject"]', EmailType::Refusal->defaultSubject());
        self::assertSelectorTextContains('[data-testid="variables"]', '{nom}, {entreprise}, {delai}');
        self::assertSelectorTextContains('main', 'Sans adresse de réponse');

        $client->submit($crawler->selectButton('Aperçu')->form(['email_text[subject]' => 'Bonjour {prenom}']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Accolade non permise');

        $client->submit($client->getCrawler()->selectButton('Aperçu')->form(['email_text[subject]' => 'Réponse à {nom} ({entreprise})', 'email_text[intro]' => 'Merci <b>beaucoup</b>']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Texte simple seulement');

        $client->submit($client->getCrawler()->selectButton('Aperçu')->form(['email_text[subject]' => 'Réponse à {nom} ({entreprise})', 'email_text[intro]' => 'Merci pour votre demande.']));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="preview-subject"]', 'Réponse à Camille Exemple (Entreprise Exemple)');
        self::assertStringContainsString('Merci pour votre demande.', $client->getCrawler()->filter('[data-testid="preview-text"]')->text());
        self::assertFalse(static::getContainer()->get(EmailTexts::class)->raw(EmailType::Refusal)['modified'], 'L\'aperçu n\'enregistre rien.');

        $client->submit($client->getCrawler()->selectButton('Enregistrer')->form(['email_text[subject]' => 'Réponse à {nom} ({entreprise})', 'email_text[intro]' => 'Merci pour votre demande.']));
        self::assertResponseRedirects();
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('[data-testid="text-history"]', 'Textes enregistrés');
        self::assertTrue(static::getContainer()->get(EmailTexts::class)->raw(EmailType::Refusal)['modified']);

        $client->submit($crawler->selectButton('Rétablir le texte d\'origine')->form());
        $this->confirmAction($client, 'Rétablir le texte d\'origine ?');
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('[data-testid="text-history"]', 'Texte d\'origine rétabli');
        self::assertFalse(static::getContainer()->get(EmailTexts::class)->raw(EmailType::Refusal)['modified']);
    }

    /**
     * Vérifie la notification « Maintenance » : activer le mode maintenance prévient l'adresse « Général du site ».
     */
    public function testMaintenanceNotifiesGeneralAddress(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $this->saveAddresses('recrutement@example.test', 'general@example.test');
        $crawler = $client->request('GET', $this->adminUrl('/parametres'));
        $form = $crawler->selectButton('Enregistrer')->form();
        $switch = $form['site_settings[maintenanceEnabled]'];
        self::assertInstanceOf(ChoiceFormField::class, $switch);
        $switch->tick();
        $client->submit($form);
        $this->confirmAction($client);
        self::assertResponseRedirects();
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('general@example.test', $email->getTo()[0]->getAddress());
        self::assertSame('[Site] Mode maintenance activé', $email->getSubject());
    }

    /**
     * Enregistre les adresses « Recrutement » et « Général du site ».
     */
    private function saveAddresses(string $recruitment, string $general): void
    {
        $settings = static::getContainer()->get(SiteSettingsRepository::class)->getOrCreate()->setRecruitmentEmail($recruitment)->setGeneralEmail($general);
        $entityManager = $this->getEntityManager();
        $entityManager->persist($settings);
        $entityManager->flush();
    }
}
