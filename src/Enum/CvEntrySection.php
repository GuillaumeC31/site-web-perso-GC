<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Rubrique « liste » du CV (après le parcours), dans l'ordre du CV de référence.
 *
 * Liens :
 * - Utilisé par : CvEntry (colonne section), CvEntryType, CvEntryRepository (ordre d'affichage),
 *   CvContentProvider, templates du CV
 */
enum CvEntrySection: string
{
    /** « Outils techniques au service des équipes » : puces sans titre. */
    case Tools = 'tools';

    /** « Expertises métiers » : domaine en gras + description. */
    case Expertise = 'expertise';

    /** « Logiciels et environnements techniques » : famille en gras + liste. */
    case Software = 'software';

    /** « Savoir-être » : trois blocs titrés. */
    case SoftSkill = 'soft_skill';

    /** « Centres d'intérêt » : thème en gras + description (en fin de CV). */
    case Interest = 'interest';

    /**
     * Renvoie la clé de traduction du titre de la rubrique.
     *
     * Liens :
     * - Appelée par : CvEntryType (choix), templates du CV et de l'admin
     */
    public function translationKey(): string
    {
        return 'cv.section.'.$this->value;
    }

    /**
     * Renvoie le rang de la rubrique dans le CV (ordre de déclaration des cas).
     *
     * Liens :
     * - Appelée par : CvEntryRepository::findAllOrdered()
     */
    public function rank(): int
    {
        return (int) array_search($this, self::cases(), true);
    }
}
