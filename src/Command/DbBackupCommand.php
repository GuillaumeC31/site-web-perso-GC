<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Heartbeat;
use App\Repository\HeartbeatRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Sauvegarde la base de données : mysqldump daté dans var/backup/, en gardant les 10 dernières sauvegardes.
 *
 * Le mot de passe ne passe jamais sur la ligne de commande (visible dans la liste des processus) : il
 * est écrit dans un fichier d'options temporaire (droits 0600), donné à mysqldump par
 * --defaults-extra-file, puis supprimé. Seules les sauvegardes de cette commande (nom
 * <base>-backup-AAAAMMJJ-HHMMSS.sql) sont comptées et supprimées ; les sauvegardes faites à la main
 * dans le même dossier ne sont jamais touchées.
 *
 * Usage : php bin/console app:db:backup [--keep=10] [--binary=chemin/vers/mysqldump] [--dir=~/backups]
 * (sur l’hébergeur, la tâche planifiée écrit dans ~/backups, hors du dossier du site)
 *
 * Liens :
 * - Utilise : DATABASE_URL, MYSQLDUMP_BINARY (.env, chemin de mysqldump), symfony/process
 * - Testé par : tests/Command/DbBackupCommandTest
 */
#[AsCommand(name: 'app:db:backup', description: 'Sauvegarde la base (mysqldump daté dans var/backup/, 10 dernières gardées)')]
final class DbBackupCommand extends Command
{
    /** Nombre de sauvegardes gardées par défaut. */
    public const KEEP = 10;

    /** Durée maximale de la sauvegarde (secondes). */
    private const TIMEOUT = 600;

    /**
     * Reçoit la configuration.
     *
     * @param string              $databaseUrl DATABASE_URL (mysql://utilisateur:motdepasse@hôte:port/base)
     * @param string              $binary      chemin de mysqldump (MYSQLDUMP_BINARY, « mysqldump » s'il est dans le PATH)
     * @param string              $projectDir  dossier du projet (var/backup/ y est créé)
     * @param HeartbeatRepository $heartbeats  passage noté pour le tableau de bord (fichier et taille)
     */
    public function __construct(
        #[Autowire('%env(DATABASE_URL)%')]
        private readonly string $databaseUrl,
        #[Autowire('%env(MYSQLDUMP_BINARY)%')]
        private readonly string $binary,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly HeartbeatRepository $heartbeats,
    ) {
        parent::__construct();
    }

    /**
     * Déclare les options.
     */
    protected function configure(): void
    {
        $this
            ->addOption('keep', null, InputOption::VALUE_REQUIRED, 'Nombre de sauvegardes à garder', (string) self::KEEP)
            ->addOption('binary', null, InputOption::VALUE_REQUIRED, 'Chemin de mysqldump (par défaut : MYSQLDUMP_BINARY)')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Dossier des sauvegardes (par défaut : var/backup du projet)');
    }

