<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\DbBackupCommand;
use App\Entity\Heartbeat;
use App\Repository\HeartbeatRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Sauvegarde de la base : lecture de DATABASE_URL, fichier d'options (le mot de passe n'est jamais sur
 * la ligne de commande ni dans la console), rotation des 10 dernières sauvegardes sans toucher aux
 * sauvegardes faites à la main. mysqldump n'est pas lancé (outil absent de l'environnement de test).
 *
 * Liens :
 * - Couvre : DbBackupCommand
 */
final class DbBackupCommandTest extends KernelTestCase
{
    /** Dossier temporaire des sauvegardes du test. */
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/dbbackup-test-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    /**
     * Vérifie la rotation : 10 sauvegardes gardées (les plus récentes), sauvegardes manuelles et autres bases intactes.
     */
    public function testRotationKeepsTheTenLatestAndIgnoresOtherFiles(): void
    {
        for ($day = 1; $day <= 13; ++$day) {
            touch(\sprintf('%s/guillaume_c2-backup-202610%02d-120000.sql', $this->directory, $day));
        }
        touch($this->directory.'/guillaume_c2-avant-lot7-20261001-120000.sql');
        touch($this->directory.'/autre_base-backup-20261001-120000.sql');

        $removed = $this->command()->rotate($this->directory, 'guillaume_c2', DbBackupCommand::KEEP);

        self::assertSame(['guillaume_c2-backup-20261003-120000.sql', 'guillaume_c2-backup-20261002-120000.sql', 'guillaume_c2-backup-20261001-120000.sql'], $removed);
        self::assertCount(10, glob($this->directory.'/guillaume_c2-backup-*.sql') ?: []);
        self::assertFileExists($this->directory.'/guillaume_c2-backup-20261013-120000.sql');
        self::assertFileExists($this->directory.'/guillaume_c2-avant-lot7-20261001-120000.sql');
        self::assertFileExists($this->directory.'/autre_base-backup-20261001-120000.sql');
    }

    /**
     * Vérifie la lecture de DATABASE_URL et le fichier d'options (mot de passe protégé par des guillemets).
     */
    public function testOptionsFileHoldsTheCredentials(): void
    {
        $command = $this->command();
        $database = $command->parseDatabaseUrl('mysql://gc2:p%40ss%22w0rd@127.0.0.1:3307/guillaume_c2?serverVersion=mariadb-11.4.13');

        self::assertSame(['host' => '127.0.0.1', 'port' => 3307, 'user' => 'gc2', 'password' => 'p@ss"w0rd', 'name' => 'guillaume_c2'], $database);
        self::assertSame("[client]\nuser=\"gc2\"\npassword=\"p@ss\\\"w0rd\"\nhost=\"127.0.0.1\"\nport=3307\n", $command->buildOptionsFile($database));

        $this->expectException(\InvalidArgumentException::class);
        $command->parseDatabaseUrl('sqlite:///var/data.db');
    }

    /**
     * Vérifie l'échec propre si mysqldump est introuvable : code d'erreur, aucun mot de passe affiché,
     * aucun fichier d'options laissé dans le dossier temporaire ; échec noté pour le tableau de bord.
     */
    public function testMissingBinaryFailsWithoutLeakingThePassword(): void
    {
        self::bootKernel();
        $tempFilesBefore = glob(sys_get_temp_dir().'/dbbackup*') ?: [];
        $tester = new CommandTester((new Application(self::$kernel))->find('app:db:backup'));

        $status = $tester->execute(['--binary' => $this->directory.'/mysqldump-introuvable']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Sauvegarde impossible', $tester->getDisplay());
        $password = (string) parse_url((string) $_SERVER['DATABASE_URL'], \PHP_URL_PASS);
        if ('' !== $password) {
            self::assertStringNotContainsString(urldecode($password), $tester->getDisplay());
        }
        self::assertSame($tempFilesBefore, glob(sys_get_temp_dir().'/dbbackup*') ?: []);
        $heartbeat = static::getContainer()->get(HeartbeatRepository::class)->findAllByName()[Heartbeat::BACKUP] ?? null;
        self::assertNotNull($heartbeat);
        self::assertFalse($heartbeat->isSuccess());
        self::assertSame('mysqldump a échoué', $heartbeat->getDetails()['error'] ?? null);
    }

    /**
     * Construit la commande avec une configuration de test.
     */
    private function command(): DbBackupCommand
    {
        return new DbBackupCommand('mysql://u:p@localhost/guillaume_c2', 'mysqldump', $this->directory, $this->createStub(HeartbeatRepository::class));
    }
}
