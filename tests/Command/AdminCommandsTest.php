<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\SecurityEvent;
use App\Entity\SkillPillar;
use App\Entity\User;
use App\Entity\WebauthnCredential;
use App\Enum\SecurityEventType;
use App\Tests\Support\AdminTestTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests des commandes console du lot 2 (compte admin, secours, purge, piliers).
 *
 * Liens :
 * - Couvre : CreateAdminCommand, ResetAdminPasswordCommand, ResetAdminWebauthnCommand,
 *   PurgeSecurityEventsCommand, CreateDefaultSkillPillarsCommand
 */
final class AdminCommandsTest extends KernelTestCase
{
    use AdminTestTrait;
    use ClockSensitiveTrait;

    /**
     * Vérifie que app:create-admin refuse le mode non interactif (le mot de passe doit être saisi masqué).
     */
    public function testCreateAdminRefusesNonInteractiveMode(): void
    {
        $tester = $this->createTester('app:create-admin');
        $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(0, $this->getEntityManager()->getRepository(User::class)->count([]));
    }

    /**
     * Vérifie que app:create-admin crée le compte (mot de passe haché, e-mail à part,
     * rôle admin) et affiche un lien d'enrôlement.
     */
    public function testCreateAdminCreatesAccountWithEnrollmentLink(): void
    {
        self::bootKernel();
        $tester = $this->createTester('app:create-admin');
        $tester->setInputs(['gcouget', 'notifications@example.test', 'Un-Mot-De-Passe-Solide-2026', 'Un-Mot-De-Passe-Solide-2026']);
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('/securite/enrolement/', $tester->getDisplay());
        self::assertStringNotContainsString('Un-Mot-De-Passe-Solide-2026', $tester->getDisplay());

        $user = $this->getEntityManager()->getRepository(User::class)->findOneBy(['username' => 'gcouget']);
        self::assertNotNull($user);
        self::assertTrue($user->isAdmin());
        self::assertSame('notifications@example.test', $user->getNotificationEmail());
        self::assertStringStartsNotWith('Un-Mot', $user->getPassword());
        self::assertNotNull($user->getEnrollmentTokenHash());
    }

    /**
     * Vérifie que app:create-admin refuse un identifiant identique à l'e-mail.
     */
    public function testCreateAdminRefusesUsernameEqualToEmail(): void
    {
        self::bootKernel();
        $tester = $this->createTester('app:create-admin');
        $tester->setInputs(['gcouget', 'gcouget@example.test']);

        try {
            $tester->execute([]);
        } catch (\RuntimeException) {
            // Les saisies prévues sont épuisées après le refus de l'e-mail : c'est attendu.
        }

        self::assertStringContainsString('différent de l\'adresse e-mail', $tester->getDisplay());
        self::assertSame(0, $this->getEntityManager()->getRepository(User::class)->count([]));
    }

    /**
     * Vérifie que app:create-admin refuse de créer un deuxième compte admin.
     */
    public function testCreateAdminRefusesSecondAdmin(): void
    {
        self::bootKernel();
        $this->createAdmin();
        $tester = $this->createTester('app:create-admin');
        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('existe déjà', $tester->getDisplay());
    }

