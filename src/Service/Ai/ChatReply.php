<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\ChatMessage;

/**
 * Résultat d'une question libre : l'échange enregistré dans le journal et les types de données
 * personnelles retirées de la question (pour prévenir le recruteur).
 *
 * Liens :
 * - Créé par : ChatAssistant::ask()
 * - Utilisé par : RecruiterAreaController::assistantAsk()
 */
final readonly class ChatReply
{
    /**
     * Regroupe l'échange et les données retirées.
     *
     * @param ChatMessage  $message échange enregistré (question filtrée, réponse, issue)
     * @param list<string> $removed types de données retirées de la question (« e-mail », « téléphone »)
     */
    public function __construct(
        public ChatMessage $message,
        public array $removed,
    ) {
    }
}
