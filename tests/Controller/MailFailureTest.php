<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\FailedEmail;
use App\Enum\RecruiterStatus;
use App\Repository\FailedEmailRepository;
use App\Repository\HeartbeatRepository;
use App\Repository\RecruiterRepository;
use App\Service\Mail\ResilientMailer;
use App\Tests\Support\AdminTestTrait;
use App\Tests\Support\ConfirmationTrait;
use App\Tests\Support\MemoryLogger;
use App\Tests\Support\MockHttpResponseFactory;
use App\Tests\Support\RecruiterTestTrait;
use App\Tests\Support\SwitchableMailer;
use App\Tests\Support\VisibleTextAssertionsTrait;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\BodyRendererInterface;

/**
 * Serveur SMTP injoignable (comme Mailpit arrêté) : la demande d'accès est quand même enregistrée, le
 * recruteur voit le message normal (pas d'erreur 500), l'erreur est journalisée sans donnée personnelle, les
 * e-mails sont gardés ; l'admin voit « X e-mails non envoyés » et les renvoie (lien de connexion recréé) ;
 * l'e-mail de test affiche l'erreur du serveur.
 *
 * Liens :
 * - Couvre : ResilientMailer, FailedEmailResender, Admin\MailController, Admin\DashboardController (alerte),
 *   RecruiterMailer, NotificationDispatcher
 */
final class MailFailureTest extends WebTestCase
{
    use ConfirmationTrait;
    use AdminTestTrait;
    use RecruiterTestTrait;
    use VisibleTextAssertionsTrait;

    /** Journal des échecs (remplace celui du service pendant le test). */
    private MemoryLogger $logger;

    protected function setUp(): void
    {
        MockHttpResponseFactory::reset();
        self::bootKernel();
        $this->clearPublicFormLimits();
        $this->createNotifiedAdmin();
        self::ensureKernelShutdown();
    }

    /**
     * Vérifie la demande d'accès pendant la panne, puis l'alerte et le renvoi depuis l'admin.
     */
    public function testRequestSurvivesSmtpOutageAndAdminResends(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $this->useMailer(false);

        $crawler = $client->request('GET', '/espace-recruteur');
        $this->submitPublicForm($client, $crawler, 'Envoyer ma demande', [
            'recruiter_request[email]' => 'panne@acme.test', 'recruiter_request[fullName]' => 'Camille Panne',
            'recruiter_request[company]' => 'Acme', 'recruiter_request[message]' => 'Poste de responsable.', 'recruiter_request[consent]' => '1',
        ]);
        self::assertResponseRedirects('/espace-recruteur');
        $client->followRedirect();
        self::assertSelectorTextContains('main', 'Demande reçue. Vous recevrez un e-mail dès sa validation (sous 24 h ouvrées).');

        // Demande enregistrée ; accusé et notification gardés ; journal sans donnée personnelle
        self::assertSame(RecruiterStatus::Pending, static::getContainer()->get(RecruiterRepository::class)->findOneByEmail('panne@acme.test')?->getStatus());
        $failed = static::getContainer()->get(FailedEmailRepository::class)->findAllOldestFirst();
        self::assertSame([ResilientMailer::KIND_REQUEST_RECEIVED, ResilientMailer::KIND_NOTIFICATION], array_map(static fn (FailedEmail $e): string => $e->getKind(), $failed));
        self::assertStringContainsString('Connection could not be established', $failed[0]->getLastError());
        $journal = implode("\n", $this->logger->messages);
        self::assertStringContainsString('E-mail « recruiter_request_received » non envoyé', $journal);
        foreach (['panne@acme.test', 'Camille Panne', 'Acme', 'notifications@example.test'] as $personal) {
            self::assertStringNotContainsString($personal, $journal);
        }

        // Admin : alerte, e-mail de test en échec (erreur affichée dans la page), puis renvoi une fois le serveur revenu
        $this->loginFullyAsAdmin($client);
        $client->request('GET', $this->adminUrl());
        self::assertSelectorTextContains('[data-testid="failed-emails-alert"]', '2 e-mails n\'ont pas pu être envoyés');
        $crawler = $client->request('GET', $this->adminUrl('/communication/e-mails'));
        $client->submit($crawler->selectButton('Tester « Général du site »')->form());
        $client->followRedirect();
        self::assertSelectorTextContains('main', 'Échec de l\'e-mail de test « Général du site » : Connection could not be established');

        $this->useMailer(true);
        $crawler = $client->request('GET', $this->adminUrl());
        $client->submit($crawler->selectButton('Renvoyer')->form());
        $this->confirmAction($client, 'Renvoyer les 2 e-mails non envoyés ?');
        $client->followRedirect();
        self::assertSelectorTextContains('main', 'Renvoi : 2 parti(s), 0 encore en échec');
        self::assertSelectorNotExists('[data-testid="failed-emails-alert"]');
        self::assertSame(0, static::getContainer()->get(FailedEmailRepository::class)->count([]));
        self::assertSame(['panne@acme.test', 'notifications@example.test'], array_map(static fn ($m): string => $m->getTo()[0]->getAddress(), $this->smtp->sent));
    }

