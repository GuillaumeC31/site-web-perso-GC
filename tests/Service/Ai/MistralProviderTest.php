<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Service\Ai\AiProviderException;
use App\Service\Ai\MistralProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Adaptateur Mistral AI : clé seulement dans l'en-tête Authorization (jamais dans l'URL ni dans les
 * erreurs), délai de 15 s, lecture des réponses et traduction des erreurs. Aucun appel à la vraie API.
 *
 * Liens :
 * - Couvre : MistralProvider, AiCompletion, AiProviderException
 */
final class MistralProviderTest extends TestCase
{
    /** Clé factice, reconnaissable si elle fuitait. */
    private const KEY = 'cle-secrete-de-test-123';

    /**
     * Vérifie un appel réussi : en-tête Authorization, URL sans clé, délai, texte et jetons lus.
     */
    public function testCompleteSendsKeyInHeaderOnly(): void
    {
        $requests = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse((string) json_encode([
                'model' => 'modele-x',
                'choices' => [['message' => ['content' => ' Bonjour. '], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 2500, 'completion_tokens' => 60, 'total_tokens' => 2560],
            ]));
        });

        $completion = (new MistralProvider($client, self::KEY, 'https://mistral.example.test'))
            ->complete('modele-x', [['role' => 'user', 'content' => 'Question']], 200);

        self::assertSame(['Bonjour.', 'modele-x', 2500, 60, 2560, 'stop'], [$completion->content, $completion->model, $completion->promptTokens, $completion->completionTokens, $completion->totalTokens(), $completion->finishReason]);
        self::assertSame('https://mistral.example.test/v1/chat/completions', $requests[0]['url']);
        self::assertStringNotContainsString(self::KEY, $requests[0]['url']);
        self::assertContains('Authorization: Bearer '.self::KEY, $requests[0]['options']['headers']);
        self::assertSame((float) MistralProvider::TIMEOUT_SECONDS, (float) $requests[0]['options']['timeout']);
        $body = json_decode((string) $requests[0]['options']['body'], true);
        self::assertSame(['modele-x', 200], [$body['model'], $body['max_tokens']]);
    }

    /**
     * Vérifie la liste des modèles (GET /v1/models) : identifiants, conversation, archivé, alias.
     */
    public function testListModels(): void
    {
        $client = new MockHttpClient(new MockResponse((string) json_encode(['object' => 'list', 'data' => [
            ['id' => 'modele-b', 'capabilities' => ['completion_chat' => true], 'archived' => false, 'aliases' => ['modele-b-latest']],
            ['id' => 'modele-a', 'capabilities' => ['completion_chat' => false], 'archived' => true, 'aliases' => []],
        ]])));

        $models = (new MistralProvider($client, self::KEY, 'https://mistral.example.test'))->listModels();

        self::assertSame([
            ['id' => 'modele-a', 'chat' => false, 'archived' => true, 'aliases' => []],
            ['id' => 'modele-b', 'chat' => true, 'archived' => false, 'aliases' => ['modele-b-latest']],
        ], $models);
    }

    /**
     * Réponses d'erreur et motif attendu.
     *
     * @return iterable<string, array{MockResponse|\Throwable, string}>
     */
    public static function provideErrors(): iterable
    {
        yield 'limite de débit' => [new MockResponse('{"message":"Rate limit exceeded"}', ['http_code' => 429]), AiProviderException::RATE_LIMITED];
        yield 'clé refusée' => [new MockResponse('{"message":"Unauthorized '.self::KEY.'"}', ['http_code' => 401]), AiProviderException::UNAUTHORIZED];
        yield 'modèle retiré' => [new MockResponse('{"message":"Invalid model: modele-x"}', ['http_code' => 400]), AiProviderException::MODEL_UNAVAILABLE];
        yield 'panne' => [new MockResponse('{"message":"Internal error"}', ['http_code' => 503]), AiProviderException::PROVIDER_ERROR];
        yield 'délai dépassé' => [new TransportException('Idle timeout reached'), AiProviderException::TIMEOUT];
        yield 'réponse illisible' => [new MockResponse('pas du json'), AiProviderException::PROVIDER_ERROR];
    }

    /**
     * Vérifie la traduction des erreurs, sans jamais la clé dans le message.
     */
    #[DataProvider('provideErrors')]
    public function testErrorsAreTranslatedWithoutKey(MockResponse|\Throwable $response, string $reason): void
    {
        $client = new MockHttpClient(static function () use ($response): MockResponse {
            if ($response instanceof \Throwable) {
                throw $response;
            }

            return $response;
        });

        try {
            (new MistralProvider($client, self::KEY, 'https://mistral.example.test'))->complete('modele-x', [['role' => 'user', 'content' => 'Question']], 50);
            self::fail('Une erreur était attendue.');
        } catch (AiProviderException $exception) {
            self::assertSame($reason, $exception->reason);
            self::assertStringNotContainsString(self::KEY, $exception->getMessage());
        }
    }

    /**
     * Vérifie qu'aucun appel n'est fait sans clé.
     */
    public function testMissingKeyMakesNoCall(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => throw new \LogicException('Aucun appel attendu.'));
        $provider = new MistralProvider($client, '', 'https://mistral.example.test');

        self::assertFalse($provider->isConfigured());
        $this->expectException(AiProviderException::class);
        $provider->listModels();
    }
}
