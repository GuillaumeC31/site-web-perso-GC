<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Repository\SiteSettingsRepository;
use App\Service\Mail\DeliverabilityChecker;
use App\Service\Mail\TxtRecordResolver;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Délivrabilité (SPF, DKIM, DMARC) avec un DNS simulé : règles de l’hébergeur (include:spf.hebergeur.example.com, sélecteur
 * « default »), un seul SPF, cache de 1 h, « non applicable » hors production.
 *
 * Liens :
 * - Couvre : App\Service\Mail\DeliverabilityChecker
 */
final class DeliverabilityCheckerTest extends KernelTestCase
{
    /**
     * Vérifie un domaine correctement configuré.
     */
    public function testValidDomain(): void
    {
        $result = $this->checker([
            'guillaume-couget.fr' => ['v=spf1 +a +mx include:spf.hebergeur.example.com ~all', 'google-site-verification=abc'],
            'default._domainkey.guillaume-couget.fr' => ['v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA'],
            '_dmarc.guillaume-couget.fr' => ['v=DMARC1; p=none; rua=mailto:dmarc@example.test'],
        ])->checkNow();

        self::assertTrue($result['applicable']);
        self::assertTrue($result['ok']);
        self::assertSame('guillaume-couget.fr', $result['domain']);
        self::assertSame('default._domainkey.guillaume-couget.fr', $result['items']['dkim']['host']);
    }

    /**
     * Vérifie les erreurs : SPF sans l’hébergeur, deux SPF, DKIM absent, DMARC absent.
     */
    public function testProblemsAreDetected(): void
    {
        $result = $this->checker(['guillaume-couget.fr' => ['v=spf1 include:_spf.google.com ~all']])->checkNow();
        self::assertFalse($result['ok']);
        self::assertFalse($result['items']['spf']['ok']);
        self::assertFalse($result['items']['dkim']['ok']);
        self::assertFalse($result['items']['dmarc']['ok']);

        $twoSpf = $this->checker(['guillaume-couget.fr' => ['v=spf1 include:spf.hebergeur.example.com ~all', 'v=spf1 include:_spf.google.com ~all']])->checkNow();
        self::assertFalse($twoSpf['items']['spf']['ok']);
        self::assertStringContainsString(' | ', (string) $twoSpf['items']['spf']['found']);
    }

    /**
     * Vérifie le cache (le DNS n'est relu qu'avec « Vérifier maintenant ») et le résultat lu par le tableau de bord.
     */
    public function testResultIsCached(): void
    {
        $records = ['guillaume-couget.fr' => ['v=spf1 include:spf.hebergeur.example.com ~all']];
        $resolver = new class($records) implements TxtRecordResolver {
            public int $calls = 0;

            /** @param array<string, list<string>> $records */
            public function __construct(private readonly array $records)
            {
            }

            public function getTxtRecords(string $host): array
            {
                ++$this->calls;

                return $this->records[$host] ?? [];
            }
        };
        $checker = $this->checker($records, 'prod', $resolver);
        self::assertNull($checker->cachedStatus());
        $checker->check();
        $checker->check();
        self::assertSame(3, $resolver->calls);
        self::assertFalse($checker->cachedStatus());
        $checker->check(true);
        self::assertSame(6, $resolver->calls);
    }

    /**
     * Vérifie « non applicable » hors production, sans requête DNS.
     */
    public function testNotApplicableLocally(): void
    {
        $result = $this->checker([], 'dev')->check();
        self::assertFalse($result['applicable']);
        self::assertTrue($result['ok']);
        self::assertSame([], $result['items']);
    }

    /**
     * Crée le service avec un DNS simulé.
     *
     * @param array<string, list<string>> $records nom => enregistrements TXT
     */
    private function checker(array $records, string $environment = 'prod', ?TxtRecordResolver $resolver = null): DeliverabilityChecker
    {
        self::bootKernel();
        $resolver ??= new class($records) implements TxtRecordResolver {
            /** @param array<string, list<string>> $records */
            public function __construct(private readonly array $records)
            {
            }

            public function getTxtRecords(string $host): array
            {
                return $this->records[$host] ?? [];
            }
        };

        return new DeliverabilityChecker($resolver, static::getContainer()->get(SiteSettingsRepository::class), new ArrayAdapter(), static::getContainer()->get(ClockInterface::class), 'noreply@example.test', $environment);
    }
}
