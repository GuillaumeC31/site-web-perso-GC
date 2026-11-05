<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Type d'e-mail envoyé par le site, avec ses textes d'origine (objet, phrase d'introduction, signature) : ces
 * trois textes se modifient dans Communication › E-mails › Contenu des e-mails (EmailText) ; la structure, les
 * liens de connexion, les boutons et les mentions obligatoires restent fixes dans les gabarits.
 *
 * Variables permises dans les textes : {nom}, {entreprise}, {delai} (Mail\EmailTexts::VARIABLES).
 *
 * Règle de confidentialité (décision du 2026-10-29) : seuls les e-mails aux recruteurs VALIDÉS portent une
 * adresse de réponse (isForValidatedRecruiter()) ; les autres disent d'utiliser le formulaire de contact.
 *
 * Liens :
 * - Utilisé par : Mail\EmailTexts, Mail\MailFactory, RecruiterMailer, NotificationDispatcher,
 *   DashboardWeeklySummaryCommand, Admin\CommunicationController, EmailLog (type)
 */
enum EmailType: string
{
    case RequestReceived = 'request_received';
    case RequestPending = 'request_pending';
    case LoginLink = 'login_link';
    case Approval = 'approval';
    case Renewal = 'renewal';
    case Refusal = 'refusal';
    case ExpiryReminder = 'expiry_reminder';
    case Notification = 'notification';
    case DashboardSummary = 'dashboard_summary';
    case Test = 'test';

    /**
     * Renvoie le gabarit Twig (sans extension, sous templates/email/).
     *
     * Liens :
     * - Appelée par : Mail\MailFactory::createTyped(), Admin\CommunicationController (aperçu)
     */
    public function template(): string
    {
        return match ($this) {
            self::RequestReceived => 'recruiter/request_received',
            self::RequestPending => 'recruiter/request_pending',
            self::LoginLink => 'recruiter/login_link',
            self::Approval, self::Renewal => 'recruiter/approval',
            self::Refusal => 'recruiter/refusal',
            self::ExpiryReminder => 'recruiter/expiry_reminder',
            self::Notification => 'notification',
            self::DashboardSummary => 'admin/dashboard_summary',
            self::Test => 'test',
        };
    }

    /**
     * Indique si l'e-mail part à un recruteur dont l'accès est validé : seul cas où l'adresse « Recrutement »
     * est donnée en adresse de réponse.
     *
     * Liens :
     * - Appelée par : Mail\MailFactory::createTyped()
     */
    public function isForValidatedRecruiter(): bool
    {
        return \in_array($this, [self::LoginLink, self::Approval, self::Renewal, self::ExpiryReminder], true);
    }

    /**
     * Indique si l'e-mail part à une personne dont la demande n'est pas validée (accusé, demande en cours, refus) :
     * aucune adresse de réponse, mention « utilisez le formulaire de contact du site ».
     *
     * Liens :
     * - Appelée par : Mail\MailFactory::createTyped(), templates/email/_layout.html.twig (pied de l'e-mail)
     */
    public function isForUnvalidatedRequest(): bool
    {
        return \in_array($this, [self::RequestReceived, self::RequestPending, self::Refusal], true);
    }

    /**
     * Indique si l'e-mail part à Guillaume (notification, résumé, test), et non à un recruteur.
     */
    public function isForAdmin(): bool
    {
        return \in_array($this, [self::Notification, self::DashboardSummary, self::Test], true);
    }

    /**
     * Renvoie la catégorie de l'e-mail pour l'historique des envois (null : celle de l'événement ou de
     * l'adresse testée, connue seulement à l'envoi).
     *
     * Liens :
     * - Appelée par : Service\Mail\ResilientMailer (historique), Admin\CommunicationController
     */
    public function category(): ?MailCategory
    {
        return match ($this) {
            self::Notification, self::Test => null,
            self::DashboardSummary => MailCategory::General,
            default => MailCategory::Recruitment,
        };
    }

    /**
     * Renvoie la nature de l'e-mail pour l'envoi résistant aux pannes (ResilientMailer::KIND_*, journal et renvoi).
     *
     * Liens :
     * - Appelée par : RecruiterMailer, FailedEmailResender, ResilientMailer::typeOfKind()
     */
    public function kind(): string
    {
        return match ($this) {
            self::Notification => 'notification',
            self::DashboardSummary => 'dashboard_summary',
            self::Test => 'test',
            default => 'recruiter_'.$this->value,
        };
    }

    /**
     * Renvoie la clé de traduction du libellé (« Accusé de réception »…).
     */
    public function translationKey(): string
    {
        return 'admin.communication.email_type.'.$this->value;
    }

    /**
     * Renvoie l'objet d'origine. Notifications : préfixe placé devant le titre de l'événement ; résumé hebdomadaire :
     * suivi de la date (« — 02/11/2026 »).
     *
     * Liens :
     * - Appelée par : Mail\EmailTexts::resolve(), « Rétablir le texte d'origine »
     */
    public function defaultSubject(): string
    {
        return match ($this) {
            self::RequestReceived => 'Votre demande d\'accès à l\'espace recruteur est bien reçue',
            self::RequestPending => 'Votre demande d\'accès est déjà en cours d\'examen',
            self::LoginLink => 'Votre lien de connexion à l\'espace recruteur',
            self::Approval => 'Votre accès à l\'espace recruteur est validé',
            self::Renewal => 'Votre accès à l\'espace recruteur est prolongé',
            self::Refusal => 'Votre demande d\'accès à l\'espace recruteur',
            self::ExpiryReminder => 'Votre accès à l\'espace recruteur arrive à échéance',
            self::Notification => '[Site]',
            self::DashboardSummary => 'Résumé hebdomadaire du site',
            self::Test => 'E-mail de test du site',
        };
    }

    /**
     * Renvoie la phrase d'introduction d'origine (après « Bonjour {nom}, » pour les recruteurs).
     */
    public function defaultIntro(): string
    {
        return match ($this) {
            self::RequestReceived => 'Merci pour votre demande d\'accès à mon espace recruteur : elle est bien reçue. Je la valide {delai} ; vous recevrez alors un e-mail avec un lien de connexion sécurisé.',
            self::RequestPending => 'Votre demande d\'accès à mon espace recruteur est déjà en cours d\'examen : inutile de la renvoyer. Réponse {delai} ; vous recevrez alors un e-mail avec un lien de connexion sécurisé.',
            self::LoginLink => 'Voici votre lien de connexion à l\'espace recruteur de mon site.',
            self::Approval => 'Merci pour votre demande : votre accès à l\'espace recruteur est validé. Vous y trouverez mes coordonnées directes, mon CV complet et le détail de mes réalisations.',
            self::Renewal => 'Votre accès à l\'espace recruteur est prolongé.',
            self::Refusal => 'Merci pour l\'intérêt porté à mon profil. Je ne peux pas donner suite à votre demande d\'accès à mon espace recruteur.',
            self::ExpiryReminder => 'Votre accès à l\'espace recruteur de mon site arrive à échéance. Si vous souhaitez le garder, demandez une prolongation : je la valide rapidement.',
            self::Notification => '',
            self::DashboardSummary => 'Résumé de la semaine (chiffres seulement, aucune donnée personnelle).',
            self::Test => 'Si vous lisez ce message, l\'envoi des e-mails du site fonctionne.',
        };
    }

    /**
     * Renvoie la signature d'origine.
     */
    public function defaultSignature(): string
    {
        return match ($this) {
            self::RequestReceived, self::RequestPending, self::Approval, self::Renewal => "Au plaisir d'échanger,\nGuillaume Couget",
            self::LoginLink => 'Guillaume Couget',
            self::Refusal => "Cordialement,\nGuillaume Couget",
            self::ExpiryReminder => "Merci de l'intérêt porté à mon profil,\nGuillaume Couget",
            self::Notification, self::DashboardSummary, self::Test => '',
        };
    }
}
