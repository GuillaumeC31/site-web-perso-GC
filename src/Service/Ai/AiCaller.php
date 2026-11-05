<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\AiUsage;
use App\Enum\AiPurpose;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Appelle l'IA avec la chaîne de secours commune à tous les usages (chatbot, import de CV, synthèse) :
 * modèle principal (15 s au plus), puis modèle de secours ; si les deux échouent, l'exception remonte
 * et l'appelant passe à l'assistant guidé (ou affiche l'erreur dans l'admin).
 *
 * Chaque tentative est enregistrée dans AiUsage (jetons réels renvoyés par l'API, ou erreur) : c'est la
 * base du plafond mensuel de jetons. Les erreurs (429, panne, délai) sont journalisées sans la clé ni
 * le contenu envoyé.
 *
 * Liens :
 * - Utilisé par : ChatAssistant, CvImportAnalyzer, CompanySynthesizer
 * - Utilise : AiProviderInterface, AiThrottle (débit du site), AiModelMonitor (ordre des modèles, en-têtes de limites)
 */
final readonly class AiCaller
{
    /** Délai total de la chaîne principal + secours (secondes) ; le secours a au moins 5 s. */
    public const TOTAL_BUDGET_SECONDS = 25.0;

    /**
     * Reçoit les dépendances.
     */
    public function __construct(
        private AiProviderInterface $provider,
        private AiThrottle $throttle,
        private AiModelMonitor $modelMonitor,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Envoie la conversation au modèle principal, puis au modèle de secours en cas d'échec.
     *
     * Liens :
     * - Appelée par : ChatAssistant::ask(), CvImportAnalyzer::analyze(), CompanySynthesizer::synthesize()
     *
     * Exemple : call(AiPurpose::Chatbot, fn (string $model) => $builder->buildMessages('Mistral AI', $model, $question), 200)
     *
     * @param AiPurpose                                                                         $purpose       usage (pour le suivi de la consommation)
     * @param callable(string): list<array{role: 'system'|'user'|'assistant', content: string}> $buildMessages construit les messages pour un modèle donné
     * @param int                                                                               $maxTokens     longueur maximale de la réponse (jetons)
     * @param float|null                                                                        $temperature   température (0 pour le chatbot) ; null : valeur par défaut du fournisseur
     *
     * @return AiCallResult réponse et indication « modèle de secours »
     *
     * @throws AiProviderException si l'IA n'est pas configurée, si le débit du site est dépassé ou si tous les modèles échouent
     */
    public function call(AiPurpose $purpose, callable $buildMessages, int $maxTokens, ?float $temperature = null): AiCallResult
    {
        $models = $this->modelMonitor->getModelOrder();
        if (!$this->provider->isConfigured() || [] === $models) {
            throw new AiProviderException(AiProviderException::UNAUTHORIZED, 'Assistant IA non configuré (clé ou modèles absents).');
        }

        $start = microtime(true);
        $lastError = null;
        foreach ($models as $index => $model) {
            $isFallback = $model !== $this->modelMonitor->getPrimaryModel();
            // Le principal a 15 s ; le secours a le reste du budget, 5 s au moins
            $timeout = 0 === $index ? (float) MistralProvider::TIMEOUT_SECONDS : max(5.0, self::TOTAL_BUDGET_SECONDS - (microtime(true) - $start));
            try {
                $this->throttle->acquire();
                $completion = $this->provider->complete($model, $buildMessages($model), $maxTokens, $timeout, $temperature);
            } catch (AiProviderException $exception) {
                $lastError = $exception;
                $this->recordUsage($purpose, $model, null, $exception);
                $this->logger->warning('Assistant IA : échec de l\'appel au modèle {model} ({reason}).', [
                    'model' => $model,
                    'reason' => $exception->reason,
                    'status' => $exception->httpStatus,
                    'message' => $exception->getMessage(),
                ]);
                if (AiProviderException::RATE_LIMITED === $exception->reason && !$isFallback) {
                    $this->modelMonitor->restPrimary();
                }
                // Même clé pour les deux modèles : inutile d'essayer le secours
                if (AiProviderException::UNAUTHORIZED === $exception->reason) {
                    break;
                }
                continue;
            }

            $this->recordUsage($purpose, $model, $completion, null);
            $this->modelMonitor->recordRateLimits($model, $completion->rateLimits);

            return new AiCallResult($completion, $isFallback);
        }

        // Au moins un modèle a été essayé : $lastError est toujours renseigné ici
        throw $lastError;
    }

    /**
     * Enregistre une tentative (jetons réels ou erreur) pour le suivi de la consommation.
     */
    private function recordUsage(AiPurpose $purpose, string $model, ?AiCompletion $completion, ?AiProviderException $error): void
    {
        $this->entityManager->persist(new AiUsage(
            $this->clock->now(),
            $purpose,
            $completion->model ?? $model,
            $completion->promptTokens ?? 0,
            $completion->completionTokens ?? 0,
            // Motif court (le détail, sans la clé, est dans le journal des conversations et les journaux)
            null === $error ? null : mb_substr($error->reason.(null === $error->httpStatus ? '' : ' '.$error->httpStatus), 0, 40),
        ));
        $this->entityManager->flush();
    }
}
