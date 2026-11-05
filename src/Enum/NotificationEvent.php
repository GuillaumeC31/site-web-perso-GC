<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Événement notifié à Guillaume (CDC §7) ; les canaux de chaque événement se règlent dans l'admin
 * (Communication › Notifications) et l'e-mail part à l'adresse de sa catégorie (category()).
 *
 * Événements « Général du site » (décision du 2026-10-29) : alertes envoyées au plus une fois par jour et par
 * type par app:communication:alerts (sécurité, e-mails en échec, tâches planifiées, IA), et maintenance activée
 * ou désactivée (à l'enregistrement de Paramètres).
 *
 * Liens :
 * - Utilisé par : SiteSettings (canaux par événement), NotificationDispatcher, Admin\CommunicationController,
 *   Command\CommunicationAlertsCommand
 */
enum NotificationEvent: string
{
    /** Nouvelle demande d'accès à l'espace recruteur. */
    case AccessRequest = 'access_request';

    /** Nouveau message du formulaire de contact, ou question d'un recruteur posée depuis l'assistant. */
    case Contact = 'contact';

    /** Signal d'intérêt explicite : clic sur « Je suis intéressé » (une fois par période, InterestSignalNotifier). */
    case Interest = 'interest';

    /**
     * Activité d'un recruteur validé (v2.0.3) : première connexion, téléchargement du CV complet ; une fois par jour
     * au plus, le reste dans le récapitulatif quotidien. Jamais présentée comme un signal d'intérêt.
     */
    case RecruiterActivity = 'recruiter_activity';

    /** Demande de prolongation de l'accès. */
    case Extension = 'extension';

    /** Un recruteur a supprimé ses données depuis son espace. */
    case DataDeletion = 'data_deletion';

    /** Alerte de sécurité : échecs de connexion à l'admin, limites atteintes, refus de la validation à distance. */
    case SecurityAlert = 'security_alert';

    /** E-mails non envoyés (serveur d'envoi en panne) : Discord et Telegram par défaut, l'e-mail risquant d'échouer aussi. */
    case FailedEmail = 'failed_email';

    /** Sauvegarde ou tâche planifiée en retard. */
    case TaskLate = 'task_late';

    /** Assistant IA : plafond atteint ou modèle retiré chez Mistral AI. */
    case AiAlert = 'ai_alert';

    /** Mode maintenance activé ou désactivé. */
    case Maintenance = 'maintenance';

    /** Plafond quotidien des e-mails aux personnes non validées atteint (une fois par jour, Mail\UnvalidatedEmailCap). */
    case EmailCap = 'email_cap';

    /**
     * Turnstile mal configuré (v2.0.3) : erreurs de configuration répétées (clé secrète refusée, clé de site invalide,
     * nom d'hôte non autorisé), formulaires publics bloqués ; une fois par jour au plus (app:communication:alerts).
     */
    case TurnstileAlert = 'turnstile_alert';

    /**
     * Renvoie la clé de traduction du libellé.
     *
     * Liens :
     * - Appelée par : Form\CommunicationNotificationsType, NotificationDispatcher
     */
    public function translationKey(): string
    {
        return 'notification.event.'.$this->value;
    }

    /**
     * Renvoie la catégorie de l'événement : l'e-mail part à l'adresse de cette catégorie.
     *
     * Liens :
     * - Appelée par : NotificationDispatcher::send(), Mail\MailRecipients, templates/admin/communication/notifications.html.twig
     */
    public function category(): MailCategory
    {
        return match ($this) {
            self::AccessRequest, self::Contact, self::Interest, self::RecruiterActivity, self::Extension, self::DataDeletion => MailCategory::Recruitment,
            default => MailCategory::General,
        };
    }

    /**
     * Renvoie les canaux par défaut d'un événement qui n'a jamais été réglé.
     *
     * Liens :
     * - Appelée par : SiteSettings::__construct(), SiteSettings::getChannels() (événement ajouté après coup)
     *
     * @return list<NotificationChannel>
     */
    public function defaultChannels(): array
    {
        return self::FailedEmail === $this
            ? [NotificationChannel::Discord, NotificationChannel::Telegram]
            : NotificationChannel::cases();
    }
}
