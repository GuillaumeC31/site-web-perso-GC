<?php

declare(strict_types=1);

namespace App\Tests\Service\Dev;

use App\DependencyInjection\E2eDatabaseEnvVarProcessor;
use App\Entity\ChatMessage;
use App\Enum\ChatOutcome;
use App\EventListener\TestTraceListener;
use App\Kernel;
use App\Service\Ai\E2eFakeAiProvider;
use App\Service\Dev\E2eMode;
use App\Service\Dev\TestContext;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Le mode « tests de bout en bout » (base dédiée, cache à part, repérage des requêtes de test) n'existe qu'en
 * développement : en production (et en test PHPUnit) il est TOTALEMENT inactif, quels que soient le navigateur, le
 * compte, l'adresse ou les variables d'environnement (décision du 2026-10-29).
 *
 * Liens :
 * - Couvre : Service\Dev\E2eMode, Service\Dev\TestContext, DependencyInjection\E2eDatabaseEnvVarProcessor,
 *   EventListener\TestTraceListener, App\Kernel::getCacheDir(), commandes app:e2e:* et IA simulée (développement seulement)
 */
final class E2eModeTest extends KernelTestCase
{
    /** Repères de test réunis : navigateur sans interface, variable des commandes de la suite. */
    private const TEST_SERVER = ['HTTP_USER_AGENT' => 'Mozilla/5.0 HeadlessChrome/140 Playwright/1.64 Chrome-Lighthouse', E2eMode::ENV_VAR => '1'];

    /**
     * Vérifie le mode selon l'environnement et les repères.
     *
     * @param array<string, string> $server variables du serveur
     */
    #[DataProvider('cases')]
    public function testModeIsActiveOnlyInDevelopment(string $environment, array $server, bool $expected): void
    {
        self::assertSame($expected, E2eMode::isActive($environment, $server));
    }

    /**
     * Environnements et repères.
     *
     * @return iterable<string, array{string, array<string, string>, bool}>
     */
    public static function cases(): iterable
    {
        yield 'production, tous les repères de test' => ['prod', self::TEST_SERVER, false];
        yield 'production, commande avec E2E_DATABASE=1' => ['prod', [E2eMode::ENV_VAR => '1'], false];
        yield 'PHPUnit, tous les repères de test' => ['test', self::TEST_SERVER, false];
        yield 'développement, navigateur de test' => ['dev', ['HTTP_USER_AGENT' => 'Mozilla/5.0 HeadlessChrome/140'], true];
        yield 'développement, commande de la suite' => ['dev', [E2eMode::ENV_VAR => '1'], true];
        yield 'développement, vrai navigateur' => ['dev', ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0) Edg/140', E2eMode::ENV_VAR => '1'], false];
        yield 'développement, commande ordinaire' => ['dev', [], false];
    }

    /**
     * Vérifie qu'en production la base et le dossier de cache restent ceux de production, même pour une requête qui
     * porte tous les repères de test.
     */
    public function testProductionDatabaseAndCacheAreNeverSwitched(): void
    {
        $server = $_SERVER;
        $_SERVER = self::TEST_SERVER + $_SERVER;
        try {
            $url = 'mysql://u:p@127.0.0.1:3306/guillaume_c2?serverVersion=mariadb-11.4.13';
            $processor = new E2eDatabaseEnvVarProcessor('prod');
            self::assertSame($url, $processor->getEnv('e2e_db', 'DATABASE_URL', static fn (): string => $url));
            self::assertSame(E2eMode::databaseUrl($url), (new E2eDatabaseEnvVarProcessor('dev'))->getEnv('e2e_db', 'DATABASE_URL', static fn (): string => $url));
            self::assertStringEndsWith('/var/cache/prod', str_replace('\\', '/', (new Kernel('prod', false))->getCacheDir()));
        } finally {
            $_SERVER = $server;
        }
    }

    /**
     * Vérifie qu'en production aucune requête n'est repérée comme test et qu'aucune ligne n'est marquée, même avec un
     * navigateur sans interface.
     */
    public function testProductionNeverRecognisesTestRequests(): void
    {
        self::bootKernel();
        $stack = new RequestStack();
        $stack->push(Request::create('/', server: self::TEST_SERVER));
        $context = new TestContext($stack, null, 'prod');
        self::assertFalse($context->isTestRequest());
        self::assertFalse($context->isDevelopment());

        $message = new ChatMessage(null, new \DateTimeImmutable(), 'Q', 'R', ChatOutcome::Refused);
        (new TestTraceListener($context))->prePersist(new PrePersistEventArgs($message, static::getContainer()->get(EntityManagerInterface::class)));
        self::assertFalse($message->isTestTrace());
    }

    /**
     * Vérifie que les outils des tests (commandes app:e2e:*, IA simulée) ne sont déclarés qu'en développement : ils
     * n'existent pas dans le conteneur de production.
     */
    public function testTestToolsAreDeclaredInDevelopmentOnly(): void
    {
        $classes = [E2eFakeAiProvider::class];
        foreach (glob(\dirname(__DIR__, 3).'/src/Command/E2e*Command.php') ?: [] as $file) {
            $classes[] = 'App\\Command\\'.basename($file, '.php');
        }
        self::assertGreaterThanOrEqual(7, \count($classes));
        foreach ($classes as $class) {
            $environments = array_map(static fn (\ReflectionAttribute $attribute): string => $attribute->newInstance()->env, (new \ReflectionClass($class))->getAttributes(When::class));
            self::assertSame(['dev'], $environments, $class);
        }
    }
}
