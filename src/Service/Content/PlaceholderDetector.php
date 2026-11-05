<?php

declare(strict_types=1);

namespace App\Service\Content;

/**
 * Repère les textes encore « à compléter » (repères entre crochets du type
 * « [À COMPLÉTER] », « [nb] », « [%] », « [montant] »).
 *
 * Règle du site : un chiffre ou une réalisation incomplète n'est jamais affiché en
 * public (pas de « X » ni de crochets visibles) ; l'admin, elle, le signale.
 *
 * Liens :
 * - Utilisé par : Experience::getPublicAchievementList(), KeyFigure::isComplete(),
 *   ContentCompletenessChecker, TextesSiteParser (import)
 */
final class PlaceholderDetector
{
    /** Repère entre crochets, quelle que soit sa longueur (avec ou sans accents graves Markdown autour). */
    private const PLACEHOLDER_PATTERN = '/\[[^\]\n]+\]/u';

    /** Mentions explicites, même si le crochet fermant manque. */
    private const PLACEHOLDER_WORDS = '/À\s+COMPL[ÉE]TER|À\s+FOURNIR/iu';

    /**
     * Indique si un texte est complet : non vide et sans repère entre crochets.
     *
     * Liens :
     * - Appelée par : Experience, KeyFigure, ContentCompletenessChecker, TextesSiteParser
     *
     * Exemple : isComplete('Encadrement de [nb] personnes') renvoie false.
     *
     * @param string|null $text texte à vérifier
     */
    public static function isComplete(?string $text): bool
    {
        $text = trim((string) $text);

        return '' !== $text
            && 1 !== preg_match(self::PLACEHOLDER_PATTERN, $text)
            && 1 !== preg_match(self::PLACEHOLDER_WORDS, $text);
    }
}
