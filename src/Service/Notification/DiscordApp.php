<?php

declare(strict_types=1);

namespace App\Service\Notification;

use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Application Discord de la validation à distance (décision du 2026-10-27) : le bot envoie les notifications
 * dans le salon configuré avec de VRAIS boutons interactifs ; Discord transmet les clics au point d'entrée
 * /discord/interactions (DiscordInteractionsController), dont chaque requête est signée (Ed25519).
 *
 * Configuration (.env.local uniquement, jamais dans Git ; check-staged-assets bloque toute valeur versionnée) :
 * DISCORD_APPLICATION_ID, DISCORD_PUBLIC_KEY (clé publique de l'application, hexadécimale), DISCORD_BOT_TOKEN,
 * DISCORD_CHANNEL_ID (salon des notifications), DISCORD_ADMIN_USER_ID (seul compte autorisé à décider).
 * Application non configurée : le webhook DISCORD_WEBHOOK_URL reste utilisé (boutons-liens vers la page de
 * confirmation).
 *
 * Liens :
 * - Utilisé par : NotificationDispatcher (envoi), DiscordInteractionsController (signature, compte autorisé),
 *   Admin\SiteSettingsController (état)
 * - Testé par : tests/Controller/DiscordInteractionsControllerTest, tests/Service/NotificationDispatcherTest
 */
final readonly class DiscordApp
{
    /** Écart toléré entre l'horodatage signé et l'heure du serveur (secondes) : un vieux clic rejoué est refusé. */
    public const MAX_CLOCK_SKEW = 300;

    /**
     * Reçoit la configuration.
     *
     * @param string $applicationId identifiant de l'application (DISCORD_APPLICATION_ID)
     * @param string $publicKey     clé publique Ed25519 de l'application, hexadécimale (DISCORD_PUBLIC_KEY)
     * @param string $botToken      jeton du bot (DISCORD_BOT_TOKEN), jamais affiché ni journalisé
     * @param string $channelId     salon des notifications (DISCORD_CHANNEL_ID)
     * @param string $adminUserId   compte Discord de Guillaume (DISCORD_ADMIN_USER_ID)
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        private ClockInterface $clock,
        #[Autowire('%env(DISCORD_APPLICATION_ID)%')]
        private string $applicationId,
        #[Autowire('%env(DISCORD_PUBLIC_KEY)%')]
        private string $publicKey,
        #[Autowire('%env(DISCORD_BOT_TOKEN)%')]
        private string $botToken,
        #[Autowire('%env(DISCORD_CHANNEL_ID)%')]
        private string $channelId,
        #[Autowire('%env(DISCORD_ADMIN_USER_ID)%')]
        private string $adminUserId,
    ) {
    }

    /**
     * Indique si l'application est entièrement configurée (les cinq variables renseignées).
     *
     * Liens :
     * - Appelée par : NotificationDispatcher, Admin\SiteSettingsController
     */
    public function isConfigured(): bool
    {
        return '' !== trim($this->applicationId) && '' !== trim($this->publicKey) && '' !== trim($this->botToken)
            && '' !== trim($this->channelId) && '' !== trim($this->adminUserId);
    }

    /**
     * Renvoie, variable par variable, ce qui est renseigné (jamais les valeurs) : page Communication › Discord.
     *
     * Liens :
     * - Appelée par : Admin\CommunicationController::discord()
     *
     * @return array<string, bool> application_id, public_key, bot_token, channel_id, admin_user_id => renseignée ?
     */
    public function getConfigurationStatus(): array
    {
        return [
            'application_id' => '' !== trim($this->applicationId),
            'public_key' => '' !== trim($this->publicKey),
            'bot_token' => '' !== trim($this->botToken),
            'channel_id' => '' !== trim($this->channelId),
            'admin_user_id' => '' !== trim($this->adminUserId),
        ];
    }

    /**
     * Vérifie la signature Ed25519 d'une requête d'interaction : signature de l'horodatage suivi du corps brut,
     * avec la clé publique de l'application ; horodatage récent exigé (rejeu refusé).
     *
     * Liens :
     * - Appelée par : DiscordInteractionsController::__invoke()
     *
     * @param string|null $signature en-tête X-Signature-Ed25519 (hexadécimal)
     * @param string|null $timestamp en-tête X-Signature-Timestamp (secondes)
     * @param string      $body      corps brut de la requête
     *
     * @return bool vrai seulement si la signature est valide et récente
     */
    public function verifySignature(?string $signature, ?string $timestamp, string $body): bool
    {
        if (!$this->isConfigured() || null === $signature || null === $timestamp || 1 !== preg_match('/^[0-9a-f]{128}$/i', $signature)
            || 1 !== preg_match('/^[0-9a-f]{64}$/i', trim($this->publicKey)) || 1 !== preg_match('/^\d{1,12}$/', $timestamp)) {
            return false;
        }
        if (abs($this->clock->now()->getTimestamp() - (int) $timestamp) > self::MAX_CLOCK_SKEW) {
            return false;
        }

        return sodium_crypto_sign_verify_detached((string) hex2bin($signature), $timestamp.$body, (string) hex2bin(trim($this->publicKey)));
    }

    /**
     * Indique si le compte Discord qui a cliqué est celui de Guillaume.
     *
     * @param string $userId identifiant du compte (member.user.id dans un serveur, user.id en message privé)
     */
    public function isAdmin(string $userId): bool
    {
        return '' !== trim($this->adminUserId) && hash_equals(trim($this->adminUserId), $userId);
    }

    /**
     * Envoie un message dans le salon configuré, par le bot (boutons interactifs permis).
     *
     * Liens :
     * - Appelée par : NotificationDispatcher (canal Discord, application configurée)
     *
     * @param array<string, mixed> $payload contenu et composants
     *
     * @return int code HTTP de la réponse de Discord
     *
     * @throws \RuntimeException si Discord est injoignable (message sans jeton ni adresse)
     */
    public function sendMessage(array $payload): int
    {
        try {
            return $this->httpClient->request('POST', \sprintf('https://discord.com/api/v10/channels/%s/messages', rawurlencode(trim($this->channelId))), [
                'headers' => ['Authorization' => 'Bot '.trim($this->botToken)],
                'json' => $payload + ['allowed_mentions' => ['parse' => []]],
                'timeout' => 3,
            ])->getStatusCode();
        } catch (ExceptionInterface) {
            throw new \RuntimeException('Discord (application) est injoignable (réseau ou délai dépassé).');
        }
    }
}
