<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\AiKnowledgeEntry;

/**
 * Contrôle avant affichage d'une réponse de l'IA (règle absolue : aucune information hors du site) :
 * chaque élément vérifiable de la réponse doit figurer dans le contexte envoyé au modèle (socle, résumé du
 * site, fiches actives ; la question du recruteur n'en fait pas partie).
 *
 * Éléments extraits :
 * - nombres : dates, années, durées, chiffres, montants (« 2015 », « 12 », « 55 », « 4,5 ») ;
 * - noms propres et sigles : mots à majuscule hors début de phrase, sigles, mots mêlant lettres et chiffres
 *   (entreprises, lieux, diplômes, certifications, normes, logiciels : « Lyon », « BTS », « ALPHA ESSAIS »,
 *   « R-290 », « NF », « SolidWorks »).
 * Comparaison normalisée : accents, casse, espaces, apostrophes et séparateurs de milliers ignorés ; un montant
 * en milliers du contexte (« XX à YY k€ ») vaut aussi sa forme en euros (« XX 000 € »).
 *
 * Limites connues : un nom écrit tout en minuscules ou un nombre écrit en lettres n'est pas contrôlé ; la
 * correction des durées (ChatGuard::fixTotalExperience()) reste appliquée avant ce contrôle.
 *
 * Liens :
 * - Utilisé par : ChatAssistant (réponse remplacée par ChatGuard::NOT_SPECIFIED si un élément manque)
 * - Testé par : tests/Service/Ai/AnswerFactCheckerTest
 */
final class AnswerFactChecker
{
    /** Mots à majuscule courants en début de proposition, jamais contrôlés. */
    private const COMMON_WORDS = [
        'je', 'il', 'elle', 'ils', 'elles', 'on', 'nous', 'vous', 'ce', 'cet', 'cette', 'ces', 'ses', 'son', 'sa',
        'le', 'la', 'les', 'l', 'un', 'une', 'de', 'du', 'des', 'd', 'en', 'au', 'aux', 'dans', 'pour', 'par',
        'avec', 'sur', 'sous', 'mais', 'et', 'ou', 'si', 'oui', 'non', 'aussi', 'puis', 'ensuite', 'enfin',
        'chez', 'avant', 'apres', 'depuis', 'pendant', 'lors', 'grace', 'selon', 'comme', 'quant', 'c', 'qu',
        'votre', 'vos', 'notre', 'nos', 'leur', 'leurs', 'tout', 'tous', 'toute', 'toutes', 'plusieurs',
        'monsieur', 'madame', 'bonjour', 'merci',
    ];

    /** Mots courts ou vides ignorés pour repérer les fiches utilisées. */
    private const MIN_KEYWORD_LENGTH = 5;

    /** Mots vides de 5 lettres ou plus (forme normalisée), jamais distinctifs d'une fiche. */
    private const STOP_WORDS = [
        'alors', 'aussi', 'autre', 'autres', 'avant', 'apres', 'avoir', 'avons', 'celle', 'celui', 'cette', 'comme',
        'comment', 'dans', 'depuis', 'donc', 'elles', 'encore', 'entre', 'etait', 'etant', 'faire', 'leurs',
        'mais', 'notre', 'parce', 'pendant', 'peut', 'plus', 'pourquoi', 'quand', 'quelle', 'quelles', 'quels',
        'selon', 'toujours', 'toute', 'toutes', 'votre', 'demande', 'demandes', 'reponds', 'reponse', 'reponses',
        'question', 'questions', 'precise', 'preciser',
    ];

    /** Termes distinctifs d'une fiche à retrouver dans la réponse : au moins 2, et au moins 30 % de ses termes. */
    private const MIN_DISTINCTIVE_MATCHES = 2;
    private const MIN_DISTINCTIVE_SHARE = 0.3;

