<?php

declare(strict_types=1);

namespace App\Service\Content;

use App\Entity\RecruitmentArgument;
use App\Repository\RecruitmentArgumentRepository;
use App\Repository\SiteSettingsRepository;

/**
 * Seule source des arguments « Pourquoi me recruter » montrés au public et à l'assistant IA.
 *
 * Règle (v2.0.2) : le bloc ne s'affiche que si son interrupteur général est activé ET qu'au moins un argument est
 * marqué « Afficher ». Un argument masqué est un brouillon : jamais sur l'accueil, jamais dans le contexte de l'IA.
 *
 * Liens :
 * - Utilisée par : HomeController::index(), Ai\ChatContextBuilder::buildSiteSummary(),
 *   Admin\RecruitmentArgumentController::preview()
 * - Utilise : RecruitmentArgumentRepository, SiteSettingsRepository (SiteSettings::isRecruitmentBlockEnabled())
 */
final readonly class RecruitmentArguments
{
    /**
     * Reçoit les dépôts.
     *
     * @param RecruitmentArgumentRepository $argumentRepository arguments
     * @param SiteSettingsRepository        $settingsRepository interrupteur général du bloc
     */
    public function __construct(
        private RecruitmentArgumentRepository $argumentRepository,
        private SiteSettingsRepository $settingsRepository,
    ) {
    }

    /**
     * Renvoie les arguments affichés sur l'accueil : vide si le bloc est coupé ou si aucun argument n'est visible.
     *
     * Liens :
     * - Appelée par : HomeController::index(), Ai\ChatContextBuilder::buildSiteSummary()
     *
     * @return list<RecruitmentArgument>
     */
    public function getDisplayed(): array
    {
        if (!$this->settingsRepository->getOrCreate()->isRecruitmentBlockEnabled()) {
            return [];
        }

        return $this->argumentRepository->findVisibleOrdered();
    }

    /**
     * Renvoie les arguments marqués « Afficher », que le bloc soit activé ou non (aperçu de l'admin).
     *
     * Liens :
     * - Appelée par : Admin\RecruitmentArgumentController::preview()
     *
     * @return list<RecruitmentArgument>
     */
    public function getVisible(): array
    {
        return $this->argumentRepository->findVisibleOrdered();
    }

    /**
     * Texte des arguments affichés pour l'assistant IA (une ligne par argument) ; vide si rien n'est affiché.
     *
     * Liens :
     * - Appelée par : Ai\ChatContextBuilder::buildSiteSummary()
     */
    public function toAssistantText(): string
    {
        return implode("\n", array_map(
            static fn (RecruitmentArgument $argument): string => '- '.$argument->getTitle().' : '.$argument->getText(),
            $this->getDisplayed(),
        ));
    }
}
