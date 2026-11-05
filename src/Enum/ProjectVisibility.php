<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Visibilité d'un projet publié (CDC §6).
 *
 * Liens :
 * - Utilisé par : Project (colonne visibility), ProjectType (formulaire), ProjectRepository
 * - Voir aussi : espace recruteur (lot 6), qui affichera les projets « recruteurs seulement »
 */
enum ProjectVisibility: string
{
    /** Visible de tous sur le site public. */
    case Public = 'public';

    /** Réservé aux recruteurs validés : jamais affiché sur le site public. */
    case Recruiter = 'recruiter';

    /**
     * Renvoie la clé de traduction du libellé.
     *
     * Liens :
     * - Appelée par : App\Form\ProjectType (choix), templates de l'admin
     */
    public function translationKey(): string
    {
        return 'project.visibility.'.$this->value;
    }
}
