<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Événement d'un recruteur validé (v2.0.3, point 7) :
 * - SIGNAL D'INTÉRÊT explicite : clic sur « Je suis intéressé » seulement (POST avec jeton CSRF), notifié tout de
 *   suite sous le titre « Signal d'intérêt d'un recruteur », une fois par période (InterestSignalNotifier::COOLDOWN) ;
 * - ACTIVITÉ : première connexion, téléchargement du CV complet ; jamais présentée comme un signal d'intérêt,
 *   notifiée « Activité d'un recruteur » (NotificationEvent::RecruiterActivity), une fois par jour au plus.
 *
 * Liens :
 * - Utilisé par : RecruiterEvent, InterestSignalNotifier, RecruiterAreaController
 */
enum RecruiterEventType: string
{
    /** Première connexion après la validation. */
    case FirstLogin = 'first_login';

    /** Bouton « Je suis intéressé ». */
    case Interested = 'interested';

    /** Téléchargement du CV complet (PDF). */
    case CvDownload = 'cv_download';

    /**
     * Indique si l'événement est le signal d'intérêt explicite (bouton « Je suis intéressé ») ; sinon, c'est de
     * l'activité (connexion, téléchargement).
     *
     * Liens :
     * - Appelée par : InterestSignalNotifier
     */
    public function isExplicitSignal(): bool
    {
        return self::Interested === $this;
    }

    /**
     * Renvoie la clé de traduction du libellé.
     *
     * Liens :
     * - Appelée par : InterestSignalNotifier, templates/admin/recruiter/show.html.twig
     */
    public function translationKey(): string
    {
        return 'recruiter_event.'.$this->value;
    }
}
