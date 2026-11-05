<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\RecruiterEvent;
use App\Enum\RecruiterEventType;
use App\Service\Recruiter\RecruiterAccessManager;
use App\Tests\Support\MockHttpResponseFactory;
use App\Tests\Support\RecruiterTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mime\Email;

/**
 * Signal d'intérêt jamais envoyé tout seul (v2.0.3, point 7) : seul le clic sur « Je suis intéressé » (POST avec
 * jeton CSRF) envoie un « Signal d'intérêt d'un recruteur », une fois par période ; la première connexion et les
 * téléchargements sont de l'« Activité d'un recruteur » ; ni l'ouverture du lien magique par un robot de
 * messagerie, ni l'affichage, ni le survol, ni le préchargement, ni un GET n'envoient quoi que ce soit.
 *
 * Liens :
 * - Couvre : RecruiterAreaController::interest() et cvPdf(), Service\Recruiter\InterestSignalNotifier,
 *   EventSubscriber\RecruiterLoginSubscriber, templates/recruiter_area/home.html.twig
 */
final class RecruiterInterestSignalTest extends WebTestCase
{
    use RecruiterTestTrait;

    /** Adresse du recruteur fictif. */
    private const EMAIL = 'valide@acme.test';

    /** Titres des deux notifications. */
    private const SIGNAL = 'Signal d\'intérêt d\'un recruteur';
    private const ACTIVITY = 'Activité d\'un recruteur';

    /**
     * Remet les réponses simulées à zéro et crée l'admin qui reçoit les notifications.
     */
    protected function setUp(): void
    {
        MockHttpResponseFactory::reset();
        self::bootKernel();
        $this->createNotifiedAdmin();
        self::ensureKernelShutdown();
    }

    /**
     * Lien magique ouvert par un robot de messagerie (GET seul : page de confirmation) : aucune connexion, aucun
     * événement, aucune notification.
     */
    public function testMagicLinkOpenedByMailScannerSendsNothing(): void
    {
        $client = self::createClient();
        $this->createRecruiter(self::EMAIL);
        $link = $this->sendLink();

        $client->request('GET', $link);
        self::assertResponseIsSuccessful();
        $client->request('HEAD', $link);

        self::assertSame([], $this->eventTypes());
        self::assertSame([], $this->discordTitles());
    }

    /**
     * Connexion par lien magique (première connexion) : « Activité d'un recruteur » seulement, jamais
     * « Signal d'intérêt ».
     */
    public function testFirstLoginIsOnlyRecruiterActivity(): void
    {
        $client = self::createClient();
        $this->createRecruiter(self::EMAIL);
        $this->logIn($client);

        self::assertSame([RecruiterEventType::FirstLogin], $this->eventTypes());
        self::assertSame([self::ACTIVITY], $this->discordTitles());
        $notification = $this->notificationEmail();
        self::assertEmailTextBodyContains($notification, self::ACTIVITY);
        self::assertEmailTextBodyNotContains($notification, 'Signal d\'intérêt');
    }

    /**
     * Affichage, survol et préchargement (en-têtes Sec-Purpose, Purpose) : aucun signal ; le bouton est un
     * formulaire POST avec jeton, et le formulaire comme les liens à effet refusent le préchargement de Turbo.
     */
    public function testDisplayHoverAndPrefetchSendNoSignal(): void
    {
        $client = self::createClient();
        $this->createPrivateProfile();
        $this->createRecruiter(self::EMAIL);
        $this->logIn($client);
        MockHttpResponseFactory::reset();

        $crawler = $client->request('GET', '/espace-recruteur/accueil');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/espace-recruteur/accueil', [], [], ['HTTP_SEC_PURPOSE' => 'prefetch']);
        $client->request('GET', '/espace-recruteur/accueil', [], [], ['HTTP_PURPOSE' => 'prefetch']);
        $client->request('GET', '/espace-recruteur/cv/ats', [], [], ['HTTP_SEC_PURPOSE' => 'prefetch']);
        $client->request('GET', '/espace-recruteur/cv/design', [], [], ['HTTP_X_SEC_PURPOSE' => 'prefetch']);

        self::assertSame([RecruiterEventType::FirstLogin], $this->eventTypes());
        self::assertSame([], $this->discordTitles());

        $form = $this->interestForm($crawler);
        self::assertSame('post', mb_strtolower((string) $form->attr('method')));
        self::assertSame('false', $form->attr('data-turbo-prefetch'));
        self::assertCount(1, $form->filter('input[name="_token"]'));
        self::assertCount(0, $crawler->filter('a[href$="/espace-recruteur/interet"]'), 'Jamais de lien vers le signal.');
        $links = $crawler->filter('a[href*="/espace-recruteur/cv/"], a[href$="/espace-recruteur/deconnexion"]');
        self::assertCount(3, $links, 'Deux liens du CV complet et la déconnexion.');
        $links->each(static fn (Crawler $link) => self::assertSame('false', $link->attr('data-turbo-prefetch'), (string) $link->attr('href')));
    }

