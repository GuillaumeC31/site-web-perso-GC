<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContactMessage;
use App\Tests\Support\MockHttpResponseFactory;
use App\Tests\Support\RecruiterTestTrait;
use App\Tests\Support\VisibleTextAssertionsTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Formulaire de contact (lot 6) : envoi, Turnstile, champ piège, consentement, 3 envois par heure,
 * notification sans contenu sensible.
 *
 * Liens :
 * - Couvre : ContactController, ContactType, TurnstileVerifier, NotificationDispatcher
 */
final class ContactControllerTest extends WebTestCase
{
    use VisibleTextAssertionsTrait;
    use RecruiterTestTrait;

    /** Valeurs valides du formulaire. */
    private const VALUES = [
        'contact[name]' => 'Jean Dupont', 'contact[email]' => 'jean.dupont@acme.test', 'contact[company]' => 'Acme',
        'contact[subject]' => 'technical', 'contact[message]' => 'Bonjour, une question sur le dimensionnement d\'une chambre froide.', 'contact[consent]' => '1',
    ];

    protected function setUp(): void
    {
        MockHttpResponseFactory::reset();
        self::bootKernel();
        $this->clearPublicFormLimits();
        $this->createNotifiedAdmin();
        self::ensureKernelShutdown();
    }

    /**
     * Vérifie l'envoi : message enregistré, confirmation, notifications sur les trois canaux sans le
     * message ni l'e-mail de l'expéditeur.
     */
    public function testSendMessageStoresAndNotifies(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/contact');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller="turnstile"]');

        $this->submitPublicForm($client, $crawler, 'Envoyer le message', self::VALUES);
        self::assertResponseRedirects('/contact');
        self::assertEmailCount(1);
        $notification = self::getMailerMessage();
        self::assertNotNull($notification);
        self::assertEmailAddressContains($notification, 'To', 'notifications@example.test');
        self::assertEmailTextBodyContains($notification, 'Jean Dupont');
        self::assertEmailTextBodyNotContains($notification, 'jean.dupont@acme.test');
        self::assertEmailTextBodyNotContains($notification, 'dimensionnement');
        self::assertCount(1, MockHttpResponseFactory::requestsTo('discord'));
        self::assertCount(1, MockHttpResponseFactory::requestsTo('api.telegram.org'));
        self::assertStringNotContainsString('dimensionnement', MockHttpResponseFactory::requestsTo('discord')[0]['body']);

        $client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', 'Merci, votre message est bien parti. Je vous réponds sous 24 h ouvrées.');
        $message = static::getContainer()->get(EntityManagerInterface::class)->getRepository(ContactMessage::class)->findOneBy(['email' => 'jean.dupont@acme.test']);
        self::assertNotNull($message);
        self::assertNotNull($message->getConsentAt());
    }

    /**
     * Vérifie le refus d'un jeton Turnstile invalide et l'exigence du consentement.
     */
    public function testRejectsInvalidTurnstileAndMissingConsent(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/contact');
        $this->submitPublicForm($client, $crawler, 'Envoyer le message', self::VALUES, MockHttpResponseFactory::INVALID_TOKEN);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'La vérification anti-robot a échoué');

        $crawler = $client->request('GET', '/contact');
        $values = self::VALUES;
        unset($values['contact[consent]']);
        $this->submitPublicForm($client, $crawler, 'Envoyer le message', $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Merci de cocher la case');

        // Objet obligatoire : l'option « Choisir un objet » est vide
        $crawler = $client->request('GET', '/contact');
        self::assertSelectorTextSame('#contact_subject option[value=""]', 'Choisir un objet');
        $values = self::VALUES;
        $values['contact[subject]'] = '';
        $this->submitPublicForm($client, $crawler, 'Envoyer le message', $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Choisissez l\'objet de votre message.');
        self::assertSame(0, static::getContainer()->get(EntityManagerInterface::class)->getRepository(ContactMessage::class)->count([]));
    }

    /**
     * Vérifie le champ piège : réponse normale, rien d'enregistré ni de notifié.
     */
    public function testHoneypotIsSilentlyIgnored(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/contact');
        $this->submitPublicForm($client, $crawler, 'Envoyer le message', self::VALUES + ['contact[website]' => 'https://spam.example']);
        self::assertResponseRedirects('/contact');
        self::assertEmailCount(0);
        self::assertSame(0, static::getContainer()->get(EntityManagerInterface::class)->getRepository(ContactMessage::class)->count([]));
    }

    /**
     * Vérifie la limite de 3 envois par heure et par IP.
     */
    public function testRateLimitIsThreePerHour(): void
    {
        $client = self::createClient();
        for ($i = 1; $i <= 3; ++$i) {
            $crawler = $client->request('GET', '/contact');
            $this->submitPublicForm($client, $crawler, 'Envoyer le message', self::VALUES);
            self::assertResponseRedirects('/contact');
        }
        $crawler = $client->request('GET', '/contact');
        $this->submitPublicForm($client, $crawler, 'Envoyer le message', self::VALUES);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Trop d\'envois');
    }
}
