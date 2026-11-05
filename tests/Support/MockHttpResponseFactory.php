<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Réponses HTTP simulées de l'environnement de test : aucun appel réseau réel.
 *
 * - Cloudflare Turnstile : jeton valide, sauf « invalid-token » (refusé) ;
 * - Discord et Telegram : réponse 204 / 200 (self::$discordStatus, file self::$telegramQueue et telegramError()
 *   pour simuler un refus ; telegramCalls() relit les appels à une méthode de l'API Telegram) ;
 * - Mistral AI (MISTRAL_BASE_URL de .env.test) : liste des modèles de test ; réponses du chatbot prises
 *   dans la file self::$mistralQueue (mistralChat(), mistralError()), sinon une réponse par défaut ;
 * - les requêtes sont mémorisées (self::$requests) pour les vérifier dans les tests.
 *
 * Liens :
 * - Déclaré dans : config/packages/framework.yaml (when@test, http_client.mock_response_factory)
 * - Utilisé par : tests/Controller/ContactControllerTest, RecruiterAreaControllerTest, tests/Service/NotificationDispatcherTest,
 *   tests/Service/Ai/*, tests/Controller/AssistantControllerTest
 */
final class MockHttpResponseFactory
{
    /** Jeton Turnstile refusé par la simulation (réponse de la clé de test « toujours refuser »). */
    public const INVALID_TOKEN = 'invalid-token';

    /** Jeton Turnstile déjà utilisé (réponse de la clé de test « jeton déjà utilisé »). */
    public const DUPLICATE_TOKEN = 'duplicate-token';

    /** Jeton Turnstile envoyé avec une clé secrète refusée (erreur de configuration « invalid-input-secret »). */
    public const BAD_SECRET_TOKEN = 'bad-secret-token';

    /** Réponse du chatbot simulée par défaut (4 phrases et du Markdown : à remettre en forme). */
    public const DEFAULT_MISTRAL_ANSWER = "**Guillaume Couget** est responsable de bureau d'études. Il a près de 20 ans d'expérience. Il recherche un nouveau poste. Il aime aussi la randonnée.";

    /**
     * Requêtes reçues depuis le dernier reset().
     *
     * @var list<array{method: string, url: string, body: string, authorization: string}>
     */
    public static array $requests = [];

    /**
     * Réponses de Mistral AI à renvoyer, dans l'ordre (chat/completions) ; vide : réponse par défaut.
     *
     * @var list<MockResponse>
     */
    public static array $mistralQueue = [];

    /** @var list<string>|null modèles renvoyés par GET /v1/models (null : principal et secours de test) */
    public static ?array $mistralModels = null;

    /**
     * Réponses de l'API Telegram à renvoyer, dans l'ordre ; vide : {"ok":true}.
     *
     * @var list<MockResponse>
     */
    public static array $telegramQueue = [];

    /** Code HTTP renvoyé par le webhook Discord (204 : envoi accepté). */
    public static int $discordStatus = 204;

    /**
     * Listes officielles de Cloudflare renvoyées (https://www.cloudflare.com/ips-v4 et ips-v6) ; null : celles du
     * fichier versionné config/cloudflare_ip_ranges.php.
     *
     * @var array{ipv4: list<string>, ipv6: list<string>}|null
     */
    public static ?array $cloudflareRanges = null;

    /**
     * Vide la liste des requêtes reçues.
     *
     * Liens :
     * - Appelée par : les tests, avant chaque scénario
     */
    public static function reset(): void
    {
        self::$requests = [];
        self::$mistralQueue = [];
        self::$mistralModels = null;
        self::$telegramQueue = [];
        self::$discordStatus = 204;
        self::$cloudflareRanges = null;
    }

    /**
     * Fabrique une réponse en erreur de l'API Telegram (ex. 401 « Unauthorized » : jeton du bot faux).
     *
     * @param int    $status      code HTTP
     * @param string $description description renvoyée par Telegram
     */
    public static function telegramError(int $status, string $description): MockResponse
    {
        return new MockResponse(json_encode(['ok' => false, 'error_code' => $status, 'description' => $description], \JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }

    /**
     * Corps JSON décodés des appels à une méthode de l'API Telegram (sendMessage, editMessageText…).
     *
     * @return list<array<string, mixed>>
     */
    public static function telegramCalls(string $method): array
    {
        return array_values(array_map(
            static fn (array $r): array => (array) json_decode($r['body'], true),
            array_filter(self::$requests, static fn (array $r): bool => str_contains($r['url'], 'api.telegram.org') && str_ends_with($r['url'], '/'.$method)),
        ));
    }

    /**
     * Fabrique une réponse réussie de Mistral AI (chat/completions).
     *
     * Liens :
     * - Appelée par : les tests de l'assistant (file self::$mistralQueue)
     *
     * @param string                $content    texte de la réponse
     * @param array<string, string> $rateLimits en-têtes x-ratelimit-… à renvoyer
     */
    public static function mistralChat(string $content = self::DEFAULT_MISTRAL_ANSWER, int $promptTokens = 2800, int $completionTokens = 60, array $rateLimits = [], string $model = 'modele-principal-test'): MockResponse
    {
        return new MockResponse(json_encode([
            'model' => $model,
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => $promptTokens, 'completion_tokens' => $completionTokens, 'total_tokens' => $promptTokens + $completionTokens],
        ], \JSON_THROW_ON_ERROR), ['response_headers' => $rateLimits + ['content-type' => 'application/json']]);
    }

    /**
     * Fabrique une réponse en erreur de Mistral AI (429, 500…).
     *
     * @param int $status code HTTP
     */
    public static function mistralError(int $status, string $message = 'Requests rate limit exceeded'): MockResponse
    {
        return new MockResponse(json_encode(['message' => $message], \JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }

    /**
     * Requêtes reçues vers une adresse qui contient le texte donné.
     *
     * @return list<array{method: string, url: string, body: string, authorization: string}>
     */
    public static function requestsTo(string $urlPart): array
    {
        return array_values(array_filter(self::$requests, static fn (array $r): bool => str_contains($r['url'], $urlPart)));
    }

    /**
     * Fabrique la réponse simulée d'une requête.
     *
     * Liens :
     * - Appelée par : le client HTTP simulé (MockHttpClient)
     *
     * @param array<string, mixed> $options options de la requête
     */
    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        $body = (string) ($options['body'] ?? '');
        $authorization = '';
        foreach ((array) ($options['normalized_headers']['authorization'] ?? []) as $header) {
            $authorization .= (string) $header;
        }
        self::$requests[] = ['method' => $method, 'url' => $url, 'body' => $body, 'authorization' => $authorization];

        if (str_contains($url, 'mistral.example.test/v1/models')) {
            return new MockResponse(json_encode(['data' => array_map(
                static fn (string $id): array => ['id' => $id, 'capabilities' => ['completion_chat' => true], 'archived' => false, 'aliases' => []],
                self::$mistralModels ?? ['modele-principal-test', 'modele-secours-test'],
            )], \JSON_THROW_ON_ERROR));
        }
        if (str_contains($url, 'mistral.example.test')) {
            return array_shift(self::$mistralQueue) ?? self::mistralChat(model: json_decode($body, true)['model'] ?? 'modele-principal-test');
        }

        if (str_contains($url, 'challenges.cloudflare.com')) {
            // Réponses relevées auprès de Cloudflare avec les clés secrètes de test (2026-10-26)
            if (str_contains($body, 'response='.self::INVALID_TOKEN)) {
                return new MockResponse(json_encode(['success' => false, 'error-codes' => ['invalid-input-response']], \JSON_THROW_ON_ERROR));
            }
            if (str_contains($body, 'response='.self::BAD_SECRET_TOKEN)) {
                return new MockResponse(json_encode(['success' => false, 'error-codes' => ['invalid-input-secret']], \JSON_THROW_ON_ERROR));
            }
            if (str_contains($body, 'response='.self::DUPLICATE_TOKEN)) {
                return new MockResponse(json_encode(['success' => false, 'error-codes' => ['timeout-or-duplicate']], \JSON_THROW_ON_ERROR));
            }

            return new MockResponse(json_encode(['success' => true, 'error-codes' => []], \JSON_THROW_ON_ERROR));
        }
        if (str_contains($url, 'www.cloudflare.com/ips-v')) {
            $ranges = self::$cloudflareRanges ?? \App\Security\CloudflareIpRanges::read(\dirname(__DIR__, 2));

            return new MockResponse(implode("\n", str_ends_with($url, 'ips-v6') ? $ranges['ipv6'] : $ranges['ipv4'])."\n");
        }
        if (str_contains($url, 'discord')) {
            return new MockResponse('', ['http_code' => self::$discordStatus]);
        }
        if (str_contains($url, 'api.telegram.org')) {
            // sendMessage : numéro du message envoyé (demande du code PIN : la réponse s'y rattache)
            $default = str_ends_with($url, '/sendMessage') ? '{"ok":true,"result":{"message_id":'.(900 + \count(self::$requests)).'}}' : '{"ok":true,"result":{}}';

            return array_shift(self::$telegramQueue) ?? new MockResponse($default);
        }

        return new MockResponse('{"ok":true}');
    }
}