    /**
     * Vérifie le renvoi d'un e-mail à lien de connexion : un lien NEUF est créé (l'ancien a pu expirer).
     */
    public function testResentApprovalHasAFreshLink(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $this->useMailer(false);
        $recruiter = $this->createRecruiter('lien@acme.test', RecruiterStatus::Pending, null);
        static::getContainer()->get(\App\Service\Recruiter\RecruiterAccessManager::class)->approve($recruiter);
        $failed = static::getContainer()->get(FailedEmailRepository::class)->findAllOldestFirst();
        self::assertSame(ResilientMailer::KIND_APPROVAL, $failed[0]->getKind());
        preg_match('#expires=(\d+)#', $failed[0]->getPayload(), $oldExpires);

        // Le lien expire à la seconde : on laisse passer une seconde pour distinguer l'ancien du nouveau
        sleep(1);
        $this->useMailer(true);
        $result = static::getContainer()->get(\App\Service\Mail\FailedEmailResender::class)->resendAll();

        self::assertSame(['sent' => 1, 'failed' => 0, 'dropped' => 0], $result);
        $text = (string) $this->smtp->sent[0]->getTextBody();
        self::assertMatchesRegularExpression('#https?://\S+/espace-recruteur/connexion/verification\?\S+#', $text);
        preg_match('#expires=(\d+)#', $text, $newExpires);
        self::assertGreaterThan((int) $oldExpires[1], (int) $newExpires[1], 'Lien recréé au renvoi (nouvelle échéance de 30 minutes).');
    }

    /**
     * Vérifie l'e-mail de test réussi : sans adresse enregistrée, envoyé à l'adresse du compte admin (repli), depuis noreply@.
     */
    public function testTestEmailGoesToTheAdminAddress(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $this->useMailer(true);
        $this->loginFullyAsAdmin($client);

        $crawler = $client->request('GET', $this->adminUrl('/communication/e-mails'));
        // Adresse du compte admin de test : fictive (signalée, permise hors production)
        self::assertSelectorTextContains('[data-testid="address-recruitment"]', 'Adresse fictive : notifications@example.test');
        $client->submit($crawler->selectButton('Tester « Recrutement »')->form());
        $client->followRedirect();
        self::assertSelectorTextContains('main', 'E-mail de test « Recrutement » envoyé à notifications@example.test.');
        self::assertSame('E-mail de test du site — Recrutement', $this->smtp->sent[0]->getSubject());
        self::assertSame('noreply@example.test', $this->smtp->sent[0]->getFrom()[0]->getAddress());
    }

    /** Faux serveur d'envoi du test (état basculable). */
    private SwitchableMailer $smtp;

    /**
     * Remplace l'envoi par un faux serveur SMTP : injoignable (comme Mailpit arrêté) ou joignable.
     *
     * @param bool $reachable faux : chaque envoi échoue comme une connexion refusée
     */
    private function useMailer(bool $reachable): void
    {
        // Un seul faux serveur par test (le service ne se remplace qu'une fois) : on bascule son état
        if (isset($this->smtp)) {
            $this->smtp->reachable = $reachable;

            return;
        }
        $container = static::getContainer();
        $this->logger = new MemoryLogger();
        $this->smtp = new SwitchableMailer($reachable);
        $container->set(ResilientMailer::class, new ResilientMailer($this->smtp, $container->get(BodyRendererInterface::class), $container->get(EntityManagerInterface::class), $this->logger, $container->get(ClockInterface::class), $container->get(HeartbeatRepository::class)));
    }
}
