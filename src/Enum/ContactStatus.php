<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Lecture d'un message dans l'admin : non lu ou lu. « Répondu » et « archivé » sont deux dates à part
 * (ContactMessage::getRepliedAt(), getArchivedAt()) : un message peut être lu, répondu et archivé à la fois
 * (décision du 2026-10-29 ; l'ancien statut « Traité » est devenu « Répondu », migration Version20261029162138).
 *
 * Liens :
 * - Utilisé par : ContactMessage, Admin\ContactMessageController, ContactMessageRepository
 */
enum ContactStatus: string
{
    case New = 'new';
    case Read = 'read';

    /**
     * Renvoie la clé de traduction du libellé.
     *
     * Liens :
     * - Appelée par : templates/admin/contact_message/*
     */
    public function translationKey(): string
    {
        return 'admin.contact_message.status.'.$this->value;
    }
}
