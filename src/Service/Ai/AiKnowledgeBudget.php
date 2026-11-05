<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\AiKnowledgeEntry;
use App\Repository\AiKnowledgeEntryRepository;
use App\Repository\SiteSettingsRepository;

/**
 * Taille des fiches du « Cerveau de l'IA » en jetons, et son effet sur le nombre de questions possibles par
 * mois : tout le contexte (socle, résumé du site, fiches actives) est envoyé à CHAQUE question.
 *
 * Les jetons sont estimés (environ 1 jeton pour 3,5 caractères en français avec le tokenizer de Mistral ;
 * mesure réelle : php bin/console app:ai:check --measure). Plafond de taille des fiches actives : réglable dans
 * Paramètres (6 000 jetons par défaut).
 *
 * Liens :
 * - Utilisé par : Admin\AiKnowledgeController (compteurs, avertissement, blocage au-delà du plafond),
 *   App\Service\Dashboard\DashboardMetrics
 * - Utilise : ChatContextBuilder (contexte sans fiches), AiKnowledgeEntryRepository, SiteSettingsRepository
 * - Testé par : tests/Service/Ai/AiKnowledgeBudgetTest
 */
final readonly class AiKnowledgeBudget
{
    /** Caractères par jeton (estimation pour du français). */
    public const CHARS_PER_TOKEN = 3.5;

    /** Jetons d'une question type (environ 120 caractères). */
    public const QUESTION_TOKENS = 35;

    /** Baisse du nombre de questions par mois à partir de laquelle la page affiche un avertissement (%). */
    public const WARNING_PERCENT = 10;

    /**
     * Reçoit les dépendances.
     */
    public function __construct(
        private ChatContextBuilder $contextBuilder,
        private AiKnowledgeEntryRepository $entryRepository,
        private SiteSettingsRepository $settingsRepository,
    ) {
    }

    /**
     * Estime les jetons d'un texte.
     *
     * Exemple : estimateTokens(str_repeat('a', 350)) renvoie 100.
     *
     * @return int jetons estimés (0 pour un texte vide)
     */
    public static function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen(trim($text)) / self::CHARS_PER_TOKEN);
    }

    /**
     * Estime les jetons qu'une fiche ajoute au contexte (titre, catégorie et contenu).
     *
     * Liens :
     * - Appelée par : getSummary(), wouldExceedLimit()
     */
    public function entryTokens(AiKnowledgeEntry $entry): int
    {
        return self::estimateTokens($this->contextBuilder->formatEntry($entry));
    }

    /**
     * Calcule les compteurs de la page : jetons par fiche, total des fiches actives, plafond de taille, coût
     * d'une question et nombre de questions possibles par mois avec et sans les fiches.
     *
     * Liens :
     * - Appelée par : Admin\AiKnowledgeController::index()
     *
     * @return array{
     *     entries: array<int, int>,
     *     total: int,
     *     limit: int,
     *     over_limit: bool,
     *     base_tokens: int,
     *     answer_tokens: int,
     *     question_cost: int,
     *     monthly_limit: int,
     *     questions_without: int,
     *     questions_with: int,
     *     reduction_percent: int,
     *     warning: bool
     * }
     */
    public function getSummary(): array
    {
        $settings = $this->settingsRepository->getOrCreate();
        $entries = [];
        $total = 0;
        foreach ($this->entryRepository->findOrdered() as $entry) {
            $tokens = $this->entryTokens($entry);
            $entries[(int) $entry->getId()] = $tokens;
            if ($entry->isActive()) {
                $total += $tokens;
            }
        }
        // Contexte sans fiches : socle et résumé du site, plus une question et la réponse au plus
        $base = self::estimateTokens($this->contextBuilder->buildSystemPrompt('Mistral AI', 'modele', false)) + self::QUESTION_TOKENS;
        $answer = ChatAssistant::MAX_TOKENS;
        $monthly = $settings->getAiMonthlyTokenLimit();
        $without = intdiv($monthly, max(1, $base + $answer));
        $with = intdiv($monthly, max(1, $base + $answer + $total));
        $reduction = $without > 0 ? (int) round(100 * ($without - $with) / $without) : 0;

        return [
            'entries' => $entries,
            'total' => $total,
            'limit' => $settings->getAiKnowledgeTokenLimit(),
            'over_limit' => $total > $settings->getAiKnowledgeTokenLimit(),
            'base_tokens' => $base,
            'answer_tokens' => $answer,
            'question_cost' => $base + $answer + $total,
            'monthly_limit' => $monthly,
            'questions_without' => $without,
            'questions_with' => $with,
            'reduction_percent' => $reduction,
            'warning' => $reduction >= self::WARNING_PERCENT,
        ];
    }

    /**
     * Indique si les fiches actives dépasseraient le plafond de taille avec la fiche enregistrée telle quelle.
     *
     * Liens :
     * - Appelée par : Admin\AiKnowledgeController (blocage à l'enregistrement)
     *
     * @param AiKnowledgeEntry $entry fiche sur le point d'être enregistrée (nouvelle ou modifiée)
     *
     * @return int|null total des fiches actives après l'enregistrement s'il dépasse le plafond ; null sinon
     */
    public function exceededTotal(AiKnowledgeEntry $entry): ?int
    {
        if (!$entry->isActive()) {
            return null;
        }
        $total = $this->entryTokens($entry);
        foreach ($this->entryRepository->findActiveOrdered() as $other) {
            if ($other !== $entry && $other->getId() !== $entry->getId()) {
                $total += $this->entryTokens($other);
            }
        }

        return $total > $this->settingsRepository->getOrCreate()->getAiKnowledgeTokenLimit() ? $total : null;
    }
}
