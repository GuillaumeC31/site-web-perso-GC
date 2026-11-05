<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\ContactMessage;
use App\Entity\Recruiter;
use App\Entity\SiteSettings;
use App\Enum\ContactStatus;
use App\Enum\ContactSubject;
use App\Enum\NotificationChannel;
use App\Enum\NotificationEvent;
use App\Enum\RecruiterStatus;
use App\Repository\SiteSettingsRepository;
use App\Tests\Support\AdminTestTrait;
use App\Tests\Support\ConfirmationTrait;
use App\Tests\Support\MockHttpResponseFactory;
use App\Tests\Support\RecruiterTestTrait;
use App\Tests\Support\VisibleTextAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\Mime\Email;

/**
 * Admin de l'espace recruteur (lot 6) : décisions sur les demandes, création manuelle, aperçu
 * « vu par un recruteur » sans session recruteur, messages de contact, paramètres.
 *
 * Liens :
 * - Couvre : Admin\RecruiterController, Admin\ContactMessageController, Admin\SiteSettingsController,
 *   Admin\DashboardController (compteurs), RecruiterAccessManager, RecruiterMailer
 */
final class RecruiterAdminTest extends WebTestCase
{
    use ConfirmationTrait;
    use VisibleTextAssertionsTrait;
    use AdminTestTrait;
    use RecruiterTestTrait;

    protected function setUp(): void
    {
        MockHttpResponseFactory::reset();
    }

    /**
     * Vérifie la validation d'une demande : accès de 90 jours, e-mail avec un lien de connexion qui
     * fonctionne ; le tableau de bord compte les demandes en attente.
     */
    public function testApproveSendsAWorkingLoginLink(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $recruiter = $this->createRecruiter('attente@acme.test', RecruiterStatus::Pending, null);

        $client->request('GET', $this->adminUrl());
        self::assertSelectorTextContains('[data-testid="tile-recruiters"]', 'demande(s) en attente');
        self::assertSelectorTextContains('[data-testid="tile-recruiters-value"]', '1');
        // Coordonnées privées vides : signalées dans « À compléter »
        self::assertSelectorTextContains('main', 'Espace recruteur : renseigner « Téléphone »');
        self::assertSelectorTextContains('main', 'Espace recruteur : renseigner « E-mail de contact »');

        $this->decide($client, $recruiter, 'Valider (90 jours)');
        $email = self::getMailerMessage();
        $client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', 'Accès validé pour 90 jours');
        $stored = $this->reload($recruiter);
        self::assertSame(RecruiterStatus::Approved, $stored->getStatus());
        self::assertEqualsWithDelta((new \DateTimeImmutable('+90 days'))->getTimestamp(), $stored->getAccessExpiresAt()?->getTimestamp(), 60);

        self::assertInstanceOf(Email::class, $email);
        self::assertEmailAddressContains($email, 'To', 'attente@acme.test');
        self::assertEmailAddressContains($email, 'From', 'noreply@example.test');
        self::assertEmailAddressContains($email, 'Reply-To', 'reponse@example.test');
        self::assertCount(0, $email->getAttachments());
        self::assertEmailTextBodyContains($email, '30 minutes');

        // Le lien de l'e-mail ouvre l'espace privé (pare-feu « recruiter », indépendant de l'admin)
        $crawler = $client->request('GET', $this->extractRecruiterLink($email));
        $client->submit($crawler->selectButton('Me connecter')->form());
        self::assertResponseRedirects('/espace-recruteur/accueil');
    }

    /**
     * Vérifie le refus (un e-mail sobre, sans adresse de réponse, décision du 2026-10-29), puis la révocation et le\n     * renouvellement d'un accès ouvert.
     */
    public function testRefuseRevokeAndRenew(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $pending = $this->createRecruiter('attente@acme.test', RecruiterStatus::Pending, null);
        $this->decide($client, $pending, 'Refuser');
        self::assertSame(RecruiterStatus::Refused, $this->reload($pending)->getStatus());
        self::assertEmailCount(1);
        $refusal = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $refusal);
        self::assertEmailHeaderSame($refusal, 'To', 'attente@acme.test');
        self::assertSame([], $refusal->getReplyTo());
        self::assertEmailTextBodyContains($refusal, 'formulaire de contact');

        $approved = $this->createRecruiter('valide@acme.test', RecruiterStatus::Approved, '+3 days');
        $this->decide($client, $approved, 'Renouveler (90 jours)');
        $renewed = $this->reload($approved);
        // Renouvellement : 90 jours de plus à partir de l'échéance en cours
        self::assertEqualsWithDelta((new \DateTimeImmutable('+93 days'))->getTimestamp(), $renewed->getAccessExpiresAt()?->getTimestamp(), 60);
        self::assertEmailCount(1);

