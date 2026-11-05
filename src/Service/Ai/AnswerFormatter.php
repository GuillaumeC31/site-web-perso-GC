<?php

declare(strict_types=1);

namespace App\Service\Ai;

/**
 * Met en forme la réponse du modèle avant l'affichage : texte brut sans Markdown (gras, titres, listes,
 * liens, code), coupé proprement à la 3e phrase complète. L'échappement HTML est fait par Twig à l'affichage.
 *
 * Liens :
 * - Utilisé par : ChatAssistant::ask()
 * - Testé par : tests/Service/Ai/AnswerFormatterTest
 */
final class AnswerFormatter
{
    /** Nombre maximal de phrases d'une réponse (TEXTES_SITE § 17). */
    public const MAX_SENTENCES = 3;

    /** Longueur maximale affichée, par sécurité (caractères). */
    public const MAX_LENGTH = 900;

    /**
     * Renvoie la réponse en texte brut, limitée à 3 phrases.
     *
     * Liens :
     * - Appelée par : ChatAssistant::ask()
     */
    public function format(string $answer): string
    {
        return $this->limitSentences($this->stripMarkdown($answer));
    }

    /**
     * Retire la mise en forme Markdown : liens (texte gardé), code, gras, italique, titres, puces, citations.
     */
    public function stripMarkdown(string $text): string
    {
        $text = str_replace("\r\n", "\n", $text);
        $text = (string) preg_replace('/!?\[([^\]]*)\]\([^)]*\)/u', '$1', $text);          // [texte](lien), images
        $text = (string) preg_replace('/<https?:\/\/[^>\s]+>/u', '', $text);                 // <https://…>
        $text = (string) preg_replace('/```.*?```/su', '', $text);                          // blocs de code
        $text = (string) preg_replace('/`([^`]*)`/u', '$1', $text);                          // code en ligne
        $text = (string) preg_replace('/(\*\*|__)(.+?)\1/u', '$2', $text);                   // gras
        $text = (string) preg_replace('/(?<![\w*])[*_](\S(?:.*?\S)?)[*_](?![\w*])/u', '$1', $text); // italique
        $text = (string) preg_replace('/^\s{0,3}#{1,6}\s*/mu', '', $text);                   // titres
        $text = (string) preg_replace('/^\s*(?:[-*+•]|\d+[.)])\s+/mu', '', $text);           // puces et listes numérotées
        $text = (string) preg_replace('/^\s*>\s?/mu', '', $text);                            // citations
        $text = str_replace(['**', '__', '#'], '', $text);                                   // restes isolés

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Coupe le texte après la 3e phrase complète (fin par . ! ? ou …) ; un texte sans fin de phrase est gardé
     * entier, dans la limite de MAX_LENGTH (coupé au dernier mot complet).
     */
    public function limitSentences(string $text, int $max = self::MAX_SENTENCES): string
    {
        // Fin de phrase : ponctuation suivie d'une espace et d'une majuscule (ou de la fin du texte) ;
        // les abréviations courantes (« ex. », « M. », « R&D. » en milieu de phrase) ne coupent pas.
        preg_match_all('/.+?(?:[.!?…]+(?=\s+[\p{Lu}«"(]|\s*$))/su', $text, $matches);
        $sentences = [];
        $pending = '';
        foreach ($matches[0] as $piece) {
            $pending = trim($pending.' '.trim($piece));
            // Fragment terminé par une abréviation : il continue avec le suivant
            if (1 !== preg_match('/\b(?:ex|M|Mme|Mlle|cf|env|n°)\.$/u', $pending)) {
                $sentences[] = $pending;
                $pending = '';
            }
        }
        if ('' !== $pending) {
            $sentences[] = $pending;
        }
        if (\count($sentences) > $max) {
            $text = implode(' ', \array_slice($sentences, 0, $max));
        }

        if (mb_strlen($text) > self::MAX_LENGTH) {
            $cut = mb_substr($text, 0, self::MAX_LENGTH);
            $text = rtrim((string) preg_replace('/\s+\S*$/u', '', $cut), ' ,;:').'…';
        }

        return trim($text);
    }
}
