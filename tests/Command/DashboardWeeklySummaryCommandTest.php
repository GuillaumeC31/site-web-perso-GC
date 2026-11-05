<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Enum\RecruiterStatus;
use App\Tests\Support\AdminTestTrait;
use App\Tests\Support\MockHttpResponseFactory;
use App\Tests\Support\RecruiterTestTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;

/**
 * Résumé hebdomadaire du tableau de bord : envoyé à l'adresse de notification du compte admin, mêmes
 * indicateurs que la page, chiffres seulement (aucun nom, adresse ni entreprise de recruteur).
 *
 * Liens :
 * - Couvre : App\Command\DashboardWeeklySummaryCommand, templates/email/admin/dashboard_summary.*.twig
 */
final class DashboardWeeklySummaryCommandTest extends KernelTestCase
{
    use AdminTestTrait;
    use RecruiterTestTrait;

    /**
     * Vérifie l'envoi et le contenu : toutes les tuiles, des chiffres, aucune donnée personnelle.
     */
    public function testSummaryHasNumbersOnly(): void
    {
        MockHttpResponseFactory::reset();
        self::bootKernel();
        $this->createAdmin();
        $this->createRecruiter('camille.martin@acme.test', RecruiterStatus::Pending, null);
        $tester = new CommandTester((new Application(self::$kernel))->find('app:dashboard:weekly-summary'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('notifications@example.test', $email->getTo()[0]->getAddress());
        self::assertStringStartsWith('Résumé hebdomadaire du site', (string) $email->getSubject());
        $text = (string) $email->getTextBody();
        foreach (['Recruteurs', 'Messages', 'Contenu à compléter', 'Assistant IA', 'Cerveau de l\'IA', 'Communication', 'Sécurité', 'Sauvegardes et tâches planifiées', 'Site', 'Calculatrice'] as $title) {
            self::assertStringContainsString($title, $text);
        }
        self::assertStringContainsString('1 demande(s) en attente', $text);
        foreach ([(string) $email->getHtmlBody(), $text] as $body) {
            self::assertStringNotContainsString('camille.martin@acme.test', $body);
            self::assertStringNotContainsString('Camille', $body);
            self::assertStringNotContainsString('Acme', $body);
        }
    }

    /**
     * Vérifie l'échec sans adresse de notification (aucun e-mail).
     */
    public function testFailsWithoutNotificationAddress(): void
    {
        self::bootKernel();
        $tester = new CommandTester((new Application(self::$kernel))->find('app:dashboard:weekly-summary'));
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertEmailCount(0);
    }
}
