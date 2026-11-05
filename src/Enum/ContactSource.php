<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Origine d'un message reçu (filtre « type » de la page Messages) : formulaire de contact public, ou question d'un
 * recruteur posée depuis l'assistant de son espace.
 *
 * Liens :
 * - Utilisé par : ContactMessage, ContactController (formulaire public), RecruiterQuestionController (question),
 *   Admin\ContactMessageController (filtre), ContactMessageRepository
 */
enum ContactSource: string
{
    case ContactForm = 'contact_form';
    case RecruiterQuestion = 'recruiter_question';

    /**
     * Renvoie la clé de traduction du libellé.
     *
     * Liens :
     * - Appelée par : templates/admin/contact_message/*
     */
    public function translationKey(): string
    {
        return 'admin.contact_message.source.'.$this->value;
    }
}
