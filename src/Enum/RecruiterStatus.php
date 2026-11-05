<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Statut d'un recruteur (demande d'accès à l'espace recruteur, CDC §7).
 *
 * Liens :
 * - Utilisé par : Recruiter, RecruiterAccessManager, Admin\RecruiterController, RecruiterUserChecker
 */
enum RecruiterStatus: string
{
    /** Demande reçue, à valider dans l'admin. */
    case Pending = 'pending';

    /** Accès validé (90 jours, renouvelable). */
    case Approved = 'approved';

    /** Demande refusée. */
    case Refused = 'refused';

    /** Accès retiré par l'admin. */
    case Revoked = 'revoked';

    /** Accès arrivé à échéance (commande app:recruiter:daily). */
    case Expired = 'expired';

    /**
     * Renvoie la clé de traduction du libellé.
     *
     * Liens :
     * - Appelée par : templates/admin/recruiter/*
     */
    public function translationKey(): string
    {
        return 'admin.recruiter.status.'.$this->value;
    }
}