    /**
     * GET sur l'adresse du signal : refusé (405), rien d'enregistré.
     */
    public function testGetOnSignalAddressIsRefused(): void
    {
        $client = self::createClient();
        $this->createRecruiter(self::EMAIL);
        $this->logIn($client);
        MockHttpResponseFactory::reset();

        $client->request('GET', '/espace-recruteur/interet');
        self::assertResponseStatusCodeSame(405);

        self::assertNotContains(RecruiterEventType::Interested, $this->eventTypes());
        self::assertSame([], $this->discordTitles());
    }

    /**
     * POST sans jeton CSRF, ou avec un jeton faux : refusé (renvoi vers la page publique), rien d'enregistré.
     */
    public function testPostWithoutValidCsrfIsRefused(): void
    {
        $client = self::createClient();
        $this->createRecruiter(self::EMAIL);
        $this->logIn($client);
        MockHttpResponseFactory::reset();

        foreach ([[], ['_token' => 'faux-jeton']] as $parameters) {
            // Jeton refusé : exception d'authentification, renvoi vers la page publique de l'espace (comme ailleurs)
            $client->request('POST', '/espace-recruteur/interet', $parameters);
            self::assertResponseRedirects('/espace-recruteur');
        }

        self::assertNotContains(RecruiterEventType::Interested, $this->eventTypes());
        self::assertSame([], $this->discordTitles());
    }

    /**
     * POST valide : exactement un signal et une notification « Signal d'intérêt » (adresse complète dans l'e-mail) ;
     * second clic : aucun doublon, message clair avec la date du signal déjà transmis.
     */
    public function testValidPostSendsExactlyOneSignalAndNoDuplicate(): void
    {
        $client = self::createClient();
        $this->createPrivateProfile();
        $this->createRecruiter(self::EMAIL);
        $this->logIn($client);
        $crawler = $client->followRedirect();
        MockHttpResponseFactory::reset();

        $client->submit($this->interestForm($crawler)->form());
        self::assertResponseRedirects('/espace-recruteur/accueil');
        self::assertSame([self::SIGNAL], $this->discordTitles());
        $notification = $this->notificationEmail();
        self::assertEmailTextBodyContains($notification, self::SIGNAL);
        self::assertEmailTextBodyContains($notification, self::EMAIL);
        self::assertEmailTextBodyNotContains($notification, 'Première connexion');
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', 'Merci, Guillaume a été prévenu et vous répondra par e-mail sous 24 h ouvrées.');

        // Second clic : ni événement, ni notification
        $client->submit($this->interestForm($crawler)->form());
        self::assertResponseRedirects('/espace-recruteur/accueil');
        $client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', 'Votre intérêt a déjà été transmis le');
        self::assertSame([RecruiterEventType::FirstLogin, RecruiterEventType::Interested], $this->eventTypes());
        self::assertSame([self::SIGNAL], $this->discordTitles());
    }

    /**
     * Envoie un lien de connexion au recruteur et le renvoie.
     */
    private function sendLink(): string
    {
        static::getContainer()->get(RecruiterAccessManager::class)->sendLoginLink(self::EMAIL);
        $emails = array_values(array_filter(self::getMailerMessages(), static fn ($m): bool => $m instanceof Email && self::EMAIL === $m->getTo()[0]->getAddress()));
        $email = end($emails);
        self::assertInstanceOf(Email::class, $email);

        return $this->extractRecruiterLink($email);
    }

    /**
     * Se connecte par le lien magique : page de confirmation (GET), puis formulaire « Me connecter » (POST).
     */
    private function logIn(KernelBrowser $client): void
    {
        $crawler = $client->request('GET', $this->sendLink());
        $client->submit($crawler->selectButton('Me connecter')->form());
        self::assertResponseRedirects('/espace-recruteur/accueil');
    }

    /**
     * Formulaire du bouton « Je suis intéressé ».
     */
    private function interestForm(Crawler $crawler): Crawler
    {
        $form = $crawler->filter('form[action$="/espace-recruteur/interet"]');
        self::assertCount(1, $form);

        return $form;
    }

    /**
     * Types des événements enregistrés pour le recruteur, dans l'ordre.
     *
     * @return list<RecruiterEventType>
     */
    private function eventTypes(): array
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return array_map(static fn (RecruiterEvent $e): RecruiterEventType => $e->getType(), $entityManager->getRepository(RecruiterEvent::class)->findBy([], ['id' => 'ASC']));
    }

    /**
     * Titres des notifications envoyées à Discord depuis la dernière remise à zéro.
     *
     * @return list<string>
     */
    private function discordTitles(): array
    {
        $titles = [];
        foreach (MockHttpResponseFactory::requestsTo('discord') as $request) {
            $content = (string) (json_decode($request['body'], true)['content'] ?? '');
            foreach ([self::SIGNAL, self::ACTIVITY] as $title) {
                if (str_contains($content, $title)) {
                    $titles[] = $title;
                }
            }
        }

        return $titles;
    }

    /**
     * Dernier e-mail de notification envoyé à Guillaume pendant la dernière requête.
     */
    private function notificationEmail(): Email
    {
        $emails = array_values(array_filter(self::getMailerMessages(), static fn ($m): bool => $m instanceof Email && 'notifications@example.test' === $m->getTo()[0]->getAddress()));
        $email = end($emails);
        self::assertInstanceOf(Email::class, $email, 'Aucun e-mail de notification.');

        return $email;
    }
}
