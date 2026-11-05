<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Service\Ai\AiCompletion;
use App\Service\Ai\AiModelMonitor;
use App\Service\Ai\AiProviderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Alerte « modèle retiré » : modèle configuré absent de GET /v1/models (ou archivé), liste lue au plus une
 * fois par jour (cache de 24 h), relecture forcée par le bouton de l'admin. Fournisseur simulé.
 *
 * Liens :
 * - Couvre : AiModelMonitor::getModelAlerts()
 */
final class AiModelMonitorTest extends TestCase
{
    /**
     * Vérifie l'alerte et le rythme des appels à la liste des modèles.
     */
    public function testRetiredModelIsReportedAndListIsReadOncePerDay(): void
    {
        $provider = new class implements AiProviderInterface {
            public int $listCalls = 0;

            /** @var list<array{id: string, chat: bool, archived: bool, aliases: list<string>}> */
            public array $models = [
                ['id' => 'ministral-3b-2512', 'chat' => true, 'archived' => false, 'aliases' => []],
                ['id' => 'ministral-8b-2512', 'chat' => true, 'archived' => true, 'aliases' => ['ministral-8b-latest']],
            ];

            public function complete(string $model, array $messages, int $maxTokens, ?float $timeout = null, ?float $temperature = null): AiCompletion
            {
                throw new \LogicException('Aucun appel de conversation dans ce test.');
            }

            public function listModels(): array
            {
                ++$this->listCalls;

                return $this->models;
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function getName(): string
            {
                return 'Fournisseur simulé';
            }
        };
        $monitor = new AiModelMonitor($provider, new ArrayAdapter(), 'ministral-8b-2512', 'ministral-3b-2512');

        self::assertSame([['role' => 'primary', 'model' => 'ministral-8b-2512', 'problem' => 'archived']], $monitor->getModelAlerts());
        $monitor->getModelAlerts();
        self::assertSame(1, $provider->listCalls, 'Liste gardée 24 h : un seul appel.');
        self::assertSame(86400, AiModelMonitor::MODELS_TTL);

        // Le modèle principal disparaît de la liste : relecture forcée (bouton de l'admin) → « missing »
        array_pop($provider->models);
        self::assertSame([['role' => 'primary', 'model' => 'ministral-8b-2512', 'problem' => 'missing']], $monitor->getModelAlerts(true));
        self::assertSame(2, $provider->listCalls);
    }
}
