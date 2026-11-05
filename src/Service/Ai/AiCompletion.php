<?php

declare(strict_types=1);

namespace App\Service\Ai;

/**
 * Réponse d'un modèle : texte, modèle qui a répondu, jetons consommés, durée.
 *
 * Liens :
 * - Produite par : AiProviderInterface::complete() (MistralProvider)
 * - Utilisée par : AiCheckCommand, chatbot (journal des conversations, lot 7)
 */
final readonly class AiCompletion
{
    /**
     * Crée la réponse.
     *
     * @param string                $content          texte de la réponse
     * @param string                $model            identifiant du modèle qui a répondu
     * @param int                   $promptTokens     jetons envoyés (consignes, contenu, question)
     * @param int                   $completionTokens jetons de la réponse
     * @param int                   $durationMs       durée de l'appel (millisecondes)
     * @param string                $finishReason     raison de fin (« stop », « length »…)
     * @param array<string, string> $rateLimits       en-têtes de limites de débit renvoyés par le fournisseur
     *                                                (ex. « x-ratelimit-limit-tokens-minute » => « 500000 »), vide s'il n'en donne pas
     */
    public function __construct(
        public string $content,
        public string $model,
        public int $promptTokens,
        public int $completionTokens,
        public int $durationMs,
        public string $finishReason,
        public array $rateLimits = [],
    ) {
    }

    /**
     * Total des jetons de l'appel (envoyés + réponse).
     */
    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }
}
