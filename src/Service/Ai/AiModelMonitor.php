<?php

declare(strict_types=1);

namespace App\Service\Ai;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Surveille les modèles configurés :
 * - limites de débit : lit les en-têtes x-ratelimit-remaining-… de chaque réponse ; si le reste est
 *   faible (ou après une erreur 429), le modèle principal est ménagé pendant 60 s et les questions
 *   passent par le modèle de secours, avant toute erreur 429 ;
 * - modèles retirés : compare les modèles configurés à la liste de Mistral AI (lue au plus une fois par jour),
 *   pour l'alerte du tableau de bord de l'admin.
 *
 * Liens :
 * - Utilisé par : AiCaller (ordre des modèles), AiStatus (état et alertes), Admin\AssistantController
 * - Utilise : AiProviderInterface::listModels(), pool de cache cache.ai, MISTRAL_MODEL_PRIMARY, MISTRAL_MODEL_FALLBACK
 */
final readonly class AiModelMonitor
{
    /** Durée pendant laquelle le modèle principal est ménagé (secondes). */
    public const PRIMARY_REST_SECONDS = 60;

    /** Seuil de requêtes restantes (par minute) en dessous duquel le principal est ménagé. */
    public const LOW_REMAINING_REQUESTS = 2;

    /** Seuil de jetons restants en dessous duquel le principal est ménagé (environ 3 questions). */
    public const LOW_REMAINING_TOKENS = 10000;

    /**
     * Durée de conservation de la liste des modèles, et d'un échec de lecture (secondes) : GET /v1/models
     * est appelé au plus une fois par jour (lot 8) ; le bouton « Vérifier les modèles » de l'admin force
     * une nouvelle lecture.
     */
    public const MODELS_TTL = 86400;
    public const MODELS_ERROR_TTL = 86400;

    private const PRIMARY_REST_KEY = 'ai_primary_rest';
    private const MODELS_KEY = 'ai_models';

    /**
     * Reçoit les dépendances et les modèles configurés.
     *
     * @param string $primaryModel  modèle principal (ministral-8b-2512)
     * @param string $fallbackModel modèle de secours (ministral-3b-2512)
     */
    public function __construct(
        private AiProviderInterface $provider,
        #[Autowire(service: 'cache.ai')]
        private CacheItemPoolInterface $cache,
        #[Autowire('%env(MISTRAL_MODEL_PRIMARY)%')]
        private string $primaryModel,
        #[Autowire('%env(MISTRAL_MODEL_FALLBACK)%')]
        private string $fallbackModel,
    ) {
    }

    /** Renvoie le modèle principal configuré (chaîne vide si absent). */
    public function getPrimaryModel(): string
    {
        return trim($this->primaryModel);
    }

    /** Renvoie le modèle de secours configuré (chaîne vide si absent). */
    public function getFallbackModel(): string
    {
        return trim($this->fallbackModel);
    }

    /**
     * Renvoie les modèles à essayer, dans l'ordre : principal puis secours, ou secours seul si le principal
     * est ménagé.
     *
     * Liens :
     * - Appelée par : AiCaller::call()
     *
     * @return list<string>
     */
    public function getModelOrder(): array
    {
        $models = array_values(array_unique(array_filter([$this->getPrimaryModel(), $this->getFallbackModel()])));
        if ($this->isPrimaryResting() && \count($models) > 1) {
            return [$this->getFallbackModel()];
        }

        return $models;
    }

    /**
     * Note les en-têtes de limites d'une réponse : ménage le principal si son reste est faible.
     *
     * Liens :
     * - Appelée par : AiCaller::call() (après chaque réponse)
     *
     * @param string                $model      modèle qui a répondu
     * @param array<string, string> $rateLimits en-têtes x-ratelimit-… (noms en minuscules)
     */
    public function recordRateLimits(string $model, array $rateLimits): void
    {
        if ($model !== $this->getPrimaryModel()) {
            return;
        }
        foreach ($rateLimits as $name => $value) {
            if (!str_starts_with($name, 'x-ratelimit-remaining-') || !is_numeric($value)) {
                continue;
            }
            $remaining = (int) $value;
            $low = str_contains($name, '-tokens-') ? $remaining < self::LOW_REMAINING_TOKENS : $remaining <= self::LOW_REMAINING_REQUESTS;
            if ($low) {
                $this->restPrimary();

                return;
            }
        }
    }

    /**
     * Ménage le modèle principal pendant 60 s (après une erreur 429 ou un reste faible).
     *
     * Liens :
     * - Appelée par : recordRateLimits(), AiCaller::call() (erreur 429 du principal)
     */
    public function restPrimary(): void
    {
        $item = $this->cache->getItem(self::PRIMARY_REST_KEY);
        $item->set(true)->expiresAfter(self::PRIMARY_REST_SECONDS);
        $this->cache->save($item);
    }

    /**
     * Indique si le modèle principal est ménagé en ce moment.
     *
     * Liens :
     * - Appelée par : getModelOrder(), AiStatus (état « modèle de secours »)
     */
    public function isPrimaryResting(): bool
    {
        return $this->cache->getItem(self::PRIMARY_REST_KEY)->isHit();
    }

    /**
     * Compare les modèles configurés à la liste de Mistral AI et renvoie les problèmes : modèle absent
     * (retiré), archivé, non vérifié (liste illisible ou clé absente) ou non configuré.
     *
     * Liens :
     * - Appelée par : AiStatus::getSummary() (tableau de bord et page Assistant IA de l'admin)
     *
     * @param bool $refresh vrai : relit la liste chez Mistral AI (bouton « Vérifier les modèles » de l'admin)
     *
     * @return list<array{role: 'primary'|'fallback', model: string, problem: 'missing'|'archived'|'unchecked'|'not_configured'}>
     */
    public function getModelAlerts(bool $refresh = false): array
    {
        $models = $this->getAvailableModels($refresh);
        $alerts = [];
        foreach (['primary' => $this->getPrimaryModel(), 'fallback' => $this->getFallbackModel()] as $role => $model) {
            $problem = match (true) {
                '' === $model => 'not_configured',
                null === $models => 'unchecked',
                !\array_key_exists($model, $models) => 'missing',
                $models[$model] => 'archived',
                default => null,
            };
            if (null !== $problem) {
                $alerts[] = ['role' => $role, 'model' => $model, 'problem' => $problem];
            }
        }

        return $alerts;
    }

    /**
     * Renvoie les modèles de discussion du compte (identifiants et alias), avec leur état « archivé ».
     *
     * @return array<string, bool>|null identifiant ou alias => archivé ; null si la liste est illisible
     */
    private function getAvailableModels(bool $refresh): ?array
    {
        $item = $this->cache->getItem(self::MODELS_KEY);
        if (!$refresh && $item->isHit()) {
            /* @var array<string, bool>|null */
            return $item->get();
        }

        $models = null;
        $ttl = self::MODELS_ERROR_TTL;
        if ($this->provider->isConfigured()) {
            try {
                $models = [];
                foreach ($this->provider->listModels() as $model) {
                    if (!$model['chat']) {
                        continue;
                    }
                    foreach ([$model['id'], ...$model['aliases']] as $name) {
                        $models[$name] = $model['archived'];
                    }
                }
                $ttl = self::MODELS_TTL;
            } catch (AiProviderException) {
                $models = null;
            }
        }
        $item->set($models)->expiresAfter($ttl);
        $this->cache->save($item);

        return $models;
    }
}
