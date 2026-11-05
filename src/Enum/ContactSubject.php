<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Objet d'un message du formulaire de contact (TEXTES_SITE § 10).
 *
 * Liens :
 * - Utilisé par : ContactMessage, ContactType
 */
enum ContactSubject: string
{
    case Recruitment = 'recruitment';
    case Technical = 'technical';
    case Other = 'other';

    /**
     * Renvoie la clé de traduction du libellé.
     *
     * Liens :
     * - Appelée par : ContactType (choix), templates de l'admin
     */
    public function translationKey(): string
    {
        return 'contact.subject.'.$this->value;
    }
}
