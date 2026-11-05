<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Rubrique du parcours où une expérience est affichée, dans l'ordre du CV de référence.
 *
 * Liens :
 * - Utilisé par : Experience (colonne career_section), ExperienceType, CvContentProvider (regroupement),
 *   templates du CV (page /cv, PDF, espace recruteur)
 */
enum CareerSection: string
{
    /** « Expériences professionnelles » : postes récents, détaillés. */
    case Professional = 'professional';

    /** « Expérience fondatrice » : postes successifs regroupés sous une même entreprise. */
    case Foundation = 'foundation';

    /** « Parcours terrain » : premiers postes, une ligne de description chacun (bloc dépliable). */
    case Field = 'field';

    /**
     * Renvoie la clé de traduction du titre de la rubrique.
     *
     * Liens :
     * - Appelée par : ExperienceType (choix), templates du CV et de l'admin
     */
    public function translationKey(): string
    {
        return 'cv.career_section.'.$this->value;
    }
}