    /**
     * Vérifie que app:admin:reset-webauthn supprime les clés, ferme les sessions
     * (version de sécurité) et affiche un lien d'enrôlement dont seule l'empreinte est stockée.
     */
    public function testResetWebauthnDeletesKeysAndPrintsEnrollmentLink(): void
    {
        self::bootKernel();
        $user = $this->createAdmin();
        $versionBefore = $user->getSecurityVersion();

        $tester = $this->createTester('app:admin:reset-webauthn');
        $tester->setInputs(['yes']);
        $tester->execute(['username' => 'admin_test']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(1, preg_match('#/securite/enrolement/([A-Za-z0-9_-]{43})#', $tester->getDisplay(), $matches));

        $this->getEntityManager()->clear();
        $reloaded = $this->getEntityManager()->find(User::class, $user->getId());
        self::assertNotNull($reloaded);
        self::assertSame($versionBefore + 1, $reloaded->getSecurityVersion());
        self::assertSame(hash('sha256', $matches[1]), $reloaded->getEnrollmentTokenHash());
        self::assertSame(0, $this->getEntityManager()->getRepository(WebauthnCredential::class)->count([]));
    }

    /**
     * Vérifie que app:admin:reset-webauthn ne change rien sans confirmation.
     */
    public function testResetWebauthnDoesNothingWithoutConfirmation(): void
    {
        self::bootKernel();
        $this->createAdmin();

        $tester = $this->createTester('app:admin:reset-webauthn');
        $tester->setInputs(['no']);
        $tester->execute(['username' => 'admin_test']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(1, $this->getEntityManager()->getRepository(WebauthnCredential::class)->count([]));
    }

    /**
     * Vérifie que app:admin:reset-password remplace le mot de passe, garde les clés
     * et ferme les sessions ouvertes (version de sécurité).
     */
    public function testResetPasswordReplacesPasswordAndKeepsKeys(): void
    {
        self::bootKernel();
        $user = $this->createAdmin();
        $oldHash = $user->getPassword();
        $versionBefore = $user->getSecurityVersion();

        $tester = $this->createTester('app:admin:reset-password');
        $tester->setInputs(['yes', 'Nouveau-Mot-De-Passe-2026', 'Nouveau-Mot-De-Passe-2026']);
        $tester->execute(['username' => 'admin_test']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $this->getEntityManager()->clear();
        $reloaded = $this->getEntityManager()->find(User::class, $user->getId());
        self::assertNotNull($reloaded);
        self::assertNotSame($oldHash, $reloaded->getPassword());
        self::assertSame($versionBefore + 1, $reloaded->getSecurityVersion());
        self::assertSame(1, $this->getEntityManager()->getRepository(WebauthnCredential::class)->count([]));
    }

    /**
     * Vérifie qu'un mot de passe trop court est refusé (3 essais, puis abandon).
     */
    public function testResetPasswordRejectsWeakPassword(): void
    {
        self::bootKernel();
        $user = $this->createAdmin();

        $tester = $this->createTester('app:admin:reset-password');
        $tester->setInputs(['yes', 'court', 'court', 'court', 'court', 'court', 'court']);

        try {
            $tester->execute(['username' => 'admin_test']);
            self::fail('Une exception était attendue après 3 essais.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('3 essais', $exception->getMessage());
        }

        $this->getEntityManager()->clear();
        self::assertSame($user->getPassword(), $this->getEntityManager()->find(User::class, $user->getId())?->getPassword());
    }

    /**
     * Vérifie que les commandes de secours signalent un identifiant inconnu.
     */
    public function testResetCommandsRejectUnknownUsername(): void
    {
        self::bootKernel();

        foreach (['app:admin:reset-webauthn', 'app:admin:reset-password'] as $commandName) {
            $tester = $this->createTester($commandName);
            $tester->execute(['username' => 'inconnu']);
            self::assertSame(Command::FAILURE, $tester->getStatusCode(), $commandName);
        }
    }

    /**
     * Vérifie que app:security-event:purge supprime les lignes de plus de 90 jours et garde les autres.
     */
    public function testPurgeDeletesEventsOlderThanNinetyDays(): void
    {
        self::bootKernel();
        $entityManager = $this->getEntityManager();

        self::mockTime('2025-12-01 12:00:00');
        $entityManager->persist(new SecurityEvent(SecurityEventType::PasswordSuccess, 'ancien', '192.0.2.1', null));
        $entityManager->flush();
        // L'entité date ses lignes avec l'heure système : on recule la date à la main.
        $entityManager->getConnection()->executeStatement("UPDATE security_event SET occurred_at = '2025-12-01 12:00:00'");
        $entityManager->persist(new SecurityEvent(SecurityEventType::PasswordSuccess, 'recent', '192.0.2.2', null));
        $entityManager->flush();
        $entityManager->getConnection()->executeStatement("UPDATE security_event SET occurred_at = '2026-10-01 12:00:00' WHERE username = 'recent'");

        self::mockTime('2026-10-21 12:00:00');
        $tester = $this->createTester('app:security-event:purge');
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $entityManager->clear();
        $remaining = $entityManager->getRepository(SecurityEvent::class)->findAll();
        self::assertCount(1, $remaining);
        self::assertSame('recent', $remaining[0]->getUsername());
    }

    /**
     * Vérifie que app:skill-pillar:create-defaults crée les 4 piliers dans l'ordre, une seule fois.
     */
    public function testCreateDefaultPillarsIsIdempotent(): void
    {
        self::bootKernel();
        $repository = $this->getEntityManager()->getRepository(SkillPillar::class);

        $this->createTester('app:skill-pillar:create-defaults')->execute([]);
        $this->createTester('app:skill-pillar:create-defaults')->execute([]);

        $names = array_map(static fn (SkillPillar $pillar): string => $pillar->getName(), $repository->findBy([], ['position' => 'ASC']));
        self::assertSame([
            'Génie climatique et frigorifique',
            'Électrotechnique et automatisme',
            'Bureau d\'études et gestion de projet',
            'Numérique industriel',
        ], $names);
    }

    /**
     * Prépare le testeur d'une commande.
     *
     * @param string $name nom de la commande
     */
    private function createTester(string $name): CommandTester
    {
        $application = new Application(self::$kernel ?? self::bootKernel());

        return new CommandTester($application->find($name));
    }
}
