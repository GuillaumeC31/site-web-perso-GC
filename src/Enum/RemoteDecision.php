<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Décision prise à distance sur une demande d'accès recruteur (bouton Telegram ou lien Discord) : accepter
 * ou refuser, avec le même traitement que depuis l'admin.
 *
 * Liens :
 * - Utilisé par : RemoteDecisionToken, RemoteDecisionTokens, RemoteDecisionExecutor, TelegramWebhookController,
 *   RemoteDecisionController
 */
enum RemoteDecision: string
{
    case Approve = 'approve';
    case Refuse = 'refuse';

    /**
     * Renvoie la clé de traduction d'un texte lié à la décision (« remote_decision.approve.button »…).
     *
     * Liens :
     * - Appelée par : TelegramWebhookController, templates/remote_decision/*.html.twig
     *
     * @param string $suffix partie finale de la clé (button, confirm, done…)
     */
    public function translationKey(string $suffix): string
    {
        return 'remote_decision.'.$this->value.'.'.$suffix;
    }
}
