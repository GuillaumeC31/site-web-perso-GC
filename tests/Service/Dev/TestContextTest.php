<?php

declare(strict_types=1);

namespace App\Tests\Service\Dev;

use App\Entity\ChatMessage;
use App\Entity\EmailLog;
use App\Enum\ChatOutcome;
use App\Enum\MailCategory;
use App\EventListener\TestTraceListener;
use App\Service\Dev\TestContext;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Repérage précis des tests de bout en bout : navigateur ou client de test, en développement seulement ; un
 * navigateur normal (action réelle) n'est jamais repéré. Repère test_trace posé à la création.
 *
 * Liens :
 * - Couvre : App\Service\Dev\TestContext, App\EventListener\TestTraceListener, EmailLog (adresse de test)
 */
final class TestContextTest extends KernelTestCase
{
    /**
     * Vérifie le repérage selon le navigateur et l'environnement.
     */
    #[DataProvider('requests')]
    public function testTestRequestsAreRecognised(string $userAgent, string $environment, bool $expected): void
    {
        self::assertSame($expected, $this->context($userAgent, $environment)->isTestRequest());
    }

    /**
     * Navigateurs, environnements et résultat attendu.
     *
     * @return iterable<string, array{string, string, bool}>
     */
    public static function requests(): iterable
    {
        yield 'Edge sans interface (Playwright)' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 HeadlessChrome/140.0 Safari/537.36 Edg/140', 'dev', true];
        yield 'requête directe de Playwright' => ['Playwright/1.64.1 (x64; windows 10.0) node/24.20', 'dev', true];
        yield 'Lighthouse' => ['Mozilla/5.0 Chrome-Lighthouse', 'dev', true];
        yield 'navigateur normal (action réelle)' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/140.0 Safari/537.36 Edg/140', 'dev', false];
        yield 'production : jamais' => ['Mozilla/5.0 HeadlessChrome/140', 'prod', false];
    }

    /**
     * Vérifie les adresses du domaine des tests.
     */
    public function testTestAddresses(): void
    {
        self::assertTrue(TestContext::isTestAddress('rh@example.test'));
        self::assertTrue(TestContext::isTestAddress('rh@e2e-parcours.example.test'));
        self::assertFalse(TestContext::isTestAddress('rh@example.test.test'));
        self::assertFalse(TestContext::isTestAddress('contact-0937@webmail.test'));
        self::assertTrue((new EmailLog(new \DateTimeImmutable(), 'test', MailCategory::General, 'rh@e2e.example.test', true))->isTestTrace());
        self::assertFalse((new EmailLog(new \DateTimeImmutable(), 'test', MailCategory::General, 'contact-0937@webmail.test', true))->isTestTrace());
    }

    /**
     * Vérifie le repère posé à la création : requête de test marquée, action réelle laissée telle quelle.
     */
    public function testListenerMarksOnlyTestRequests(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $test = new ChatMessage(null, new \DateTimeImmutable(), 'Q', 'R', ChatOutcome::Refused);
        (new TestTraceListener($this->context('Mozilla/5.0 HeadlessChrome/140', 'dev')))->prePersist(new PrePersistEventArgs($test, $entityManager));
        self::assertTrue($test->isTestTrace());

        $real = new ChatMessage(null, new \DateTimeImmutable(), 'Q', 'R', ChatOutcome::Refused);
        (new TestTraceListener($this->context('Mozilla/5.0 (Windows NT 10.0) Edg/140', 'dev')))->prePersist(new PrePersistEventArgs($real, $entityManager));
        self::assertFalse($real->isTestTrace());
    }

    /**
     * Construit le service pour une requête donnée.
     */
    private function context(string $userAgent, string $environment): TestContext
    {
        $stack = new RequestStack();
        $stack->push(Request::create('/', server: ['HTTP_USER_AGENT' => $userAgent]));

        return new TestContext($stack, null, $environment);
    }
}
