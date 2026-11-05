<?php

declare(strict_types=1);

namespace App\Service\Ai;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Adaptateur Mistral AI (offre gratuite de La Plateforme), seul fournisseur d'IA du site.
 *
 * - La clé part uniquement dans l'en-tête Authorization (« Bearer … »), jamais dans une URL ; elle n'est
 *   jamais écrite dans un message d'erreur ni dans les journaux.
 * - Délai maximal d'un appel : 15 s (réglable), au-delà : AiProviderException::TIMEOUT.
 * - Le modèle est choisi par l'appelant, d'après la configuration (MISTRAL_MODEL_PRIMARY, MISTRAL_MODEL_FALLBACK).
 *
 * API : GET /v1/models, POST /v1/chat/completions (docs.mistral.ai/api).
 *
 * Liens :
 * - Implémente : AiProviderInterface
 * - Utilise : HttpClient, MISTRAL_API_KEY (.env.local), MISTRAL_BASE_URL
 * - Testé par : tests/Service/Ai/MistralProviderTest (client HTTP simulé, jamais la vraie API)
 */
#[AsAlias(AiProviderInterface::class)]
final readonly class MistralProvider implements AiProviderInterface
{
    /** Température par défaut (synthèse d'entreprise, import de CV) ; le chatbot demande 0. */
    public const DEFAULT_TEMPERATURE = 0.2;

    /** Préfixes des en-têtes de limites de débit relevés dans les réponses (jamais la clé). */
    private const RATE_LIMIT_HEADER_PREFIXES = ['x-ratelimit', 'ratelimit'];

    /** Délai maximal d'un appel, en secondes. */
    public const TIMEOUT_SECONDS = 15;

    /**
     * Reçoit le client HTTP et la configuration.
     *
     * @param string $apiKey  clé d'API Mistral (.env.local, jamais dans Git)
     * @param string $baseUrl adresse de l'API (https://api.mistral.ai)
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(MISTRAL_API_KEY)%')]
        private string $apiKey,
        #[Autowire('%env(MISTRAL_BASE_URL)%')]
        private string $baseUrl,
    ) {
    }

    /**
     * Envoie une conversation au modèle (POST /v1/chat/completions).
     *
     * Liens :
     * - Appelée par : AiCheckCommand, AiCaller::call()
     *
     * @param string                                                          $model       identifiant du modèle
     * @param list<array{role: 'system'|'user'|'assistant', content: string}> $messages    consignes, puis échanges
     * @param int                                                             $maxTokens   longueur maximale de la réponse
     * @param float|null                                                      $timeout     délai maximal (secondes), 15 s au plus
     * @param float|null                                                      $temperature température (0 accepté par Mistral AI) ; null : DEFAULT_TEMPERATURE
     *
     * @throws AiProviderException en cas d'erreur (sans la clé ni le contenu)
     */
    public function complete(string $model, array $messages, int $maxTokens, ?float $timeout = null, ?float $temperature = null): AiCompletion
    {
        $start = hrtime(true);
        [$data, $rateLimits] = $this->request('POST', '/v1/chat/completions', [
            'json' => ['model' => $model, 'messages' => $messages, 'max_tokens' => $maxTokens, 'temperature' => $temperature ?? self::DEFAULT_TEMPERATURE],
        ], $model, min($timeout ?? self::TIMEOUT_SECONDS, self::TIMEOUT_SECONDS));

        $choice = $data['choices'][0] ?? null;
        $content = \is_array($choice) ? ($choice['message']['content'] ?? null) : null;
        if (!\is_string($content)) {
            throw new AiProviderException(AiProviderException::PROVIDER_ERROR, 'Réponse de Mistral AI illisible (pas de texte).');
        }
        $usage = \is_array($data['usage'] ?? null) ? $data['usage'] : [];

        return new AiCompletion(
            trim($content),
            \is_string($data['model'] ?? null) ? $data['model'] : $model,
            (int) ($usage['prompt_tokens'] ?? 0),
            (int) ($usage['completion_tokens'] ?? 0),
            (int) ((hrtime(true) - $start) / 1_000_000),
            \is_string($choice['finish_reason'] ?? null) ? $choice['finish_reason'] : '',
            $rateLimits,
        );
    }

    /**
     * Liste les modèles du compte (GET /v1/models).
     *
     * Liens :
     * - Appelée par : AiCheckCommand, AiModelMonitor (alerte « modèle retiré »)
     *
     * @return list<array{id: string, chat: bool, archived: bool, aliases: list<string>}>
     *
     * @throws AiProviderException en cas d'erreur
     */
    public function listModels(): array
    {
        $models = [];
        $list = $this->request('GET', '/v1/models', [], null, self::TIMEOUT_SECONDS)[0]['data'] ?? [];
        foreach (\is_array($list) ? $list : [] as $model) {
            if (!\is_array($model) || !\is_string($model['id'] ?? null)) {
                continue;
            }
            $models[] = [
                'id' => $model['id'],
                'chat' => true === ($model['capabilities']['completion_chat'] ?? false),
                'archived' => true === ($model['archived'] ?? false),
                'aliases' => array_values(array_filter((array) ($model['aliases'] ?? []), 'is_string')),
            ];
        }
        usort($models, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        return $models;
    }

    /**
     * Indique si la clé d'API est renseignée.
     */
    public function isConfigured(): bool
    {
        return '' !== trim($this->apiKey);
    }

    /**
     * Nom du fournisseur.
     */
    public function getName(): string
    {
        return 'Mistral AI';
    }

    /**
     * Envoie une requête authentifiée et décode le JSON ; convertit toute erreur en AiProviderException.
     *
     * @param array<string, mixed> $options options du client HTTP (json…)
     * @param string|null          $model   modèle demandé (pour reconnaître « modèle indisponible »)
     * @param float                $timeout délai maximal de la requête (secondes)
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>} corps décodé, en-têtes de limites de débit
     *
     * @throws AiProviderException
     */
    private function request(string $method, string $path, array $options, ?string $model, float $timeout): array
    {
        if (!$this->isConfigured()) {
            throw new AiProviderException(AiProviderException::UNAUTHORIZED, 'Clé d\'API Mistral absente (MISTRAL_API_KEY dans .env.local).');
        }

        try {
            $response = $this->httpClient->request($method, rtrim($this->baseUrl, '/').$path, $options + [
                'headers' => ['Authorization' => 'Bearer '.$this->apiKey, 'Accept' => 'application/json'],
                'timeout' => $timeout,
                'max_duration' => $timeout,
            ]);
            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw $this->httpError($status, $response->getContent(false), $model);
            }
            $data = $response->toArray(false);
            $rateLimits = [];
            foreach ($response->getHeaders(false) as $name => $values) {
                foreach (self::RATE_LIMIT_HEADER_PREFIXES as $prefix) {
                    if (str_starts_with(strtolower($name), $prefix)) {
                        $rateLimits[strtolower($name)] = implode(', ', $values);
                    }
                }
            }
            ksort($rateLimits);
        } catch (AiProviderException $exception) {
            throw $exception;
        } catch (TransportExceptionInterface $exception) {
            throw new AiProviderException(AiProviderException::TIMEOUT, \sprintf('Mistral AI injoignable ou trop lent (délai de %s s).', round($timeout, 1)), null, $exception);
        } catch (ExceptionInterface $exception) {
            throw new AiProviderException(AiProviderException::PROVIDER_ERROR, 'Réponse de Mistral AI illisible.', null, $exception);
        }

        return [$data, $rateLimits];
    }

    /**
     * Traduit une erreur HTTP de Mistral en AiProviderException (sans la clé ni le contenu envoyé).
     *
     * @param string      $body  corps de la réponse d'erreur (seul son message court est repris)
     * @param string|null $model modèle demandé
     */
    private function httpError(int $status, string $body, ?string $model): AiProviderException
    {
        $detail = json_decode($body, true);
        $message = \is_array($detail) && \is_string($detail['message'] ?? null) ? $detail['message'] : '';
        // Message du fournisseur raccourci, et jamais la clé (par prudence, même s'il ne la contient pas)
        $message = mb_strimwidth(str_replace($this->apiKey, '***', $message), 0, 200, '…');

        $reason = match (true) {
            429 === $status => AiProviderException::RATE_LIMITED,
            401 === $status, 403 === $status => AiProviderException::UNAUTHORIZED,
            null !== $model && \in_array($status, [400, 404, 422], true) && str_contains(mb_strtolower($message), 'model') => AiProviderException::MODEL_UNAVAILABLE,
            default => AiProviderException::PROVIDER_ERROR,
        };

        return new AiProviderException($reason, \sprintf('Mistral AI : erreur HTTP %d%s.', $status, '' === $message ? '' : ' ('.$message.')'), $status);
    }
}
