<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Recruiter;
use App\Enum\NotificationChannel;
use App\Enum\RecruiterStatus;
use App\Enum\SecurityEventType;
use App\Service\Recruiter\RecruiterAccessManager;
use App\Tests\Support\RemoteDecisionTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Mime\Email;

/**
 * Liens « Accepter » et « Refuser » des notifications Discord : GET sans effet (aperçus automatiques), POST
 * avec jeton CSRF et code PIN correct, 5 codes faux puis blocage, usage unique, expiration (72 h), demande
 * déjà traitée, interrupteur ; même traitement que l'admin ; pages en noindex.
 *
 * Liens :
 * - Couvre : App\Controller\RemoteDecisionController, RemoteDecisionTokens, RemoteDecisionExecutor
 */
final class RemoteDecisionControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use RemoteDecisionTestTrait;

    private KernelBrowser $client;

    /**
     * Démarre un client.
     */
    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /**
     * Vérifie que l'ouverture du lien (GET, comme un aperçu automatique) ne décide rien et affiche le résumé,
     * sans e-mail complet ni IP, en noindex.
     */
    public function testGetShowsConfirmationWithoutEffect(): void
    {
        $this->enableRemoteValidation(withPin: true);
        $recruiter = $this->createPendingRequest();
        $recruiter->setIpAddress('203.0.113.9');
        $tokens = $this->issueTokens($recruiter, NotificationChannel::Discord);

        $this->client->request('GET', '/validation-distance/'.$tokens['approve']);
        $this->client->request('GET', '/validation-distance/'.$tokens['approve']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Accepter la demande de Camille Martin (Acme) ?');
        $summary = $this->client->getCrawler()->filter('[data-testid="remote-decision-summary"]')->text();
        self::assertStringContainsString('@acme.example', $summary);
        self::assertStringContainsString('Chargée de recrutement', $summary);
        self::assertStringNotContainsString('rh@acme.test', $summary);
        self::assertStringNotContainsString('203.0.113.9', (string) $this->client->getResponse()->getContent());
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        self::assertSelectorExists('meta[name="robots"][content="noindex, nofollow"]');
        self::assertSame(RecruiterStatus::Pending, $this->reloadStatus($recruiter));
        self::assertEmailCount(0);
        self::assertSame([], $this->securityUsernames(SecurityEventType::RemoteDecision));
    }

    /**
     * Vérifie l'acceptation avec le bon code PIN (même traitement que l'admin), puis le rejeu refusé.
     */
    public function testCorrectPinApprovesOnceOnly(): void
    {
        $this->enableRemoteValidation(withPin: true);
        $recruiter = $this->createPendingRequest();
        $tokens = $this->issueTokens($recruiter, NotificationChannel::Discord);

        $this->submit($tokens['approve'], self::TEST_PIN);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="remote-decision-result"]', 'Demande de Camille Martin (Acme) acceptée');
        self::assertSame(RecruiterStatus::Approved, $this->reloadStatus($recruiter));
        self::assertEmailCount(1);
        self::assertEmailAddressContains(self::getMailerMessage(), 'To', 'rh@acme.test');
        self::assertSame(['discord approve #'.$recruiter->getId()], $this->securityUsernames(SecurityEventType::RemoteDecision));

        $this->client->request('GET', '/validation-distance/'.$tokens['approve']);
        self::assertResponseStatusCodeSame(410);
        self::assertSelectorTextContains('h1', 'Lien déjà utilisé');
    }

    /**
     * Vérifie le refus avec le bon code PIN : même traitement que l'admin (e-mail de refus au demandeur, sans\n     * adresse de réponse).
     */
    public function testCorrectPinRefuses(): void
    {
        $this->enableRemoteValidation(withPin: true);
        $recruiter = $this->createPendingRequest();
        $tokens = $this->issueTokens($recruiter, NotificationChannel::Discord);

        $this->submit($tokens['refuse'], self::TEST_PIN);

        self::assertSame(RecruiterStatus::Refused, $this->reloadStatus($recruiter));
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame([], $email->getReplyTo());
    }

    /**
     * Vérifie qu'un code PIN faux ne décide rien, compte les essais, et bloque le lien au 5e.
     */
    public function testWrongPinIsCountedThenLinkIsBlocked(): void
    {
        $this->enableRemoteValidation(withPin: true);
        $recruiter = $this->createPendingRequest();
        $tokens = $this->issueTokens($recruiter, NotificationChannel::Discord);

        $this->submit($tokens['approve'], '000000');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-testid="remote-decision-error"]', 'encore 4 essais');
        foreach (range(1, 3) as $attempt) {
            $this->submit($tokens['approve'], '111111');
        }
        self::assertSelectorTextContains('[data-testid="remote-decision-error"]', 'encore 1 essai');
        $this->submit($tokens['approve'], '222222');
        self::assertResponseStatusCodeSame(410);
        self::assertSelectorTextContains('h1', 'Lien bloqué');

        // Même le bon code ne sert plus
        $this->client->request('GET', '/validation-distance/'.$tokens['approve']);
        self::assertResponseStatusCodeSame(410);
        self::assertSame(RecruiterStatus::Pending, $this->reloadStatus($recruiter));
        $rejected = $this->securityUsernames(SecurityEventType::RemoteDecisionRejected);
        self::assertSame(array_merge(array_fill(0, 4, 'discord pin_invalid #'.$recruiter->getId()), ['discord pin_blocked #'.$recruiter->getId(), 'discord blocked #'.$recruiter->getId()]), $rejected);
    }

    /**
     * Vérifie qu'un envoi sans jeton CSRF valide ne décide rien (et ne compte pas d'essai de code PIN).
     */
    public function testPostWithoutCsrfIsRefused(): void
    {
        $this->enableRemoteValidation(withPin: true);
        $recruiter = $this->createPendingRequest();
        $tokens = $this->issueTokens($recruiter, NotificationChannel::Discord);

        $this->client->request('POST', '/validation-distance/'.$tokens['approve'], ['pin' => self::TEST_PIN, '_token' => 'faux']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(RecruiterStatus::Pending, $this->reloadStatus($recruiter));
        self::assertSame(['discord csrf #'.$recruiter->getId()], $this->securityUsernames(SecurityEventType::RemoteDecisionRejected));
    }

    /**
     * Vérifie l'expiration après 72 h, la demande déjà traitée, le jeton Telegram et le lien inconnu.
     */
    public function testExpiredProcessedAndUnknownLinks(): void
    {
        $this->enableRemoteValidation(withPin: true);
        $recruiter = $this->createPendingRequest();
        $discord = $this->issueTokens($recruiter, NotificationChannel::Discord);
        $telegram = $this->issueTokens($recruiter, NotificationChannel::Telegram);

        $this->client->request('GET', '/validation-distance/'.$telegram['approve']);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/validation-distance/inconnu');
        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('h1', 'Lien inconnu');

        self::mockTime('+73 hours');
        $this->client->request('GET', '/validation-distance/'.$discord['approve']);
        self::assertResponseStatusCodeSame(410);
        self::assertSelectorTextContains('h1', 'Lien expiré');

        self::mockTime(false);
        static::getContainer()->get(RecruiterAccessManager::class)->approve($this->reloadRecruiter($recruiter->getId()));
        $this->client->request('GET', '/validation-distance/'.$discord['refuse']);
        self::assertResponseStatusCodeSame(409);
        self::assertSelectorTextContains('h1', 'Demande déjà traitée');
    }

    /**
     * Vérifie que l'interrupteur désactivé rend les liens sans effet.
     */
    public function testTrialLinkFollowsTheWholeFlowWithoutDeciding(): void
    {
        $this->enableRemoteValidation(withPin: true);
        $tokens = $this->issueTokens(null, NotificationChannel::Discord);

        $this->client->request('GET', '/validation-distance/'.$tokens['refuse']);
        self::assertSelectorTextContains('h1', 'Essai : refuser la demande fictive ?');
        self::assertSelectorTextContains('main', 'confirmer cet essai ne décide rien');
        self::assertSelectorTextContains('[data-testid="remote-decision-summary"]', '70/100 — fiabilité bonne');
        $this->submit($tokens['refuse'], self::TEST_PIN);

        self::assertSelectorTextContains('[data-testid="remote-decision-result"]', 'Essai réussi');
        self::assertEmailCount(0);
        self::assertSame(['discord refuse essai'], $this->securityUsernames(SecurityEventType::RemoteDecision));
    }

    /**
     * Vérifie que l'interrupteur désactivé rend les liens sans effet.
     */
    public function testDisabledSwitchRefusesLinks(): void
    {
        $this->enableRemoteValidation(withPin: true);
        $recruiter = $this->createPendingRequest();
        $tokens = $this->issueTokens($recruiter, NotificationChannel::Discord);
        $this->enableRemoteValidation(false);

        $this->client->request('GET', '/validation-distance/'.$tokens['approve']);

        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextContains('h1', 'Validation à distance désactivée');
        self::assertSame(RecruiterStatus::Pending, $this->reloadStatus($recruiter));
    }

    /**
     * Ouvre le lien puis envoie le formulaire avec un code PIN.
     */
    private function submit(string $token, string $pin): void
    {
        $crawler = $this->client->request('GET', '/validation-distance/'.$token);
        $form = $crawler->filter('form[action$="/validation-distance/'.$token.'"]');
        if (0 === $form->count()) {
            // Lien déjà bloqué ou refusé : envoi direct (jeton CSRF sans importance)
            $this->client->request('POST', '/validation-distance/'.$token, ['pin' => $pin, '_token' => '']);

            return;
        }
        $this->client->submit($form->form(['pin' => $pin]));
    }

    /**
     * Relit une demande en base.
     */
    private function reloadRecruiter(?int $id): Recruiter
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getRepository(Recruiter::class)->find($id) ?? throw new \LogicException('Demande introuvable.');
    }
}
