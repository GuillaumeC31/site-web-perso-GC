<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Catégorie d'adresse de réception (page Communication › E-mails, décision du 2026-10-29) : chaque événement
 * notifié par e-mail part à l'adresse de sa catégorie.
 *
 * - Recrutement : demandes d'accès, signaux d'intérêt, questions de l'assistant, messages du formulaire de
 *   contact, suppressions de données ; c'est aussi l'adresse de réponse donnée aux recruteurs VALIDÉS ;
 * - Général du site : résumé hebdomadaire, e-mails en échec, alertes de sécurité, tâches planifiées, IA,
 *   maintenance, tout événement technique ;
 * - Calculatrice : futur formulaire de suggestions (à venir) ; vide : repli sur « Général du site ».
 *
 * Liens :
 * - Utilisé par : NotificationEvent::category(), Mail\MailRecipients, Admin\CommunicationController, EmailLog
 */
enum MailCategory: string
{
    case Recruitment = 'recruitment';
    case General = 'general';
    case Calculator = 'calculator';

    /**
     * Renvoie la clé de traduction du libellé.
     *
     * Liens :
     * - Appelée par : templates/admin/communication/*.html.twig
     */
    public function translationKey(): string
    {
        return 'admin.communication.category.'.$this->value;
    }
}
