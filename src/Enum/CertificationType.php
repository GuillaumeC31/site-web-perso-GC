<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Nature d'une certification du CV.
 *
 * Liens :
 * - Utilisé par : Certification (colonne type), CertificationType (formulaire)
 */
enum CertificationType: string
{
    /** Certification ou attestation (ex. manipulation des fluides frigorigènes). */
    case Certification = 'certification';

    /** Habilitation à durée de validité (ex. habilitation électrique, SST, CACES). */
    case Clearance = 'clearance';

    /**
     * Renvoie la clé de traduction du libellé.
     *
     * Liens :
     * - Appelée par : App\Form\CertificationType (choix), templates de l'admin
     */
    public function translationKey(): string
    {
        return 'certification.type.'.$this->value;
    }
}
