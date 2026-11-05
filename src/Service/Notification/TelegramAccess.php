<?php

declare(strict_types=1);

namespace App\Service\Notification;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Qui peut appuyer sur les boutons Telegram : la conversation TELEGRAM_CHAT_ID ET le compte TELEGRAM_USER_ID.
 * TELEGRAM_USER_ID vide et conversation privée (identifiant positif : en privé, Telegram donne à la
 * conversation l'identifiant du compte) : le compte autorisé est celui de la conversation (décision du
 * 2026-10-27). Groupe ou canal (identifiant négatif) : TELEGRAM_USER_ID est obligatoire.
 *
 * Liens :
 * - Utilisé par : RemoteDecisionTokens (boutons proposés ou non), TelegramCallbackHandler (contrôle de chaque
 *   appui), Admin\SiteSettingsController (état affiché)
 * - Testé par : tests/Controller/TelegramWebhookControllerTest
 */
final readonly class TelegramAccess
{
    /**
     * Reçoit la configuration.
     *
     * @param string $chatId conversation des notifications (TELEGRAM_CHAT_ID)
     * @param string $userId compte autorisé (TELEGRAM_USER_ID), vide : voir allowedUserId()
     */
    public function __construct(
        #[Autowire('%env(TELEGRAM_CHAT_ID)%')]
        private string $chatId,
        #[Autowire('%env(TELEGRAM_USER_ID)%')]
        private string $userId,
    ) {
    }

    /**
     * Renvoie la conversation autorisée (vide : Telegram non configuré).
     */
    public function allowedChatId(): string
    {
        return trim($this->chatId);
    }

    /**
     * Renvoie le compte autorisé : TELEGRAM_USER_ID, ou la conversation privée si la variable est vide.
     *
     * @return string identifiant du compte ; vide si aucun compte ne peut être déduit (groupe sans TELEGRAM_USER_ID)
     */
    public function allowedUserId(): string
    {
        if ('' !== trim($this->userId)) {
            return trim($this->userId);
        }

        return 1 === preg_match('/^[1-9]\d*$/', $this->allowedChatId()) ? $this->allowedChatId() : '';
    }

    /**
     * Indique si un appui vient de la conversation ET du compte autorisés.
     *
     * Liens :
     * - Appelée par : TelegramCallbackHandler::handle()
     *
     * @param string $chatId conversation du message des boutons
     * @param string $fromId compte qui a appuyé
     */
    public function isAllowed(string $chatId, string $fromId): bool
    {
        return '' !== $this->allowedUserId() && $chatId === $this->allowedChatId() && $fromId === $this->allowedUserId();
    }

    /**
     * Renvoie ce qui manque pour proposer des boutons (texte clair), ou null si tout est prêt.
     *
     * Liens :
     * - Appelée par : RemoteDecisionTokens::telegramKeyboard(), Admin\SiteSettingsController
     */
    public function missing(): ?string
    {
        return match (true) {
            '' === $this->allowedChatId() => 'TELEGRAM_CHAT_ID manquant',
            '' === $this->allowedUserId() => 'TELEGRAM_USER_ID manquant (conversation de groupe)',
            default => null,
        };
    }
}
