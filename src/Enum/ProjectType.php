<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Type d'un projet (CDC §6) : deux familles bien séparées sur le site.
 *
 * Liens :
 * - Utilisé par : Project (colonne type), ProjectType (formulaire), ProjectController (onglets)
 */
enum ProjectType: string
{
    /** Étude de cas professionnelle (plan imposé), affichée en premier. */
    case CaseStudy = 'case_study';

    /** Labo : projet personnel (ESP32, IoT, impression 3D…), affiché en second. */
    case Lab = 'lab';

    /**
     * Renvoie la clé de traduction du libellé.
     *
     * Liens :
     * - Appelée par : App\Form\ProjectType (choix), templates publics et de l'admin
     */
    public function translationKey(): string
    {
        return 'project.type.'.$this->value;
    }

    /**
     * Renvoie la valeur utilisée dans l'URL des onglets (ex. /projets?type=labo).
     *
     * Liens :
     * - Appelée par : templates/project/index.html.twig, fromUrlValue()
     */
    public function urlValue(): string
    {
        return match ($this) {
            self::CaseStudy => 'etudes-de-cas',
            self::Lab => 'labo',
        };
    }

    /**
     * Retrouve le type à partir de sa valeur d'URL (null si inconnue).
     *
     * Liens :
     * - Appelée par : ProjectController::index()
     */
    public static function fromUrlValue(?string $value): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->urlValue() === $value) {
                return $case;
            }
        }

        return null;
    }
}
