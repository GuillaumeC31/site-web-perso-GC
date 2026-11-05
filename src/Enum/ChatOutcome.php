<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Issue d'une question à l'assistant de l'espace recruteur (journal des conversations).
 *
 * Liens :
 * - Utilisé par : ChatMessage, ChatAssistant, admin (journal), templates de l'assistant
 */
enum ChatOutcome: string
{
    /** Question guidée (réponse tirée du contenu public, sans IA). */
    case Guided = 'guided';

    /** Réponse du modèle principal. */
    case AiPrimary = 'ai_primary';

    /** Réponse du modèle de secours (principal en erreur, trop lent ou presque à sa limite). */
    case AiFallback = 'ai_fallback';

    /** Les deux modèles ont échoué : assistant guidé proposé. */
    case GuidedFallback = 'guided_fallback';

    /** Plafond atteint (recruteur, site, mois) ou débit dépassé : assistant guidé proposé. */
    case LimitReached = 'limit_reached';

    /** Question libre désactivée dans l'admin : assistant guidé proposé. */
    case Disabled = 'disabled';

    /** Tentative de détournement (consignes, changement de rôle) : refus poli, sans appel au modèle. */
    case Refused = 'refused';

    /**
     * Renvoie la clé de traduction du libellé.
     *
     * Liens :
     * - Appelée par : templates de l'admin (journal)
     */
    public function translationKey(): string
    {
        return 'assistant.outcome.'.$this->value;
    }

    /**
     * Indique si la réponse vient d'un modèle d'IA (jetons consommés).
     */
    public function isAi(): bool
    {
        return \in_array($this, [self::AiPrimary, self::AiFallback], true);
    }

    /**
     * Indique si l'IA n'a pas répondu (panne, plafond, désactivation) : l'assistant guidé est mis en avant
     * avec le lien vers le formulaire de contact.
     *
     * Liens :
     * - Appelée par : templates/recruiter_area/_assistant.html.twig
     */
    public function isGuidedFallback(): bool
    {
        return \in_array($this, [self::GuidedFallback, self::LimitReached, self::Disabled], true);
    }
}
