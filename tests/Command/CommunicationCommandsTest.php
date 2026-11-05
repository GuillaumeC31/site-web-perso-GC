<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\FailedEmail;
use App\Repository\SiteSettingsRepository;
use App\Tests\Support\MockHttpResponseFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;

/**
 * Commandes de la page Communication : app:communication:check (refus d'une mise en ligne avec une adresse vide ou
 * fictive) et app:communication:alerts (alertes « Général du site », une fois par 24 h et par type, aucun envoi
 * hors production sans --send ; appels Discord et Telegram simulés).
 *
 * Liens :
 * - Couvre : Command\CommunicationCheckCommand, Command\CommunicationAlertsCommand
 */
final class CommunicationCommandsTest extends KernelTestCase
{
    /**
     * Vide la simulation HTTP.
     */
    protected function setUp(): void
    {
        MockHttpResponseFactory::reset();
    }

    /**
     * Vérifie le contrôle de déploiement : échec sans adresse ou avec une adresse fictive, succès sinon.
     */
    public function testCheckRefusesMissingOrFictitiousAddresses(): void
    {
        self::bootKernel();
        $tester = new CommandTester((new Application(self::$kernel))->find('app:communication:check'));
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('« Recrutement » absente', $tester->getDisplay());

        $this->saveAddresses('rh@example.test', 'general@example.test');
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('« Recrutement » fictive', $tester->getDisplay());
        self::assertStringContainsString('« Général du site » : OK', $tester->getDisplay());

        $this->saveAddresses('recrutement@example.test', 'general@example.test');
        self::assertSame(Command::SUCCESS, $tester->execute([]));
    }

    /**
     * Vérifie les alertes : rien hors production sans --send ; avec --send, alerte de sécurité (aucune clé YubiKey) et
     * tâches en retard (aucune sauvegarde) par e-mail à « Général du site », Discord et Telegram, e-mail en échec sur
     * Discord et Telegram seulement ; pas de nouvel envoi dans les 24 h.
     */
    public function testAlertsAreSentOncePerDay(): void
    {
        self::bootKernel();
        $this->saveAddresses('recrutement@example.test', 'general@example.test');
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new FailedEmail(new \DateTimeImmutable(), 'notification', 'x@exemple.test', 'Objet', 'payload'));
        $entityManager->flush();
        $tester = new CommandTester((new Application(self::$kernel))->find('app:communication:alerts'));

        $tester->execute([]);
        foreach (['security_alert', 'failed_email', 'task_late'] as $event) {
            self::assertStringContainsString($event.' : ', $tester->getDisplay());
        }
        self::assertStringContainsString('non envoyé hors production', $tester->getDisplay());
        self::assertEmailCount(0);
        self::assertSame([], MockHttpResponseFactory::telegramCalls('sendMessage'));

        $tester->execute(['--send' => true]);
        self::assertStringContainsString('3 alerte(s) envoyée(s)', $tester->getDisplay());
        // Sécurité et tâches : e-mail (adresse « Général du site »), Discord et Telegram ; e-mails en échec : Discord et Telegram
        self::assertEmailCount(2);
        $email = self::getMailerMessage(0);
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('general@example.test', $email->getTo()[0]->getAddress());
        self::assertSame('[Site] Alerte de sécurité', $email->getSubject());
        self::assertCount(3, MockHttpResponseFactory::telegramCalls('sendMessage'));
        // Chiffres seulement : jamais l'adresse d'un e-mail en échec
        self::assertStringNotContainsString('x@exemple.test', (string) json_encode(MockHttpResponseFactory::telegramCalls('sendMessage')));

        $tester->execute(['--send' => true]);
        self::assertStringContainsString('0 alerte(s) envoyée(s)', $tester->getDisplay());
        self::assertStringContainsString('déjà signalé', $tester->getDisplay());
        self::assertCount(3, MockHttpResponseFactory::telegramCalls('sendMessage'));
    }

    /**
     * Enregistre les adresses « Recrutement » et « Général du site ».
     */
    private function saveAddresses(string $recruitment, string $general): void
    {
        $settings = static::getContainer()->get(SiteSettingsRepository::class)->getOrCreate()->setRecruitmentEmail($recruitment)->setGeneralEmail($general);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($settings);
        $entityManager->flush();
    }
}
