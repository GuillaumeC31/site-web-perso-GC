<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Recruiter;
use App\Entity\RecruiterEvent;
use App\Enum\RecruiterEventType;
use App\Enum\RecruiterStatus;
use App\Repository\RecruiterRepository;
use App\Service\Cv\PublicCvPdfCache;
use App\Service\Recruiter\RecruiterAccessManager;
use App\Tests\Support\ConfirmationTrait;
use App\Tests\Support\MockHttpResponseFactory;
use App\Tests\Support\RecruiterTestTrait;
use App\Tests\Support\SignatureClock;
use App\Tests\Support\VisibleTextAssertionsTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Espace recruteur (lot 6) : demande d'accès (avec accusé de réception), réponse identique pour une adresse
 * connue ou non, lien de connexion (confirmation en POST, usage unique, expiré après 30 minutes, caduc après
 * révocation), espace privé (coordonnées, vrais noms d'entreprises, CV complet), signaux d'intérêt regroupés,
 * prolongation par lien signé, suppression de ses données par le recruteur.
 *
 * Liens :
 * - Couvre : RecruiterAreaController, RecruiterAccessManager, RecruiterMailer, InterestSignalNotifier,
 *   RecruiterUserChecker, RecruiterLoginSubscriber, pare-feu « recruiter » (security.yaml)
 */
final class RecruiterAreaControllerTest extends WebTestCase
{
    use ConfirmationTrait;
    use ClockSensitiveTrait;
    use VisibleTextAssertionsTrait;
    use RecruiterTestTrait;

    /** Réponse unique du formulaire « Recevoir un lien » (TEXTES_SITE § 11). */
    private const LINK_RESPONSE = 'Si un accès est associé à cette adresse, un lien de connexion vient de vous être envoyé.';

    protected function setUp(): void
    {
        MockHttpResponseFactory::reset();
        SignatureClock::reset();
        self::bootKernel();
        $this->clearPublicFormLimits();
        $this->createNotifiedAdmin();
        self::ensureKernelShutdown();
    }

    /**
     * Remet l'horloge simulée des liens à l'heure réelle.
     */
    protected function tearDown(): void
    {
        SignatureClock::reset();
        parent::tearDown();
    }

    /**
     * Vérifie une demande d'accès : enregistrée en attente avec son score, notifiée sans le message, accusé
     * de réception au recruteur (sans lien de connexion) ; une nouvelle demande en attente : e-mail « déjà en
     * cours d'examen » au plus une fois par 24 h, sans renotifier l'admin, même message affiché.
     */
    public function testAccessRequestIsStoredScoredAndNotified(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/espace-recruteur');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, '[data-controller="turnstile"]');

        $this->submitPublicForm($client, $crawler, 'Envoyer ma demande', [
            'recruiter_request[email]' => 'Camille@acme.test', 'recruiter_request[fullName]' => 'Camille Martin',
            'recruiter_request[company]' => 'Acme', 'recruiter_request[message]' => 'Poste de responsable BE à Lyon.', 'recruiter_request[consent]' => '1',
            'recruiter_request[jobTitle]' => 'Chargée de recrutement', 'recruiter_request[companyWebsite]' => 'https://www.acme.example',
        ]);
        self::assertResponseRedirects('/espace-recruteur');
        $recruiter = static::getContainer()->get(RecruiterRepository::class)->findOneByEmail('camille@acme.test');
        self::assertNotNull($recruiter);
        self::assertSame(RecruiterStatus::Pending, $recruiter->getStatus());
        // MX + 30, domaine professionnel + 40, entreprise + 20, message + 10
        self::assertSame(100, $recruiter->getScore());
        self::assertEmailCount(2);
        $acknowledgement = $this->lastEmailTo('camille@acme.test');
        self::assertEmailTextBodyContains($acknowledgement, '24 h ouvrées');
        self::assertEmailTextBodyNotContains($acknowledgement, '/espace-recruteur/');
        self::assertSame('Chargée de recrutement', $recruiter->getJobTitle());
        self::assertSame('https://www.acme.example', $recruiter->getCompanyWebsite());
        // Notification pour décider (décision du 2026-10-26) : détails sur tous les canaux, jamais l'IP ; adresse
        // complète dans l'e-mail à Guillaume seulement (v2.0.3), domaine seul sur Discord et Telegram
        $notification = $this->lastEmailTo('notifications@example.test');
        foreach (['Camille Martin', 'Acme', 'Chargée de recrutement', 'https://www.acme.example', '@acme.example', 'Poste de responsable BE à Lyon.'] as $expected) {
            self::assertEmailTextBodyContains($notification, $expected);
        }
        self::assertEmailTextBodyContains($notification, 'camille@acme.test');
        self::assertEmailTextBodyNotContains($notification, '127.0.0.1');
        $chats = [MockHttpResponseFactory::requestsTo('discord'), MockHttpResponseFactory::requestsTo('api.telegram.org')];
        foreach ($chats as $requests) {
            self::assertCount(1, $requests);
            self::assertStringContainsString('@acme.example', $requests[0]['body']);
            self::assertStringContainsString('Lyon', $requests[0]['body']);
            self::assertStringNotContainsString('camille@acme.test', $requests[0]['body']);
            self::assertStringNotContainsString('127.0.0.1', $requests[0]['body']);
            // Validation à distance désactivée par défaut : aucun bouton ni lien de décision
            self::assertStringNotContainsString('validation-distance', $requests[0]['body']);
            self::assertStringNotContainsString('callback_data', $requests[0]['body']);
        }

        // Même adresse, demande toujours en attente : e-mail « déjà en cours d'examen » au recruteur, l'admin
        // n'est pas renotifié, le message affiché est le même
        self::mockTime('2026-10-27 10:00:00');
        $this->resendRequest($client);
        self::assertEmailCount(1);
        $pending = $this->lastEmailTo('camille@acme.test');
        self::assertSame('Votre demande d\'accès est déjà en cours d\'examen', $pending->getSubject());
        self::assertEmailTextBodyContains($pending, 'déjà en cours d\'examen');
        self::assertEmailTextBodyContains($pending, 'sous 24 h ouvrées');
        self::assertEmailTextBodyNotContains($pending, '/espace-recruteur/');
        self::assertCount(1, MockHttpResponseFactory::requestsTo('discord'), 'Pas de nouvelle notification.');
        $client->followRedirect();
        self::assertSelectorTextContains('[data-notify-target="toast"]', 'Demande reçue. Vous recevrez un e-mail dès sa validation (sous 24 h ouvrées).');

        // Moins de 24 h plus tard : rien de plus ; même message affiché
        $this->clearPublicFormLimits();
        self::mockTime('2026-10-28 09:59:00');
        $this->resendRequest($client);
        self::assertEmailCount(0);
        $client->followRedirect();
        self::assertSelectorTextContains('[data-notify-target="toast"]', 'Demande reçue. Vous recevrez un e-mail dès sa validation (sous 24 h ouvrées).');

        // 24 h passées : un nouvel e-mail, toujours aucune notification
        $this->clearPublicFormLimits();
        self::mockTime('2026-10-28 10:00:01');
        $this->resendRequest($client);
        self::assertEmailCount(1);
        self::assertCount(1, MockHttpResponseFactory::requestsTo('discord'));
    }

    /**
     * Renvoie la demande de camille@acme.test (toujours en attente) ; la redirection n'est pas suivie
     * (les e-mails comptés sont ceux de l'envoi).
     */
    private function resendRequest(KernelBrowser $client): void
    {
        $crawler = $client->request('GET', '/espace-recruteur');
        $this->submitPublicForm($client, $crawler, 'Envoyer ma demande', [
            'recruiter_request[email]' => 'camille@acme.test', 'recruiter_request[fullName]' => 'Camille Martin',
            'recruiter_request[company]' => 'Acme', 'recruiter_request[message]' => '', 'recruiter_request[consent]' => '1',
        ]);
        self::assertResponseRedirects('/espace-recruteur');
    }

    /**
     * Vérifie le refus Turnstile de chaque formulaire (clés de test « toujours refuser » et « jeton déjà
     * utilisé ») : page renvoyée (422), message clair en français, saisie conservée, rien d'enregistré ni d'envoyé.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideTurnstileRefusals')]
    public function testTurnstileRefusalKeepsTheInput(string $form, string $token, string $message): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/espace-recruteur');
        $values = 'request' === $form
            ? ['recruiter_request[email]' => 'refus@acme.test', 'recruiter_request[fullName]' => 'Camille Refus', 'recruiter_request[company]' => 'Acme', 'recruiter_request[message]' => 'Message conservé', 'recruiter_request[consent]' => '1']
            : ['login_link[email]' => 'refus@acme.test'];
        $this->createRecruiter('refus@acme.test');

        $crawler = $this->submitPublicForm($client, $crawler, 'request' === $form ? 'Envoyer ma demande' : 'Recevoir un lien de connexion', $values, $token);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', $message);
        $name = 'request' === $form ? 'recruiter_request[email]' : 'login_link[email]';
        self::assertSame('refus@acme.test', $crawler->filter('input[name="'.$name.'"]')->attr('value'));
        if ('request' === $form) {
            self::assertSame('Message conservé', $crawler->filter('textarea[name="recruiter_request[message]"]')->text());
            self::assertSame('Camille Martin', static::getContainer()->get(RecruiterRepository::class)->findOneByEmail('refus@acme.test')?->getFullName(), 'Fiche inchangée.');
        }
        self::assertEmailCount(0);
    }

    /**
     * Cas de refus : formulaire × jeton refusé (réponses réelles des clés secrètes de test de Cloudflare).
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideTurnstileRefusals(): iterable
    {
        $failed = 'La vérification anti-robot a échoué. Merci de réessayer.';
        $expired = 'La vérification anti-robot a expiré ou a déjà servi.';
        yield 'demande, toujours refuser' => ['request', MockHttpResponseFactory::INVALID_TOKEN, $failed];
        yield 'demande, jeton déjà utilisé' => ['request', MockHttpResponseFactory::DUPLICATE_TOKEN, $expired];
        yield 'lien, toujours refuser' => ['link', MockHttpResponseFactory::INVALID_TOKEN, $failed];
        yield 'lien, jeton déjà utilisé' => ['link', MockHttpResponseFactory::DUPLICATE_TOKEN, $expired];
        yield 'lien, sans jeton' => ['link', '', $failed];
    }

    /**
     * Vérifie que la demande de lien donne la même réponse pour une adresse inconnue, en attente ou
     * validée, et n'envoie un e-mail qu'à un accès ouvert.
     */
    public function testLoginLinkRequestNeverRevealsWhetherAnAddressExists(): void
    {
        $client = self::createClient();
        $this->createRecruiter('valide@acme.test');
        $this->createRecruiter('attente@acme.test', RecruiterStatus::Pending, null);

        $pages = [];
        foreach (['inconnu@acme.test', 'attente@acme.test', 'valide@acme.test'] as $email) {
            $crawler = $client->request('GET', '/espace-recruteur');
            $this->submitPublicForm($client, $crawler, 'Recevoir un lien de connexion', ['login_link[email]' => $email]);
            self::assertResponseRedirects('/espace-recruteur');
            $sent = \count(self::getMailerMessages());
            $client->followRedirect();
            $pages[$email] = [$sent, $client->getCrawler()->filter('[role="status"]')->text()];
        }
        self::assertSame([0, 0, 1], array_column($pages, 0));
        self::assertSame(array_fill(0, 3, $pages['inconnu@acme.test'][1]), array_column($pages, 1));
        self::assertStringContainsString(self::LINK_RESPONSE, $pages['inconnu@acme.test'][1]);
    }

    /**
     * Vérifie le parcours de connexion : lien de l'e-mail, page de confirmation (GET), connexion (POST),
     * espace privé avec les coordonnées et le vrai nom d'une entreprise anonymisée ; le lien ne sert qu'une fois.
     */
    public function testLoginLinkWorksOnceAndOpensThePrivateArea(): void
    {
        $client = self::createClient();
        $this->createPrivateProfile();
        $recruiter = $this->createRecruiter('valide@acme.test');
        static::getContainer()->get(RecruiterAccessManager::class)->sendLoginLink('valide@acme.test');
        $link = $this->extractRecruiterLink($this->lastEmailTo('valide@acme.test'));
        self::assertEmailTextBodyContains($this->lastEmailTo('valide@acme.test'), '30 minutes');

        $this->logIn($client, $link);
        self::assertResponseRedirects('/espace-recruteur/accueil');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', self::PRIVATE_PHONE);
        self::assertSelectorTextContains('main', self::PRIVATE_EMAIL);
        // Prétentions masquées tant que la case n'est pas cochée
        self::assertSelectorTextNotContains('main', 'XX à YY k€');
        self::assertSelectorExists('meta[name="robots"][content="noindex, nofollow"]');

        // Première connexion : activité notifiée (« Activité d'un recruteur », jamais un signal d'intérêt, v2.0.3)
        $events = static::getContainer()->get(EntityManagerInterface::class)->getRepository(RecruiterEvent::class)->findBy(['recruiter' => $recruiter->getId()]);
        self::assertCount(1, $events);
        self::assertSame(RecruiterEventType::FirstLogin, $events[0]->getType());
        self::assertNotNull($events[0]->getNotifiedAt());

        // Le même lien ne sert pas deux fois
        $client->request('GET', '/espace-recruteur/deconnexion');
        $this->logIn($client, $link);
        self::assertResponseRedirects('/espace-recruteur/lien-invalide');
        // Lien recruteur refusé : ce n'est pas un échec de connexion à l'admin (journal de sécurité vide)
        self::assertSame(0, static::getContainer()->get(EntityManagerInterface::class)->getRepository(\App\Entity\SecurityEvent::class)->count([]));
    }

    /**
     * Vérifie qu'un lien de connexion expire après 30 minutes (horloge simulée de la vérification des liens) :
     * encore valable à 29 minutes, refusé à 30 minutes et 1 seconde.
     */
    public function testLoginLinkExpiresAfterThirtyMinutes(): void
    {
        $client = self::createClient();
        $this->createRecruiter('horloge@acme.test');
        $manager = static::getContainer()->get(RecruiterAccessManager::class);

        $manager->sendLoginLink('horloge@acme.test');
        $valid = $this->extractRecruiterLink($this->lastEmailTo('horloge@acme.test'));
        SignatureClock::advance(29 * 60);
        $this->logIn($client, $valid);
        self::assertResponseRedirects('/espace-recruteur/accueil');
        $client->request('GET', '/espace-recruteur/deconnexion');

        SignatureClock::reset();
        // Le navigateur de test redémarre le noyau à chaque requête : service et e-mails du conteneur courant
        static::getContainer()->get(RecruiterAccessManager::class)->sendLoginLink('horloge@acme.test');
        $expired = $this->extractRecruiterLink($this->lastEmailTo('horloge@acme.test'));
        SignatureClock::advance(30 * 60 + 1);
        $crawler = $client->request('GET', $expired);
        $client->submit($crawler->selectButton('Me connecter')->form());
        self::assertResponseRedirects('/espace-recruteur/lien-invalide');
        $client->request('GET', '/espace-recruteur/accueil');
        self::assertResponseRedirects();
        self::assertStringNotContainsString('/accueil', (string) $client->getResponse()->headers->get('Location'));
    }

    /**
     * Vérifie la suppression de ses données par le recruteur : page de confirmation, suppression (fiche,
     * évènements), déconnexion, message, notification sans donnée personnelle ; le lien déjà reçu ne sert plus.
     */
    public function testRecruiterCanDeleteOwnData(): void
    {
        $client = self::createClient();
        $recruiter = $this->createRecruiter('efface@acme.test');
        $id = $recruiter->getId();
        $manager = static::getContainer()->get(RecruiterAccessManager::class);
        $manager->sendLoginLink('efface@acme.test');
        $this->logIn($client, $this->extractRecruiterLink($this->lastEmailTo('efface@acme.test')));
        static::getContainer()->get(RecruiterAccessManager::class)->sendLoginLink('efface@acme.test');
        $spareLink = $this->extractRecruiterLink($this->lastEmailTo('efface@acme.test'));

        $crawler = $client->request('GET', '/espace-recruteur/accueil');
        $client->click($crawler->selectLink('Mes données et leur suppression')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'efface@acme.test');
        $client->submit($client->getCrawler()->selectButton('Supprimer définitivement mes données')->form());
        // Sans JavaScript : page de confirmation d'abord (rien n'est encore supprimé)
        self::assertNotNull(static::getContainer()->get(EntityManagerInterface::class)->find(Recruiter::class, $id));
        $this->confirmAction($client, 'Supprimer définitivement vos données ?');
        self::assertResponseRedirects('/espace-recruteur');
        $notification = $this->lastEmailTo('notifications@example.test');
        self::assertEmailTextBodyContains($notification, 'Un recruteur a supprimé ses données');
        self::assertEmailTextBodyNotContains($notification, 'efface@acme.test');
        self::assertEmailTextBodyNotContains($notification, 'Camille');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Vos données ont été supprimées');

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        self::assertNull($entityManager->find(Recruiter::class, $id));
        self::assertSame([], $entityManager->getRepository(RecruiterEvent::class)->findBy(['recruiter' => $id]));

        $client->request('GET', '/espace-recruteur/accueil');
        self::assertResponseRedirects();
        $crawler = $client->request('GET', $spareLink);
        $client->submit($crawler->selectButton('Me connecter')->form());
        self::assertResponseRedirects('/espace-recruteur/lien-invalide');
    }

    /**
     * Vérifie la fenêtre de bienvenue : à la première connexion seulement (contenu de l'espace, date de fin
     * de l'accès, lien « Mes données »), prête pour le contrôleur « notify », absente ensuite.
     */
    public function testWelcomeIsShownOnlyOnFirstLogin(): void
    {
        $client = self::createClient();
        $this->createRecruiter('bienvenue@acme.test');
        static::getContainer()->get(RecruiterAccessManager::class)->sendLoginLink('bienvenue@acme.test');
        $this->logIn($client, $this->extractRecruiterLink($this->lastEmailTo('bienvenue@acme.test')));
        $crawler = $client->followRedirect();

        $welcome = $crawler->filter('[data-testid="recruiter-welcome"]');
        self::assertCount(1, $welcome);
        self::assertSame('notify', $welcome->attr('data-controller'));
        self::assertSame('true', $welcome->attr('data-notify-dialog-value'));
        self::assertStringContainsString('Votre accès est ouvert jusqu\'au', $welcome->text());
        self::assertStringContainsString('le CV complet', $welcome->text());
        self::assertSame('/espace-recruteur/mes-donnees', $welcome->filter('a')->attr('href'));

        $client->request('GET', '/espace-recruteur/accueil');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="recruiter-welcome"]');

        // Nouvelle connexion (lien neuf) : plus de fenêtre
        static::getContainer()->get(RecruiterAccessManager::class)->sendLoginLink('bienvenue@acme.test');
        $this->logIn($client, $this->extractRecruiterLink($this->lastEmailTo('bienvenue@acme.test')));
        $client->followRedirect();
        self::assertSelectorNotExists('[data-testid="recruiter-welcome"]');
    }

    /**
     * Vérifie que la suppression exige un jeton CSRF valide (rien n'est supprimé sinon).
     */
    public function testDataDeletionRequiresCsrfToken(): void
    {
        $client = self::createClient();
        $recruiter = $this->createRecruiter('csrf@acme.test');
        static::getContainer()->get(RecruiterAccessManager::class)->sendLoginLink('csrf@acme.test');
        $this->logIn($client, $this->extractRecruiterLink($this->lastEmailTo('csrf@acme.test')));

        // Jeton refusé : exception d'authentification, renvoi vers la page publique de l'espace
        $client->request('POST', '/espace-recruteur/mes-donnees/supprimer', ['_token' => 'faux']);
        self::assertResponseRedirects('/espace-recruteur');
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertNotNull(static::getContainer()->get(EntityManagerInterface::class)->find(Recruiter::class, $recruiter->getId()));
    }

    /**
     * Vérifie l'échéance de l'accès (90 jours, horloge simulée) puis son renouvellement : connexion possible
     * au 89e jour ; au 91e, plus de lien envoyé et un lien encore valable est refusé ; après « Renouveler »,
     * e-mail « prolongé » avec un lien qui ouvre de nouveau l'espace.
     */
    public function testAccessExpiresAfterNinetyDaysAndCanBeRenewed(): void
    {
        $client = self::createClient();
        self::mockTime('2026-11-02 10:00:00');
        $recruiter = $this->createRecruiter('quatrevingtdix@acme.test', RecruiterStatus::Pending, null);
        static::getContainer()->get(RecruiterAccessManager::class)->approve($recruiter);
        self::assertSame('2027-01-31', $recruiter->getAccessExpiresAt()?->format('Y-m-d'), 'Accès de 90 jours.');

        self::mockTime('2027-01-30 10:00:00');
        self::assertTrue(static::getContainer()->get(RecruiterAccessManager::class)->sendLoginLink('quatrevingtdix@acme.test'));
        $link = $this->extractRecruiterLink($this->lastEmailTo('quatrevingtdix@acme.test'));
        $this->logIn($client, $link);
        self::assertResponseRedirects('/espace-recruteur/accueil');
        $client->request('GET', '/espace-recruteur/deconnexion');

        // 91e jour : accès échu
        self::mockTime('2027-02-01 10:00:00');
        self::assertFalse(static::getContainer()->get(RecruiterAccessManager::class)->sendLoginLink('quatrevingtdix@acme.test'), 'Aucun lien envoyé après l\'échéance.');
        self::assertEmailCount(0);
        $fresh = static::getContainer()->get('security.authenticator.login_link_handler.recruiter')->createLoginLink(static::getContainer()->get(RecruiterRepository::class)->findOneByEmail('quatrevingtdix@acme.test'))->getUrl();
        $this->logIn($client, $fresh);
        self::assertResponseRedirects('/espace-recruteur/lien-invalide');

        // Renouvellement par l'admin : 90 jours à partir d'aujourd'hui, e-mail « prolongé » avec un lien
        $expired = static::getContainer()->get(RecruiterRepository::class)->findOneByEmail('quatrevingtdix@acme.test');
        self::assertInstanceOf(Recruiter::class, $expired);
        static::getContainer()->get(RecruiterAccessManager::class)->renew($expired);
        self::assertSame('2027-05-02', $expired->getAccessExpiresAt()?->format('Y-m-d'));
        $renewal = $this->lastEmailTo('quatrevingtdix@acme.test');
        self::assertSame('Votre accès à l\'espace recruteur est prolongé', $renewal->getSubject());
        self::assertSame('noreply@example.test', $renewal->getFrom()[0]->getAddress());
        $this->logIn($client, $this->extractRecruiterLink($renewal));
        self::assertResponseRedirects('/espace-recruteur/accueil');
    }

    /**
     * Vérifie qu'une révocation rend caducs les liens déjà envoyés et ferme les pages privées.
     */
    public function testRevokedAccessCannotLogInWithAnExistingLink(): void
    {
        $client = self::createClient();
        $recruiter = $this->createRecruiter('valide@acme.test');
        $accessManager = static::getContainer()->get(RecruiterAccessManager::class);
        $accessManager->sendLoginLink('valide@acme.test');
        $link = $this->extractRecruiterLink($this->lastEmailTo('valide@acme.test'));
        $accessManager->revoke(static::getContainer()->get(RecruiterRepository::class)->find($recruiter->getId()) ?? $recruiter);

        $this->logIn($client, $link);
        self::assertResponseRedirects('/espace-recruteur/lien-invalide');
        $client->request('GET', '/espace-recruteur/accueil');
        self::assertResponseRedirects('/espace-recruteur');
    }

    /**
     * Vérifie qu'une session ouverte se ferme dès que l'accès est révoqué dans l'admin.
     */
    public function testOpenSessionEndsWhenAccessIsRevoked(): void
    {
        $client = self::createClient();
        $recruiter = $this->createRecruiter('valide@acme.test');
        $accessManager = static::getContainer()->get(RecruiterAccessManager::class);
        $accessManager->sendLoginLink('valide@acme.test');
        $this->logIn($client, $this->extractRecruiterLink($this->lastEmailTo('valide@acme.test')));
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        $stored = static::getContainer()->get(RecruiterRepository::class)->find($recruiter->getId());
        self::assertInstanceOf(Recruiter::class, $stored);
        // Service du conteneur courant (le client a redémarré le noyau depuis l'envoi du lien)
        static::getContainer()->get(RecruiterAccessManager::class)->revoke($stored);
        $client->request('GET', '/espace-recruteur/accueil');
        self::assertResponseRedirects('/espace-recruteur');
        // Session fermée : même avec un accès rouvert, il faut un nouveau lien
        $stored->setStatus(RecruiterStatus::Approved);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $client->request('GET', '/espace-recruteur/accueil');
        self::assertResponseRedirects('/espace-recruteur');
    }

    /**
     * Vérifie le CV complet (PDF), « Je suis intéressé » et la séparation (v2.0.3) : l'activité (première connexion,
     * CV téléchargé) est regroupée, une notification par jour ; le signal d'intérêt part tout de suite, à part.
     */
    public function testInterestSignalsAreGroupedOncePerDay(): void
    {
        $client = self::createClient();
        $this->createPrivateProfile(true);
        $recruiter = $this->createRecruiter('valide@acme.test');
        static::getContainer()->get(RecruiterAccessManager::class)->sendLoginLink('valide@acme.test');
        $this->logIn($client, $this->extractRecruiterLink($this->lastEmailTo('valide@acme.test')));
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('main', 'XX à YY k€');
        MockHttpResponseFactory::reset();

        // CV complet (coordonnées, prétentions) : jamais dans le cache des PDF publics (PublicCvPdfCache)
        static::getContainer()->get(PublicCvPdfCache::class)->clear();
        $client->request('GET', '/espace-recruteur/cv/ats');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertSame(0, static::getContainer()->get(PublicCvPdfCache::class)->count(), 'Le PDF privé n\'est jamais mis en cache.');
        $client->submit($crawler->selectButton('Je suis intéressé')->form());
        self::assertResponseRedirects('/espace-recruteur/accueil');
        // Texte exact après le clic (v2.0.3, point 6) : réponse par e-mail sous 24 h ouvrées, aucune autre promesse
        $client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', 'Merci, Guillaume a été prévenu et vous répondra par e-mail sous 24 h ouvrées.');
        self::assertStringNotContainsString('recontacte', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('je vous réponds par e-mail sous 24 h ouvrées', (string) $client->getResponse()->getContent());

        // Activité déjà notifiée aujourd'hui (première connexion) : le CV attend le récapitulatif ; le signal
        // d'intérêt explicite part tout de suite, seul
        $discord = implode("\n", array_map(static fn (array $r): string => (string) json_decode($r['body'], true)['content'], MockHttpResponseFactory::requestsTo('discord')));
        self::assertCount(1, MockHttpResponseFactory::requestsTo('discord'));
        self::assertStringContainsString('Signal d\'intérêt d\'un recruteur', $discord);
        self::assertStringNotContainsString('CV complet', $discord);
        $events = static::getContainer()->get(EntityManagerInterface::class)->getRepository(RecruiterEvent::class)->findBy(['recruiter' => $recruiter->getId()], ['id' => 'ASC']);
        self::assertSame([RecruiterEventType::FirstLogin, RecruiterEventType::CvDownload, RecruiterEventType::Interested], array_map(static fn (RecruiterEvent $e): RecruiterEventType => $e->getType(), $events));
        self::assertNull($events[1]->getNotifiedAt());
        self::assertNotNull($events[2]->getNotifiedAt());
    }

    /**
     * Vérifie la demande de prolongation : lien signé, confirmation en POST, notification ; lien modifié refusé.
     */
    public function testExtensionRequestFromSignedLink(): void
    {
        $client = self::createClient();
        $recruiter = $this->createRecruiter('valide@acme.test', RecruiterStatus::Approved, '+5 days');
        $url = static::getContainer()->get(UrlGeneratorInterface::class)->generate('recruiter_extension', ['id' => $recruiter->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
        $signed = static::getContainer()->get(UriSigner::class)->sign($url, new \DateTimeImmutable('+14 days'));

        $client->request('GET', str_replace('_hash=', '_hash=x', $signed));
        self::assertResponseStatusCodeSame(404);

        $crawler = $client->request('GET', $signed);
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Demander la prolongation')->form());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="status"]', 'demande de prolongation est bien enregistrée');
        $stored = static::getContainer()->get(RecruiterRepository::class)->find($recruiter->getId());
        self::assertInstanceOf(Recruiter::class, $stored);
        self::assertNotNull($stored->getExtensionRequestedAt());
        self::assertCount(1, MockHttpResponseFactory::requestsTo('discord'));
    }

    /**
     * Vérifie qu'un visiteur non connecté est renvoyé vers la page publique de l'espace recruteur.
     */
    public function testPrivatePagesRequireALogin(): void
    {
        $client = self::createClient();
        foreach (['/espace-recruteur/accueil', '/espace-recruteur/cv/ats'] as $url) {
            $client->request('GET', $url);
            self::assertResponseRedirects('/espace-recruteur');
        }
    }

    /**
     * Ouvre un lien de connexion (page de confirmation), puis envoie le formulaire de connexion (POST).
     */
    private function logIn(KernelBrowser $client, string $link): void
    {
        $crawler = $client->request('GET', $link);
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Me connecter')->form());
    }

    /**
     * Dernier e-mail envoyé à une adresse.
     */
    private function lastEmailTo(string $address): Email
    {
        $emails = array_values(array_filter(self::getMailerMessages(), static fn ($m): bool => $m instanceof Email && str_contains((string) $m->getTo()[0]->getAddress(), $address)));
        self::assertNotEmpty($emails, 'Aucun e-mail pour '.$address);
        $last = end($emails);
        self::assertInstanceOf(Email::class, $last);

        return $last;
    }
}