    /**
     * Renvoie le premier élément de la réponse absent du contexte, ou null si tout est vérifié.
     *
     * Liens :
     * - Appelée par : ChatAssistant (après la mise en forme et la correction des durées)
     *
     * Exemple : findUnsupported('Il a rejoint Airbus en 2015.', 'CV : … ALPHA ESSAIS … 2012 …') renvoie « Airbus ».
     *
     * @param string $answer  réponse du modèle, mise en forme
     * @param string $context contexte envoyé (socle, résumé du site, fiches)
     *
     * @return string|null élément fautif, tel qu'écrit dans la réponse ; null si la réponse est fidèle
     */
    public function findUnsupported(string $answer, string $context): ?string
    {
        $normalizedContext = ' '.$this->expandThousandUnits($this->normalize($context)).' ';
        foreach ($this->extractElements($answer) as $element) {
            $needle = $this->normalize($element);
            if ('' === $needle) {
                continue;
            }
            $pattern = ctype_digit(str_replace(['.', '-'], '', $needle))
                ? '/(?<![\d.\-])'.preg_quote($needle, '/').'(?![\d\-]|\.\d)/u'
                : '/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/u';
            if (1 !== preg_match($pattern, $normalizedContext)) {
                return $element;
            }
        }

        return null;
    }

    /**
     * Renvoie les éléments contrôlés d'une réponse (nombres, noms propres, sigles), dans leur ordre.
     *
     * Liens :
     * - Appelée par : findUnsupported() ; tests
     *
     * @return list<string> éléments tels qu'écrits dans la réponse, sans doublon
     */
    public function extractElements(string $answer): array
    {
        $text = $this->joinThousands(str_replace(['’', '«', '»', '"'], ["'", ' ', ' ', ' '], $answer));
        $elements = [];

        // Nombres (dates, durées, montants), décimales comprises
        // « 15-140 » (norme) reste un seul nombre : « 15 » seul ne s'y retrouve pas
        preg_match_all('/(?<![\p{L}\d-])\d+(?:[.,]\d+)?(?:-\d+)*(?![\d])/u', $text, $numbers);
        foreach ($numbers[0] as $number) {
            $elements[] = $number;
        }

        // Mots : début de phrase repéré pour ne pas contrôler la majuscule d'usage
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\-]*|[.!?…:;]/u', $text, $tokens);
        $sentenceStart = true;
        foreach ($tokens[0] as $token) {
            if (1 === preg_match('/^[.!?…:;]$/u', $token)) {
                $sentenceStart = true;
                continue;
            }
            $hasLetter = 1 === preg_match('/\p{L}/u', $token);
            $isAcronym = $hasLetter && mb_strlen($token) >= 2 && $token === mb_strtoupper($token) && 1 !== preg_match('/^\d/u', $token);
            $mixed = $hasLetter && 1 === preg_match('/\d/u', $token);
            $capitalized = $hasLetter && 1 === preg_match('/^\p{Lu}/u', $token);
            $common = \in_array($this->normalize($token), self::COMMON_WORDS, true);
            if ($isAcronym && !$common || $mixed || ($capitalized && !$sentenceStart && !$common)) {
                $elements[] = $token;
            }
            $sentenceStart = false;
        }

