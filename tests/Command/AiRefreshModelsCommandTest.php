<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Heartbeat;
use App\Repository\AiUsageRepository;
use App\Repository\HeartbeatRepository;
use App\Tests\Support\MockHttpResponseFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Vérification quotidienne des modèles Mistral AI : seulement GET /v1/models (aucune question, aucun
 * jeton), passage noté pour le tableau de bord, modèle retiré signalé. Client HTTP simulé : l'API réelle
 * n'est jamais appelée.
 *
 * Liens :
 * - Couvre : App\Command\AiRefreshModelsCommand
 */
final class AiRefreshModelsCommandTest extends KernelTestCase
{
    /**
     * Vérifie le cas normal : deux modèles disponibles, aucune question posée, passage réussi noté.
     */
    public function testModelsAvailable(): void
    {
        MockHttpResponseFactory::reset();
        self::bootKernel();
        $tester = new CommandTester((new Application(self::$kernel))->find('app:ai:refresh-models'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('modele-principal-test (principal)', $tester->getDisplay());
        self::assertStringNotContainsString('test-mistral-key-0000', $tester->getDisplay());
        self::assertCount(1, MockHttpResponseFactory::requestsTo('mistral.example.test/v1/models'));
        self::assertCount(1, MockHttpResponseFactory::requestsTo('mistral.example.test'), 'Aucune question posée.');
        self::assertSame(0, self::getContainer()->get(AiUsageRepository::class)->sumTokensSince(new \DateTimeImmutable('-1 hour')));

        $heartbeat = self::getContainer()->get(HeartbeatRepository::class)->findAllByName()[Heartbeat::AI_MODELS] ?? null;
        self::assertNotNull($heartbeat);
        self::assertTrue($heartbeat->isSuccess());
        self::assertSame([], $heartbeat->getDetails()['alerts'] ?? null);
    }

    /**
     * Vérifie l'alerte : modèle de secours retiré de la liste de Mistral AI.
     */
    public function testRetiredModelIsReported(): void
    {
        MockHttpResponseFactory::reset();
        MockHttpResponseFactory::$mistralModels = ['modele-principal-test'];
        self::bootKernel();
        $tester = new CommandTester((new Application(self::$kernel))->find('app:ai:refresh-models'));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('absent de la liste', $tester->getDisplay());
        $heartbeat = self::getContainer()->get(HeartbeatRepository::class)->findAllByName()[Heartbeat::AI_MODELS] ?? null;
        self::assertNotNull($heartbeat);
        self::assertTrue($heartbeat->isSuccess(), 'La liste a bien été lue.');
        self::assertSame(['fallback:missing'], $heartbeat->getDetails()['alerts'] ?? null);
        MockHttpResponseFactory::reset();
    }
}