        $this->decide($client, $renewed, 'Révoquer l\'accès');
        self::assertSame(RecruiterStatus::Revoked, $this->reload($approved)->getStatus());
    }

    /**
     * Vérifie la création manuelle (accès ouvert, e-mail) et le refus d'une adresse déjà enregistrée.
     */
    public function testCreateManually(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $values = ['recruiter_manual[email]' => 'direct@acme.test', 'recruiter_manual[fullName]' => 'Alex Durand', 'recruiter_manual[company]' => 'Acme'];

        $crawler = $client->request('GET', $this->adminUrl('/recruteurs/nouveau'));
        $client->submit($crawler->selectButton('Créer et envoyer le lien')->form($values));
        self::assertResponseRedirects();
        $recruiter = $this->getEntityManager()->getRepository(Recruiter::class)->findOneBy(['email' => 'direct@acme.test']);
        self::assertInstanceOf(Recruiter::class, $recruiter);
        self::assertSame(RecruiterStatus::Approved, $recruiter->getStatus());
        self::assertTrue($recruiter->isCreatedManually());
        self::assertEmailCount(1);

        $crawler = $client->request('GET', $this->adminUrl('/recruteurs/nouveau'));
        $client->submit($crawler->selectButton('Créer et envoyer le lien')->form($values));
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * Vérifie l'aperçu « vu par un recruteur » : contenu privé en lecture seule, sans session recruteur
     * (l'espace privé reste fermé ensuite).
     */
    public function testPreviewIsReadOnlyWithoutRecruiterSession(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $this->createPrivateProfile();

        $client->request('GET', $this->adminUrl('/recruteurs/apercu'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Aperçu en lecture seule');
        self::assertSelectorTextContains('main', self::PRIVATE_PHONE);
        self::assertSelectorNotExists('main form');

        $client->request('GET', '/espace-recruteur/accueil');
        self::assertResponseRedirects('/espace-recruteur');
    }

    /**
     * Vérifie les messages de contact : lecture (statut « lu »), « répondu » (date), suppression.
     */
    public function testContactMessagesWorkflow(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $message = (new ContactMessage(new \DateTimeImmutable()))->setName('Jean Dupont')->setEmail('jean@acme.test')
            ->setSubject(ContactSubject::Technical)->setMessage('Une question sur le dimensionnement.')->setConsentAt(new \DateTimeImmutable());
        $this->getEntityManager()->persist($message);
        $this->getEntityManager()->flush();

        $client->request('GET', $this->adminUrl());
        self::assertSelectorTextContains('[data-testid="tile-messages"]', 'message(s) non lu(s)');
        self::assertSelectorTextContains('[data-testid="tile-messages-value"]', '1');
        $crawler = $client->request('GET', $this->adminUrl('/messages/'.$message->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Une question sur le dimensionnement.');
        self::assertSame(ContactStatus::Read, $this->statusOf($message));

        // Répondu : le message reste lu et porte la date de réponse
        $client->submit($crawler->selectButton('Marquer comme répondu')->form());
        $this->getEntityManager()->clear();
        self::assertNotNull($this->getEntityManager()->find(ContactMessage::class, $message->getId())?->getRepliedAt());
        $crawler = $client->followRedirect();
        $client->submit($crawler->selectButton('Supprimer')->form());
        self::assertNotNull($this->statusOf($message), 'Rien n\'est supprimé avant la confirmation.');
        $this->confirmAction($client, 'Supprimer ce message ?');
        self::assertNull($this->statusOf($message));
    }

    /**
     * Vérifie l'enregistrement des paramètres (délai de réponse, validation automatique) et des canaux par événement,\n     * désormais dans Communication › Notifications.
     */
    public function testSaveSiteSettings(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);

        $crawler = $client->request('GET', $this->adminUrl('/parametres'));
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Enregistrer')->form(['site_settings[responseDelay]' => 'sous 48 h', 'site_settings[autoApprovalThreshold]' => '80']));
        self::assertResponseRedirects();

        $crawler = $client->request('GET', $this->adminUrl('/communication/notifications'));
        $form = $crawler->selectButton('Enregistrer')->form();
        $telegram = $form['communication_notifications[channels_contact]'];
        self::assertIsArray($telegram);
        foreach ($telegram as $checkbox) {
            self::assertInstanceOf(ChoiceFormField::class, $checkbox);
            if ('telegram' === $checkbox->availableOptionValues()[0]) {
                $checkbox->untick();
            }
        }
        $client->submit($form);
        self::assertResponseRedirects();

        $this->getEntityManager()->clear();
        $settings = static::getContainer()->get(SiteSettingsRepository::class)->getOrCreate();
        self::assertInstanceOf(SiteSettings::class, $settings);
        self::assertSame('sous 48 h', $settings->getResponseDelay());
        self::assertSame(80, $settings->getAutoApprovalThreshold());
        self::assertFalse($settings->isAutoApprovalEnabled());
        self::assertSame([NotificationChannel::Email, NotificationChannel::Discord], $settings->getChannels(NotificationEvent::Contact));
        $client->request('GET', '/contact');
        self::assertSelectorTextContains('main', 'sous 48 h');
    }

    /**
     * Envoie un bouton de décision de la fiche d'un recruteur ; valider, refuser et révoquer passent par la
     * page de confirmation (navigateur sans JavaScript), renouveler non.
     *
     * @param string $button texte du bouton (« Valider (90 jours) »…)
     */
    private function decide(KernelBrowser $client, Recruiter $recruiter, string $button): void
    {
        $crawler = $client->request('GET', $this->adminUrl('/recruteurs/'.$recruiter->getId()));
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton($button)->form());
        if (!str_starts_with($button, 'Renouveler')) {
            $this->confirmAction($client, $recruiter->getFullName());
        }
        self::assertResponseRedirects();
    }

    /**
     * Relit un recruteur en base (après les requêtes du client).
     */
    private function reload(Recruiter $recruiter): Recruiter
    {
        $this->getEntityManager()->clear();
        $stored = $this->getEntityManager()->find(Recruiter::class, $recruiter->getId());
        self::assertInstanceOf(Recruiter::class, $stored);

        return $stored;
    }

    /**
     * Relit le statut d'un message de contact en base, null s'il a été supprimé.
     */
    private function statusOf(ContactMessage $message): ?ContactStatus
    {
        $this->getEntityManager()->clear();

        return $this->getEntityManager()->find(ContactMessage::class, $message->getId())?->getStatus();
    }
}
