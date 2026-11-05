<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Recruiter;
use App\Service\Recruiter\RecruiterScorer;
use App\Tests\Support\FakeDnsChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Score d'aide à la décision des demandes d'accès recruteur (lot 6).
 *
 * Liens :
 * - Couvre : RecruiterScorer (contrôles DNS simulés par FakeDnsChecker)
 */
final class RecruiterScorerTest extends TestCase
{
    /**
     * Cas de score : adresse, entreprise, message, IP → score attendu.
     *
     * @return iterable<string, array{string, string, ?string, ?string, int}>
     */
    public static function provideRequests(): iterable
    {
        yield 'domaine professionnel complet' => ['rh@acme.test', 'Acme', 'Bonjour', '198.51.100.7', 100];
        yield 'sans message' => ['rh@acme.test', 'Acme', null, '198.51.100.7', 90];
        yield 'messagerie grand public' => ['contact-3bda@webmail.test', 'Acme', null, '198.51.100.7', 50];
        yield 'domaine sans MX' => ['rh@no-mx.test', 'Acme', null, '198.51.100.7', 60];
        yield 'adresse jetable' => ['contact-9dd4@webmail.test', 'Acme', 'Bonjour', '198.51.100.7', 0];
        yield 'IP sur liste de blocage' => ['rh@acme.test', 'Acme', 'Bonjour', FakeDnsChecker::LISTED_IP, 0];
        yield 'IP locale non vérifiée' => ['rh@acme.test', 'Acme', null, '127.0.0.1', 90];
    }

    /**
     * Vérifie le score et la présence du détail de chaque contrôle.
     *
     * @param string      $email    adresse de la demande
     * @param string      $company  entreprise
     * @param string|null $message  message facultatif
     * @param string|null $ip       adresse IP du demandeur
     * @param int         $expected score attendu sur 100
     */
    #[DataProvider('provideRequests')]
    public function testScore(string $email, string $company, ?string $message, ?string $ip, int $expected): void
    {
        $recruiter = (new Recruiter(new \DateTimeImmutable()))->setEmail($email)->setFullName('Camille Martin')->setCompany($company)->setMessage($message);
        $result = (new RecruiterScorer(new FakeDnsChecker()))->score($recruiter, $ip);

        self::assertSame($expected, $result['score']);
        $steps = array_column($result['details'], 'step');
        foreach (['disposable', 'mx', 'spamhaus'] as $step) {
            self::assertContains($step, $steps);
        }
    }
}
