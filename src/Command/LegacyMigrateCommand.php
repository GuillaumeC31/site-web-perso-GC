<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Legacy\LegacyDatabaseReader;
use App\Service\Legacy\LegacyMigrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Migration de l'ancienne base (site v1) vers la v2 : inventaire des tables, aperçu des changements (rien
 * n'est écrit), puis application avec --apply. Relancée, elle ne refait rien (projets repérés par leur
 * adresse, données de calcul par leur nom).
 *
 * L'ancienne base est une copie locale de l'export de production, lue en lecture seule
 * (LEGACY_DATABASE_URL dans .env.local) ; les images des projets viennent d'une copie du dossier
 * public/uploads de l'ancien site (--uploads).
 *
 * Usage :
 *   php bin/console app:legacy:migrate --uploads=../guillaume_c-prod/public/uploads            (aperçu)
 *   php bin/console app:legacy:migrate --uploads=../guillaume_c-prod/public/uploads --apply    (écriture)
 *
 * Liens :
 * - Utilise : LegacyDatabaseReader, LegacyMigrator
 * - Testé par : tests/Service/Legacy/LegacyMigratorTest
 */
#[AsCommand(name: 'app:legacy:migrate', description: 'Reprend les projets, LinkedIn et les données de calcul de l\'ancien site (aperçu, puis --apply)')]
final class LegacyMigrateCommand extends Command
{
    /** Tables de l'ancienne base et ce qu'elles deviennent dans la v2 (inventaire affiché). */
    public const INVENTORY = [
        'project' => 'repris : projets du Labo, en brouillon',
        'category' => 'repris : catégories des projets',
        'project_category' => 'repris : liens projets ↔ catégories',
        'social_network' => 'repris : LinkedIn seulement (Paramètres)',
        'fluid' => 'repris : calculatrice (chauffe d\'un fluide)',
        'printer3d' => 'repris : calculatrice (coût d\'une impression 3D)',
        'energy_provider' => 'repris : calculatrice (coût d\'une impression 3D)',
        'hosting_provider' => 'non repris : l\'hébergeur est écrit dans les mentions légales',
        'experience' => 'non repris : contenu du CV synchronisé depuis TEXTES_SITE.md',
        'experience_skill' => 'non repris : contenu du CV synchronisé depuis TEXTES_SITE.md',
        'skill' => 'non repris : contenu du CV synchronisé depuis TEXTES_SITE.md',
        'setting' => 'non repris : réglages propres à la v1 (et données privées)',
        'user' => 'non repris : comptes (admin v2 : mot de passe et YubiKey)',
        'recruiter' => 'non repris : recruteurs (décision du 24/10/2026)',
        'recruiter_request' => 'non repris : demandes de recruteurs',
        'chatbot_directive' => 'non repris : consignes du chatbot v1',
        'changelog_entry' => 'non repris : journal des versions v1',
        'maintenance_subscriber' => 'non repris : inscriptions à la fin de maintenance v1',
        'messenger_messages' => 'non repris : file de messages technique',
        'doctrine_migration_versions' => 'non repris : historique technique des migrations v1',
    ];

    /**
     * Reçoit les dépendances.
     *
     * @param string $legacyUrl adresse de la copie locale de l'ancienne base (LEGACY_DATABASE_URL)
     */
    public function __construct(
        private readonly LegacyMigrator $migrator,
        #[Autowire('%env(default::LEGACY_DATABASE_URL)%')]
        private readonly ?string $legacyUrl,
    ) {
        parent::__construct();
    }

    /**
     * Déclare les options.
     */
    protected function configure(): void
    {
        $this
            ->addOption('uploads', null, InputOption::VALUE_REQUIRED, 'Dossier public/uploads de l\'ancien site (images des projets)')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Écrire les changements (sinon : aperçu seulement)');
    }

    /**
     * Affiche l'inventaire, l'aperçu des changements, puis les applique avec --apply.
     *
     * Liens :
     * - Appelée par : la console Symfony
     *
     * @return int Command::SUCCESS, ou Command::FAILURE si l'ancienne base est inaccessible
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $uploads = \is_string($input->getOption('uploads')) ? $input->getOption('uploads') : null;
        if (null !== $uploads && !is_dir($uploads.'/projects')) {
            $io->error(\sprintf('Dossier des images introuvable : %s/projects.', $uploads));

            return Command::FAILURE;
        }

        try {
            $reader = LegacyDatabaseReader::fromUrl((string) $this->legacyUrl);
            $counts = $reader->countRows();
        } catch (\Throwable $exception) {
            $io->error('Ancienne base inaccessible : '.$exception->getMessage());

            return Command::FAILURE;
        }

        $io->section('Inventaire de l\'ancienne base');
        $io->table(['Table', 'Lignes', 'Dans la v2'], array_map(
            static fn (string $table, int $count): array => [$table, $count, self::INVENTORY[$table] ?? 'non repris (table inconnue)'],
            array_keys($counts),
            $counts,
        ));

        $result = $this->migrator->migrate($reader, $uploads, $apply);
        $io->section($apply ? 'Changements enregistrés' : 'Changements prévus (aperçu)');
        if ([] === $result['changes']) {
            $io->success('Rien à reprendre : la v2 contient déjà tout.');
        } else {
            $io->table(['Rubrique', 'Élément', 'Action', 'Détail'], array_map(static fn (array $c): array => [$c['area'], $c['item'], $c['action'], $c['detail']], $result['changes']));
        }
        if ([] !== $result['notes']) {
            $io->section('Remarques');
            $io->listing($result['notes']);
        }
        if (null === $uploads) {
            $io->note('Sans --uploads, les images des projets ne sont pas reprises.');
        }

        if ($apply) {
            $io->success(\sprintf('%d changement(s) enregistré(s). Projets importés en brouillon : à relire dans l\'admin avant publication.', \count($result['changes'])));
        } elseif ([] !== $result['changes']) {
            $io->warning(\sprintf('%d changement(s) prévu(s). Rien n\'a été écrit : relancez avec --apply pour les enregistrer.', \count($result['changes'])));
        }

        return Command::SUCCESS;
    }
}
