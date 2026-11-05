<?php

declare(strict_types=1);

namespace App\Service\Ai;

/**
 * Résultat d'un appel à l'IA par AiCaller : la réponse du modèle et l'indication « modèle de secours ».
 *
 * Liens :
 * - Créé par : AiCaller::call()
 * - Utilisé par : ChatAssistant, CvImportAnalyzer, CompanySynthesizer
 */
final readonly class AiCallResult
{
    /**
     * Regroupe la réponse et le modèle utilisé.
     *
     * @param AiCompletion $completion réponse du modèle (texte, jetons, durée)
     * @param bool         $fallback   vrai si le modèle de secours a répondu
     */
    public function __construct(
        public AiCompletion $completion,
        public bool $fallback,
    ) {
    }
}
