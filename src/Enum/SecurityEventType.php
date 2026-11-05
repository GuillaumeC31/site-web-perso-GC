<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Types d'évènements enregistrés dans le journal des connexions à l'admin.
 *
 * Liens :
 * - Utilisé par : SecurityEvent (colonne type), SecurityEventLogger, templates/admin/security_event/index.html.twig
 */
enum SecurityEventType: string
{
    case PasswordSuccess = 'password_success';
    case PasswordFailure = 'password_failure';
    case KeySuccess = 'key_success';
    case KeyFailure = 'key_failure';
    case KeyEnrolled = 'key_enrolled';
    case KeyRemoved = 'key_removed';
    case EnrollmentLinkUsed = 'enrollment_link_used';
    case EnrollmentLinkRejected = 'enrollment_link_rejected';
    case SessionExpired = 'session_expired';
    case Logout = 'logout';

    /**
     * Limite de débit atteinte (connexion admin, clé, formulaires publics, assistant) ; l'identifiant
     * indique la limite (ex. « contact_form »), jamais l'adresse saisie par un visiteur.
     */
    case RateLimited = 'rate_limited';

    /**
     * Décision prise à distance (bouton Telegram, lien Discord) ; l'identifiant indique le canal, l'action et
     * la demande (« telegram approve #12 »), jamais le jeton ni l'adresse du lien.
     */
    case RemoteDecision = 'remote_decision';

    /**
     * Refus de sécurité de la validation à distance (secret Telegram absent ou faux, autre conversation,
     * jeton inconnu, expiré ou déjà utilisé, code PIN faux, lien bloqué) ; l'identifiant indique le canal et le
     * motif (« discord pin_invalid #12 »).
     */
    case RemoteDecisionRejected = 'remote_decision_rejected';

    /** Révocation de tous les appareils de confiance (lien vers l'admin sur le cœur du pied de page). */
    case TrustedDevicesRevoked = 'trusted_devices_revoked';

    /**
     * Turnstile en erreur de CONFIGURATION (v2.0.3) : clé secrète refusée, clé de site invalide, nom d'hôte non
     * autorisé… (jamais les refus normaux des robots). L'identifiant indique l'origine et le code (« serveur
     * invalid-input-secret », « navigateur 110200 »), jamais le jeton.
     */
    case TurnstileConfigError = 'turnstile_config_error';

    /**
     * Renvoie la clé de traduction du libellé affiché dans le journal.
     *
     * Liens :
     * - Appelée par : templates/admin/security_event/index.html.twig
     *
     * @return string ex. « security_event.type.password_success »
     */
    public function translationKey(): string
    {
        return 'security_event.type.'.$this->value;
    }

    /**
     * Indique si l'évènement signale un échec ou un refus (mis en évidence dans le journal).
     *
     * Liens :
     * - Appelée par : templates/admin/security_event/index.html.twig
     */
    public function isFailure(): bool
    {
        return \in_array($this, [self::PasswordFailure, self::KeyFailure, self::EnrollmentLinkRejected, self::RateLimited, self::RemoteDecisionRejected, self::TurnstileConfigError], true);
    }
}
