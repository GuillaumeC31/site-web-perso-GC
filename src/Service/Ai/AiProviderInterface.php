<?php

declare(strict_types=1);

namespace App\Service\Ai;

/**
 * Fournisseur d'IA du site, derrière une interface neutre : le reste du code ne connaît ni l'API ni la
 * clé du fournisseur. Une seule implémentation : MistralProvider (offre gratuite de Mistral AI).
 *
 * Liens :
 * - Implémentée par : MistralProvider
 * - Utilisée par : AiCheckCommand (app:ai:check) ; chatbot, import de CV et synthèse d'entreprise (lot 7)
 */
interface AiProviderInterface
{
    /**
     * Envoie une conversation au modèle et renvoie sa réponse.
     *
     * @param string                                                          $model       identifiant du modèle (configuration)
     * @param list<array{role: 'system'|'user'|'assistant', content: string}> $messages    consignes, puis échanges
     * @param int                                                             $maxTokens   longueur maximale de la réponse (jetons)
     * @param float|null                                                      $timeout     délai maximal de l'appel (secondes) ; null : délai par défaut (15 s)
     * @param float|null                                                      $temperature température (0 : réponse la plus déterministe) ; null : valeur par défaut (0,2)
     *
     * @throws AiProviderException en cas d'erreur du fournisseur (429, panne, délai dépassé, réponse illisible)
     */
    public function complete(string $model, array $messages, int $maxTokens, ?float $timeout = null, ?float $temperature = null): AiCompletion;

    /**
     * Liste les modèles disponibles pour le compte.
     *
     * @return list<array{id: string, chat: bool, archived: bool, aliases: list<string>}>
     *
     * @throws AiProviderException en cas d'erreur du fournisseur
     */
    public function listModels(): array;

    /**
     * Indique si le fournisseur est configuré (clé présente).
     */
    public function isConfigured(): bool;

    /**
     * Nom du fournisseur et du service, affiché au recruteur et dans l'admin (ex. « Mistral AI »).
     */
    public function getName(): string;
}
