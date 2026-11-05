<?php

declare(strict_types=1);

namespace App\Service\Notification;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Appels à l'API des bots Telegram (sendMessage, editMessageText, answerCallbackQuery, setWebhook…).
 * Une erreur lève une \RuntimeException au message clair (description renvoyée par Telegram), qui ne
 * contient JAMAIS le jeton du bot ni l'adresse appelée.
 *
 * Liens :
 * - Utilisé par : NotificationDispatcher (envoi), TelegramCallbackHandler (boutons), TelegramWebhookCommand,
 *   TelegramPollCommand (relève en local)
 * - Testé par : tests/Controller/TelegramWebhookControllerTest, tests/Service/NotificationDispatcherTest
 */
final readonly class TelegramClient
{
    /**
     * Reçoit le client HTTP et le jeton du bot.
     *
     * @param string $token jeton du bot (TELEGRAM_BOT_TOKEN, .env.local) ; vide : non configuré
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(TELEGRAM_BOT_TOKEN)%')]
        private string $token,
    ) {
    }

    /**
     * Indique si le jeton du bot est renseigné.
     */
    public function hasToken(): bool
    {
        return '' !== $this->token;
    }

    /**
     * Appelle une méthode de l'API et renvoie son résultat.
     *
     * Liens :
     * - Appelée par : NotificationDispatcher, TelegramWebhookController, TelegramWebhookCommand
     *
     * Exemple : call('sendMessage', ['chat_id' => '42', 'text' => 'Bonjour']).
     *
     * @param string               $method     méthode de l'API Telegram
     * @param array<string, mixed> $parameters paramètres envoyés en JSON
     * @param float                $timeout    délai maximal (secondes) ; plus long pour l'attente de getUpdates
     *
     * @return array<mixed>|bool champ « result » de la réponse
     *
     * @throws \RuntimeException si l'appel échoue (message sans jeton ni adresse)
     */
    public function call(string $method, array $parameters, float $timeout = 5): array|bool
    {
        if (!$this->hasToken()) {
            throw new \RuntimeException('TELEGRAM_BOT_TOKEN n\'est pas renseigné.');
        }
        try {
            $response = $this->httpClient->request('POST', \sprintf('https://api.telegram.org/bot%s/%s', $this->token, $method), ['json' => $parameters, 'timeout' => $timeout, 'max_duration' => $timeout + 5]);
            $status = $response->getStatusCode();
            $data = json_decode($response->getContent(false), true);
        } catch (ExceptionInterface) {
            throw new \RuntimeException('Telegram est injoignable (réseau ou délai dépassé).');
        }
        if (!\is_array($data) || true !== ($data['ok'] ?? false)) {
            $description = \is_array($data) && \is_string($data['description'] ?? null) ? $data['description'] : 'réponse illisible';
            throw new \RuntimeException(\sprintf('Telegram a refusé l\'appel %s (HTTP %d : %s).', $method, $status, str_replace($this->token, '[jeton]', $description)));
        }
        $result = $data['result'] ?? true;

        return \is_array($result) || \is_bool($result) ? $result : true;
    }
}
