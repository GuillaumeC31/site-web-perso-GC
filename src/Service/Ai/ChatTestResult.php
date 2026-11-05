<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\AiKnowledgeEntry;
use App\Entity\ChatMessage;

/**
 * Résultat d'un essai de l'assistant par l'admin (banc d'essai du « Cerveau de l'IA ») : échange enregistré
 * (essai admin), fiches qui ont servi à la réponse, élément qui a fait bloquer la réponse.
 *
 * Liens :
 * - Créé par : ChatAssistant::test()
 * - Affiché par : templates/admin/ai_knowledge/index.html.twig (banc d'essai)
 */
final readonly class ChatTestResult
{
    /**
     * Regroupe le résultat de l'essai.
     *
     * @param ChatMessage            $message     échange enregistré (essai admin)
     * @param list<AiKnowledgeEntry> $usedEntries fiches dont le contenu se retrouve dans la réponse
     * @param string|null            $rawAnswer   réponse du modèle avant remplacement (si elle a été bloquée), sinon null
     */
    public function __construct(
        public ChatMessage $message,
        public array $usedEntries,
        public ?string $rawAnswer = null,
    ) {
    }
}