    /**
     * Lance mysqldump avec un fichier d'options temporaire, puis supprime les sauvegardes en trop.
     *
     * Liens :
     * - Appelée par : la console Symfony (à la main, ou par une tâche planifiée)
     *
     * @return int Command::SUCCESS, ou Command::FAILURE si mysqldump échoue
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $database = $this->parseDatabaseUrl($this->databaseUrl);
        $keep = max(1, (int) $input->getOption('keep'));
        $binary = \is_string($input->getOption('binary')) && '' !== $input->getOption('binary') ? $input->getOption('binary') : $this->binary;

        $directory = \is_string($input->getOption('dir')) && '' !== $input->getOption('dir') ? rtrim($input->getOption('dir'), '/\\') : $this->projectDir.'/var/backup';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            $io->error(\sprintf('Dossier %s impossible à créer.', $directory));
            $this->recordHeartbeat(false, ['error' => 'Dossier des sauvegardes impossible à créer']);

            return Command::FAILURE;
        }
        $target = \sprintf('%s/%s-backup-%s.sql', $directory, $database['name'], (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Ymd-His'));

        $optionsFile = (string) tempnam(sys_get_temp_dir(), 'dbbackup');
        try {
            chmod($optionsFile, 0600);
            file_put_contents($optionsFile, $this->buildOptionsFile($database));
            // --defaults-extra-file doit être la première option de mysqldump
            $process = new Process([
                $binary, '--defaults-extra-file='.$optionsFile,
                '--single-transaction', '--routines', '--triggers', '--default-character-set=utf8mb4',
                '--result-file='.$target, $database['name'],
            ], null, null, null, self::TIMEOUT);
            $process->run();
        } catch (\Throwable $exception) {
            $process = null;
            $failure = $exception->getMessage();
        } finally {
            @unlink($optionsFile);
        }

        if (null === $process || !$process->isSuccessful()) {
            @unlink($target);
            $message = $failure ?? trim($process?->getErrorOutput() ?: (string) $process?->getOutput());
            // Par prudence : jamais le mot de passe dans la console
            $io->error('Sauvegarde impossible : '.str_replace($database['password'] ?: "\0", '***', $message));
            $this->recordHeartbeat(false, ['error' => 'mysqldump a échoué']);

            return Command::FAILURE;
        }

        $removed = $this->rotate($directory, $database['name'], $keep);
        $this->recordHeartbeat(true, ['file' => basename($target), 'size' => filesize($target) ?: 0]);
        $io->success(\sprintf('Sauvegarde : %s (%s Ko). %d ancienne(s) sauvegarde(s) supprimée(s), %d gardée(s) au plus.', basename($target), number_format((filesize($target) ?: 0) / 1024, 0, ',', ' '), \count($removed), $keep));

        return Command::SUCCESS;
    }

    /**
     * Note le passage pour le tableau de bord ; une base injoignable n'empêche pas la sauvegarde de finir.
     *
     * Liens :
     * - Utilise : HeartbeatRepository::beat() (Heartbeat::BACKUP), lu par DashboardMetrics
     *
     * @param bool                      $success vrai si la sauvegarde a réussi
     * @param array<string, string|int> $details fichier et taille (octets), ou erreur (sans mot de passe)
     */
    private function recordHeartbeat(bool $success, array $details): void
    {
        try {
            $this->heartbeats->beat(Heartbeat::BACKUP, $success, $details);
        } catch (\Throwable) {
            // Tableau de bord seulement : la sauvegarde elle-même est déjà faite (ou signalée en échec)
        }
    }

    /**
     * Lit DATABASE_URL.
     *
     * Liens :
     * - Appelée par : execute() ; tests
     *
     * @return array{host: string, port: int, user: string, password: string, name: string}
     *
     * @throws \InvalidArgumentException si l'adresse n'est pas une base MySQL ou MariaDB
     */
    public function parseDatabaseUrl(string $url): array
    {
        $parts = parse_url($url);
        if (false === $parts || !\in_array($parts['scheme'] ?? '', ['mysql', 'mariadb', 'pdo-mysql'], true) || '' === trim($parts['path'] ?? '', '/')) {
            throw new \InvalidArgumentException('DATABASE_URL doit désigner une base MySQL ou MariaDB.');
        }

        return [
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => $parts['port'] ?? 3306,
            'user' => urldecode($parts['user'] ?? ''),
            'password' => urldecode($parts['pass'] ?? ''),
            'name' => urldecode(trim($parts['path'], '/')),
        ];
    }

    /**
     * Construit le fichier d'options de mysqldump (section [client]) : utilisateur, mot de passe, hôte, port.
     *
     * Liens :
     * - Appelée par : execute() ; tests
     *
     * @param array{host: string, port: int, user: string, password: string, name: string} $database base (parseDatabaseUrl())
     */
    public function buildOptionsFile(array $database): string
    {
        $quote = static fn (string $value): string => '"'.addcslashes($value, '\\"').'"';

        return implode("\n", [
            '[client]',
            'user='.$quote($database['user']),
            'password='.$quote($database['password']),
            'host='.$quote($database['host']),
            'port='.$database['port'],
            '',
        ]);
    }

    /**
     * Supprime les plus anciennes sauvegardes de cette commande au-delà du nombre à garder.
     *
     * Liens :
     * - Appelée par : execute() ; tests
     *
     * @param string $directory dossier des sauvegardes
     * @param string $name      nom de la base (préfixe des fichiers)
     * @param int    $keep      nombre de sauvegardes à garder
     *
     * @return list<string> fichiers supprimés
     */
    public function rotate(string $directory, string $name, int $keep): array
    {
        $pattern = '/^'.preg_quote($name, '/').'-backup-\d{8}-\d{6}\.sql$/';
        $files = array_values(array_filter(scandir($directory) ?: [], static fn (string $file): bool => 1 === preg_match($pattern, $file)));
        // Le nom contient la date : l'ordre alphabétique est l'ordre chronologique
        rsort($files);
        $removed = [];
        foreach (\array_slice($files, $keep) as $file) {
            if (unlink($directory.'/'.$file)) {
                $removed[] = $file;
            }
        }

        return $removed;
    }
}
