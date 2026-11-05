<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Ai\AiDirectiveImporter;
use App\Service\Legacy\LegacyDatabaseReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Reprend les directives du chatbot de l'ancien site en fiches du « Cerveau de l'IA » (brouillons
 * inactifs). Sans --apply : aperçu seulement, rien n'est écrit. Les « Règles strictes » sont listées à part,
 * jamais importées : à décider par l'admin (les règles restent dans le socle de sécurité).
 *
 * Usage :
 *   php bin/console app:ai:import-directives            (aperçu)
 *   php bin/console app:ai:import-directives --apply    (après validation de la liste)
 *
 * Liens :
 * - Utilise : LegacyDatabaseReader (LEGACY_DATABASE_URL, copie locale de l'ancienne base, lecture seule),
 *   AiDirectiveImporter
 * - Testée par : tests/Service/Ai/AiDirectiveImporterTest (lecteur simulé)
 */
#[AsCommand(name: 'app:ai:import-directives', description: 'Reprend les directives du chatbot de l\'ancien site en fiches du Cerveau de l\'IA (brouillons ; aperçu sans --apply)')]
final class AiImportDirectivesCommand extends Command
{
    /**
     * Reçoit les dépendances.
     *
     * @param string|null $legacyUrl adresse de la copie locale de l'ancienne base (LEGACY_DATABASE_URL)
     */
    public function __construct(
        private readonly AiDirectiveImporter $importer,
        private readonly TranslatorInterface $translator,
        #[Autowire('%env(default::LEGACY_DATABASE_URL)%')]
        private readonly ?string $legacyUrl,
    ) {
        parent::__construct();
    }

    /**
     * Déclare l'option --apply.
     */
    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Créer les fiches (sinon : aperçu seulement)');
    }

    /**
     * Affiche l'aperçu (ou les fiches créées), les règles strictes à décider et les directives écartées.
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
        try {
            $result = $this->importer->import(LegacyDatabaseReader::fromUrl((string) $this->legacyUrl), $apply);
        } catch (\Throwable $exception) {
            $io->error('Ancienne base inaccessible : '.$exception->getMessage());

            return Command::FAILURE;
        }

        $io->section($apply ? 'Fiches créées (brouillons inactifs)' : 'Fiches prévues (aperçu, rien n\'est écrit)');
        if ([] === $result['imports']) {
            $io->text('Aucune.');
        } else {
            $io->table(['N°', 'Catégorie', 'Titre', 'Contenu'], array_map(fn (array $i): array => [
                $i['id'], $this->translator->trans($i['category']->translationKey()), $i['title'], mb_strimwidth($i['content'], 0, 90, '…'),
            ], $result['imports']));
        }

        $io->section('Règles strictes de l\'ancien site (non importées : à décider)');
        if ([] === $result['strict']) {
            $io->text('Aucune.');
        } else {
            $io->listing(array_map(static fn (array $r): string => \sprintf('n° %d%s : %s', $r['id'], $r['active'] ? '' : ' (inactive)', $r['content']), $result['strict']));
        }

        if ([] !== $result['skipped']) {
            $io->section('Directives écartées');
            $io->table(['N°', 'Type', 'Motif'], array_map(fn (array $s): array => [
                $s['id'], $s['type'], implode(', ', array_map(fn (string $r): string => $this->translator->trans($r), explode(', ', $s['reason']))),
            ], $result['skipped']));
        }
        if (!$apply && [] !== $result['imports']) {
            $io->note('Relisez la liste, puis relancez avec --apply : les fiches seront créées inactives (brouillons).');
        }

        return Command::SUCCESS;
    }
}
