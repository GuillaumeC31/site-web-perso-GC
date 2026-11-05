<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Repository\AiUsageRepository;
use App\Tests\Support\MockHttpResponseFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Vérification de l'IA (app:ai:check) avec le client HTTP simulé : modèles listés, appel court par
 * modèle configuré, en-têtes de limites affichés, jetons comptés, jamais la clé.
 *
 * Liens :
 * - Couvre : AiCheckCommand, MistralProvider::listModels()
 */
final class AiCheckCommandTest extends KernelTestCase
{
    /**
     * Vérifie la sortie de la commande et l'absence de la clé.
     */
    public function testCheckListsModelsAndNeverShowsTheKey(): void
    {
        MockHttpResponseFactory::reset();
        self::bootKernel();
        MockHttpResponseFactory::$mistralQueue = [
            MockHttpResponseFactory::mistralChat('OK.', 20, 2, ['x-ratelimit-remaining-req-minute' => '187']),
            MockHttpResponseFactory::mistralChat('OK.', 20, 2, [], 'modele-secours-test'),
        ];
        $tester = new CommandTester((new Application(self::$kernel))->find('app:ai:check'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();
        self::assertStringContainsString('modele-principal-test', $display);
        self::assertStringContainsString('modele-secours-test', $display);
        self::assertStringContainsString('x-ratelimit-remaining-req-minute', $display);
        self::assertStringNotContainsString('test-mistral-key-0000', $display);
        self::assertNotEmpty(MockHttpResponseFactory::requestsTo('mistral.example.test/v1/models'));
        // Jetons comptés dans le plafond mensuel
        self::assertSame(44, self::getContainer()->get(AiUsageRepository::class)->sumTokensSince(new \DateTimeImmutable('-1 hour')));
    }
}
