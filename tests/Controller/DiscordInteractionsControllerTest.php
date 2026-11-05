<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Recruiter;
use App\Enum\NotificationChannel;
use App\Enum\RecruiterStatus;
use App\Enum\SecurityEventType;
use App\Tests\Support\DiscordSigner;
use App\Tests\Support\RemoteDecisionTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Interactions de l'application Discord, simulées (Discord ne peut pas joindre localhost) : signature Ed25519
 * obligatoire (absente, fausse, trop ancienne : 401), PING, compte autorisé seulement, fenêtre du code PIN,
 * code faux puis blocage, bon code (traitement de l'admin, message mis à jour, boutons retirés), rejeu,
 * expiration ; journal de sécurité sans jeton ni code.
 *
 * Liens :
 * - Couvre : App\Controller\DiscordInteractionsController, App\Service\Notification\DiscordApp
 */
final class DiscordInteractionsControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use RemoteDecisionTestTrait;

    /** Compte Discord autorisé de .env.test. */
    private const ADMIN = '333';

    private KernelBrowser $client;

    /**
     * Démarre un client.
     */
    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /**
     * Vérifie que toute requête sans signature valide est refusée (401) et notée, sans effet ; PING signé : PONG.
     */
    public function testSignatureIsMandatory(): void
    {
        $body = (string) json_encode(['type' => 1]);
        $this->client->request('POST', '/discord/interactions', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        self::assertResponseStatusCodeSame(401);
        $this->post($body, signature: str_repeat('0', 128));
        self::assertResponseStatusCodeSame(401);
        $this->post($body, timestamp: (string) (time() - 3600));
        self::assertResponseStatusCodeSame(401);
        // Corps modifié après signature
        $timestamp = (string) time();
        $this->client->request('POST', '/discord/interactions', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE_ED25519' => DiscordSigner::sign($timestamp, $body), 'HTTP_X_SIGNATURE_TIMESTAMP' => $timestamp], content: '{"type":3}');
        self::assertResponseStatusCodeSame(401);
        self::assertSame(array_fill(0, 4, 'discord signature_invalid'), $this->securityUsernames(SecurityEventType::RemoteDecisionRejected));

        $this->post($body);
        self::assertResponseIsSuccessful();
        self::assertSame(['type' => 1], $this->json());
    }

    /**
     * Vérifie le parcours : clic (fenêtre du code PIN), code faux (essais restants), bon code (accès ouvert comme
     * depuis l'admin, message mis à jour, boutons retirés), puis rejeu refusé ; jamais le code au journal.
     */
    public function testApproveInsideDiscordWithPin(): void
    {
        $this->enableRemoteValidation(withPin: true);
        $recruiter = $this->createPendingRequest();
        $tokens = $this->issueTokens($recruiter, NotificationChannel::Discord);

        $this->post($this->click('d:'.$tokens['approve']));
        $modal = $this->json();
        self::assertSame(9, $modal['type']);
        self::assertSame('m:'.$tokens['approve'], $modal['data']['custom_id']);
        self::assertSame('Accepter la demande', $modal['data']['title']);
        self::assertSame(18, $modal['data']['components'][0]['type']);
        self::assertSame(['type' => 4, 'custom_id' => 'pin', 'style' => 1, 'min_length' => 6, 'max_length' => 12, 'required' => true], $modal['data']['components'][0]['component']);
        self::assertSame(RecruiterStatus::Pending, $this->reloadStatus($recruiter));

        $this->post($this->submit('m:'.$tokens['approve'], '000000'));
        self::assertSame(['type' => 4, 'data' => ['content' => 'Code PIN incorrect : encore 4 essais.', 'flags' => 64, 'allowed_mentions' => ['parse' => []]]], $this->json());
        self::assertSame(RecruiterStatus::Pending, $this->reloadStatus($recruiter));

        $this->post($this->submit('m:'.$tokens['approve'], self::TEST_PIN));
        $update = $this->json();
        self::assertSame(7, $update['type']);
        self::assertSame([], $update['data']['components']);
        self::assertMatchesRegularExpression('#✅ Accepté le \d{2}/\d{2} à \d{2} h \d{2}#', $update['data']['content']);
        self::assertStringContainsString('E-mail : `@acme.example`', $update['data']['content']);
        self::assertStringNotContainsString('rh@acme.test', $update['data']['content']);
        self::assertSame(RecruiterStatus::Approved, $this->reloadStatus($recruiter));
        self::assertEmailCount(1);
        self::assertSame(['discord approve #'.$recruiter->getId()], $this->securityUsernames(SecurityEventType::RemoteDecision));

        $this->post($this->click('d:'.$tokens['approve']));
        self::assertSame('Bouton déjà utilisé.', $this->json()['data']['content']);
        foreach ([...$this->securityUsernames(SecurityEventType::RemoteDecisionRejected), ...$this->securityUsernames(SecurityEventType::RemoteDecision)] as $username) {
            self::assertStringNotContainsString(self::TEST_PIN, (string) $username);
            self::assertStringNotContainsString($tokens['approve'], (string) $username);
        }
    }

    /**
     * Vérifie les refus : autre compte Discord, 5 codes faux (bouton bloqué), expiration, jeton Telegram, demande
     * déjà traitée.
     */
    public function testRefusals(): void
    {
        $this->enableRemoteValidation(withPin: true);
        $recruiter = $this->createPendingRequest();
        $tokens = $this->issueTokens($recruiter, NotificationChannel::Discord);

        // Autre compte : refusé, même avec le bon code
        $this->post($this->click('d:'.$tokens['refuse'], '999'));
        self::assertSame('Action non autorisée.', $this->json()['data']['content']);
        $this->post($this->submit('m:'.$tokens['refuse'], self::TEST_PIN, '999'));
        self::assertSame('Action non autorisée.', $this->json()['data']['content']);
        self::assertSame(RecruiterStatus::Pending, $this->reloadStatus($recruiter));

        // 5 codes faux : bouton bloqué, message mis à jour
        foreach (range(1, 5) as $attempt) {
            $this->post($this->submit('m:'.$tokens['refuse'], '111111'));
        }
        self::assertSame(7, $this->json()['type']);
        self::assertStringContainsString('Bouton bloqué', $this->json()['data']['content']);
        $this->post($this->submit('m:'.$tokens['refuse'], self::TEST_PIN));
        self::assertStringContainsString('Bouton bloqué', $this->json()['data']['content']);
        self::assertSame(RecruiterStatus::Pending, $this->reloadStatus($recruiter));

        // Jeton Telegram, puis expiration
        $telegram = $this->issueTokens($this->fresh($recruiter), NotificationChannel::Telegram);
        $this->post($this->click('d:'.$telegram['approve']));
        self::assertSame('Bouton inconnu : utilisez l\'admin.', $this->json()['data']['content']);
        self::mockTime('+73 hours');
        $this->post($this->click('d:'.$tokens['approve']));
        self::assertStringContainsString('Bouton expiré', $this->json()['data']['content']);

        $rejected = $this->securityUsernames(SecurityEventType::RemoteDecisionRejected);
        self::assertContains('discord foreign_user', $rejected);
        self::assertContains('discord pin_blocked #'.$recruiter->getId(), $rejected);
        self::assertContains('discord expired #'.$recruiter->getId(), $rejected);
    }

    /**
     * Envoie une interaction signée comme Discord.
     */
    private function post(string $body, ?string $signature = null, ?string $timestamp = null): void
    {
        // Horodatage selon l'horloge (simulée ou non) : celle avec laquelle le site contrôle l'écart
        $timestamp ??= (string) Clock::get()->now()->getTimestamp();
        $this->client->request('POST', '/discord/interactions', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE_ED25519' => $signature ?? DiscordSigner::sign($timestamp, $body),
            'HTTP_X_SIGNATURE_TIMESTAMP' => $timestamp,
        ], content: $body);
    }

    /**
     * Clic simulé sur un bouton, dans un serveur (compte dans member.user).
     */
    private function click(string $customId, string $userId = self::ADMIN): string
    {
        return (string) json_encode(['type' => 3, 'member' => ['user' => ['id' => $userId]], 'data' => ['custom_id' => $customId, 'component_type' => 2]]);
    }

    /**
     * Validation simulée de la fenêtre du code PIN (Label → Text Input).
     */
    private function submit(string $customId, string $pin, string $userId = self::ADMIN): string
    {
        return (string) json_encode(['type' => 5, 'member' => ['user' => ['id' => $userId]], 'data' => ['custom_id' => $customId, 'components' => [
            ['type' => 18, 'component' => ['type' => 4, 'custom_id' => 'pin', 'value' => $pin]],
        ]]]);
    }

    /**
     * Réponse JSON décodée.
     *
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /**
     * Relit une demande (après un vidage du gestionnaire d'entités).
     */
    private function fresh(Recruiter $recruiter): Recruiter
    {
        return static::getContainer()->get(EntityManagerInterface::class)->find(Recruiter::class, $recruiter->getId()) ?? throw new \LogicException('Demande introuvable.');
    }
}
