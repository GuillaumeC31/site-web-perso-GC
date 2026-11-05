<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\NotificationChannel;
use App\Service\Notification\TelegramCallbackHandler;
use App\Service\Recruiter\RemoteDecisionExecutor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Webhook Telegram de la validation à distance (en ligne) : reçoit les appuis sur les boutons « ✅ Accepter »
 * et « ❌ Refuser » des notifications, et les réponses portant le code PIN. Seul contrôle propre au webhook : l'en-tête
 * X-Telegram-Bot-Api-Secret-Token doit être égal à TELEGRAM_WEBHOOK_SECRET (sinon 403, et toujours 403 si le
 * secret n'est pas configuré). Le reste (conversation et compte, jeton, deux appuis) est fait par
 * TelegramCallbackHandler, comme pour app:telegram:poll en local.
 *
 * Adresse publique : elle est protégée par ce secret.
 *
 * Liens :
 * - Route : telegram_webhook (/telegram/webhook, POST), déclarée auprès de Telegram par app:telegram:webhook set
 * - Utilise : TelegramCallbackHandler, RemoteDecisionExecutor::reject() (journal)
 * - Testé par : tests/Controller/TelegramWebhookControllerTest
 */
final class TelegramWebhookController extends AbstractController
{
    /**
     * Reçoit les dépendances et le secret du webhook.
     *
     * @param string $secret secret attendu dans l'en-tête (TELEGRAM_WEBHOOK_SECRET) ; vide : tout est refusé
     */
    public function __construct(
        private readonly TelegramCallbackHandler $handler,
        private readonly RemoteDecisionExecutor $executor,
        #[Autowire('%env(TELEGRAM_WEBHOOK_SECRET)%')]
        private readonly string $secret,
    ) {
    }

    /**
     * Traite une mise à jour envoyée par Telegram (seuls les appuis sur un bouton comptent).
     *
     * Liens :
     * - Route : telegram_webhook (/telegram/webhook, POST)
     *
     * @return Response 403 si le secret est absent, faux ou non configuré ; sinon 200 (Telegram renverrait la mise à jour)
     */
    #[Route('/telegram/webhook', name: 'telegram_webhook', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $received = $request->headers->get('X-Telegram-Bot-Api-Secret-Token');
        if ('' === $this->secret || null === $received || !hash_equals($this->secret, $received)) {
            $this->executor->reject(NotificationChannel::Telegram, match (true) {
                '' === $this->secret => 'secret_not_configured',
                null === $received => 'secret_missing',
                default => 'secret_invalid',
            });

            return new Response('', Response::HTTP_FORBIDDEN);
        }
        $update = json_decode($request->getContent(), true);
        if (\is_array($update) && \is_array($update['callback_query'] ?? null)) {
            $this->handler->handle($update['callback_query']);
        } elseif (\is_array($update) && \is_array($update['message'] ?? null)) {
            // Réponse portant le code PIN (supprimée aussitôt par le traitement)
            $this->handler->handleMessage($update['message']);
        }

        return new JsonResponse(['ok' => true]);
    }
}
