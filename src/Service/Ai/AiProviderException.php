<?php

declare(strict_types=1);

namespace App\Service\Ai;

/**
 * Erreur du fournisseur d'IA (limite de débit, panne, délai dépassé, réponse illisible). Le message ne
 * contient jamais la clé d'API ni le contenu envoyé : il peut être journalisé tel quel.
 *
 * Liens :
 * - Levée par : MistralProvider
 * - Utilisée par : AiCheckCommand ; chatbot (bascule vers le modèle de secours, puis l'assistant guidé)
 */
final class AiProviderException extends \RuntimeException
{
    /** Pas de réponse dans le délai (ou connexion impossible). */
    public const TIMEOUT = 'timeout';

    /** Limite de débit atteinte (HTTP 429). */
    public const RATE_LIMITED = 'rate_limited';

    /** Modèle inconnu ou retiré (HTTP 400/404 sur le modèle). */
    public const MODEL_UNAVAILABLE = 'model_unavailable';

    /** Clé absente ou refusée (HTTP 401/403). */
    public const UNAUTHORIZED = 'unauthorized';

    /** Autre erreur du fournisseur (5xx, réponse illisible). */
    public const PROVIDER_ERROR = 'provider_error';

    /**
     * Crée l'erreur.
     *
     * @param string   $reason     une des constantes de cette classe
     * @param string   $message    description sans clé ni contenu
     * @param int|null $httpStatus code HTTP reçu (null : pas de réponse)
     */
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?int $httpStatus = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