        return array_values(array_unique($elements));
    }

    /**
     * Renvoie les fiches probablement utilisées pour la réponse (banc d'essai), d'après leurs termes
     * DISTINCTIFS : mots de 5 lettres au moins et éléments contrôlés (nombres, noms, sigles) de la fiche, hors
     * mots vides et hors termes déjà présents dans le contexte sans fiches (socle et résumé du site : nom de
     * Guillaume, métier…), qui ne prouvent rien. Une fiche est retenue si au moins MIN_DISTINCTIVE_MATCHES de
     * ses termes (tous s'il y en a moins) et au moins MIN_DISTINCTIVE_SHARE de ses termes se retrouvent dans
     * la réponse.
     *
     * Liens :
     * - Appelée par : ChatAssistant::test()
     * - Voir aussi : ChatContextBuilder::buildSystemPrompt() (contexte sans fiches)
     *
     * Exemple : la fiche « … conçu et développé de manière autonome, avec l'assistance d'outils d'IA » n'est pas
     * retenue pour une réponse qui ne partage que « Guillaume » et « outils ».
     *
     * @param list<AiKnowledgeEntry> $entries     fiches actives envoyées au modèle
     * @param string                 $baseContext contexte envoyé sans les fiches (socle et résumé du site)
     *
     * @return list<AiKnowledgeEntry> fiches probablement utilisées, dans l'ordre reçu ; liste vide sinon
     */
    public function findUsedEntries(string $answer, array $entries, string $baseContext): array
    {
        $answerTerms = array_flip($this->terms($answer));
        $commonTerms = array_flip($this->terms($baseContext));
        $used = [];
        foreach ($entries as $entry) {
            $distinctive = array_values(array_filter(
                $this->terms($entry->getContent()),
                static fn (string $term): bool => !isset($commonTerms[$term]),
            ));
            if ([] === $distinctive) {
                continue;
            }
            $matches = \count(array_filter($distinctive, static fn (string $term): bool => isset($answerTerms[$term])));
            if ($matches >= min(self::MIN_DISTINCTIVE_MATCHES, \count($distinctive)) && $matches / \count($distinctive) >= self::MIN_DISTINCTIVE_SHARE) {
                $used[] = $entry;
            }
        }

        return $used;
    }

    /**
     * Renvoie les termes normalisés d'un texte pour repérer les fiches utilisées : mots de MIN_KEYWORD_LENGTH
     * lettres au moins hors mots vides, et éléments contrôlés quelle que soit leur longueur.
     *
     * @return list<string> termes sans doublon
     */
    private function terms(string $text): array
    {
        $terms = array_map(fn (string $element): string => $this->normalize($element), $this->extractElements($text));
        foreach (preg_split('/[^\p{L}\p{N}.\-]+/u', $this->normalize($text)) ?: [] as $word) {
            if (mb_strlen($word) >= self::MIN_KEYWORD_LENGTH && !\in_array($word, self::STOP_WORDS, true)) {
                $terms[] = $word;
            }
        }

        return array_values(array_unique(array_filter($terms, static fn (string $term): bool => '' !== $term)));
    }

    /**
     * Normalise un texte pour la comparaison : sans accents, minuscules, apostrophes et tirets en espaces,
     * séparateurs de milliers retirés, virgule décimale en point, espaces regroupés.
     */
    private function normalize(string $text): string
    {
        $text = $this->joinThousands($text);
        $text = (string) preg_replace('/(?<=\d),(?=\d)/u', '.', $text);
        $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);
        $text = mb_strtolower((string) preg_replace('/\p{Mn}/u', '', false === $decomposed ? $text : $decomposed));
        // Tiret entre deux chiffres gardé (« 15-140 ») ; ailleurs remplacé par une espace (« Saint-Étienne »)
        $text = (string) preg_replace('/(?<!\d)-|-(?!\d)/u', ' ', $text);
        $text = str_replace(['’', "'", '«', '»', '"', '–', '—', '/', '(', ')', ',', ';', ':', '!', '?', '…'], ' ', $text);
        $text = (string) preg_replace('/\.(?!\d)/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Ajoute au contexte normalisé la valeur entière des montants en milliers (« XX à YY k€ » → « 70000 75000 ») :
     * le modèle reformule souvent une fourchette en euros (« XX 000 € et XX 000 € »).
     *
     * Liens :
     * - Appelée par : findUnsupported() (contexte seulement, jamais la réponse)
     *
     * @param string $context contexte déjà normalisé (normalize())
     *
     * @return string contexte suivi des montants développés ; inchangé s'il n'y a aucun « k » ou « k€ »
     */
    private function expandThousandUnits(string $context): string
    {
        preg_match_all('/(?<![\d.\-])(\d+(?:\.\d+)?)(?:\s*(?:a|-)\s*(\d+(?:\.\d+)?))?\s*k(?:€|euros?)?(?![\p{L}\p{N}])/u', $context, $matches, \PREG_SET_ORDER);
        $amounts = [];
        foreach ($matches as $match) {
            foreach (array_filter([$match[1], $match[2] ?? '']) as $value) {
                $amounts[] = (string) (int) round((float) $value * 1000);
            }
        }

        return [] === $amounts ? $context : $context.' '.implode(' ', array_unique($amounts));
    }

    /**
     * Retire les séparateurs de milliers (« 5 000 000 », « 5 000 » avec espace insécable) : « 5000000 ».
     */
    private function joinThousands(string $text): string
    {
        return (string) preg_replace('/(?<=\d)[ \x{202F}\x{A0}](?=\d{3}(?!\d))/u', '', $text);
    }
}
