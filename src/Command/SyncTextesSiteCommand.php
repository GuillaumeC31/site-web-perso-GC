<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\CvContentSynchronizer;
use App\Service\Import\TextesSiteParser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Aligne le contenu du CV en base sur TEXTES_SITE.md (tiré du CV de référence).
 *
 * Garde-fous :
 * - en production, refuse de tourner sans l'option --production ;
 * - le chemin du fichier est obligatoire (le fichier est hors du dépôt) ;
 * - par défaut, n'écrit RIEN : liste ce qui serait ajouté, remplacé ou supprimé (--report=fichier.md
 *   pour le rapport complet) ; l'écriture demande --apply, après validation de cette liste ;
 * - aucune donnée privée n'est importée hors de son champ réservé (voir TextesSiteParser).
 *
 * Usage :
 *   php bin/console app:content:sync-textes ../TEXTES_SITE.md --dry-run --report=var/sync-textes.md
 *   php bin/console app:content:sync-textes ../TEXTES_SITE.md --apply
 *
 * Liens :
 * - Utilise : TextesSiteParser, CvContentSynchronizer
 * - Testée par : tests/Command/SyncTextesSiteCommandTest
 */
#[AsCommand(name: 'app:content:sync-textes', description: 'Aligne le contenu du CV sur TEXTES_SITE.md (aperçu par défaut, --apply pour écrire ; environnement local uniquement)')]
final class SyncTextesSiteCommand extends Command
{
    /**
     * Reçoit les dépendances.
     *
     * @param TextesSiteParser      $parser       lecture du fichier
     * @param CvContentSynchronizer $synchronizer comparaison et écriture
     * @param string                $environment  environnement du noyau (dev, test, prod)
     */
    public function __construct(
        private readonly TextesSiteParser $parser,
        private readonly CvContentSynchronizer $synchronizer,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    /**
     * Déclare l'argument (chemin du fichier) et les options --apply et --report.
     *
     * Liens :
     * - Appelée par : la console Symfony
     */
    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::REQUIRED, 'Chemin de TEXTES_SITE.md')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Écrire les changements en base (sinon : aperçu seulement)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Aperçu seulement : lister créations, mises à jour et suppressions sans rien écrire (comportement par défaut)')
            ->addOption('report', null, InputOption::VALUE_REQUIRED, 'Écrire le rapport complet (Markdown) dans ce fichier')
            ->addOption('production', null, InputOption::VALUE_NONE, 'Autoriser la commande en production (serveur, après sauvegarde de la base)');
    }

    /**
     * Vérifie les garde-fous, analyse le fichier, affiche les changements et les applique avec --apply.
     *
     * Liens :
     * - Appelée par : la console Symfony
     * - Utilise : TextesSiteParser::extract(), CvContentSynchronizer::synchronize()
     *
     * @return int Command::SUCCESS, ou Command::FAILURE (production sans --production, fichier illisible, donnée privée détectée)
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($input->getOption('apply') && $input->getOption('dry-run')) {
            $io->error('--apply et --dry-run sont incompatibles.');

            return Command::FAILURE;
        }
        $apply = (bool) $input->getOption('apply');
        $io->title($apply ? 'Synchronisation du CV avec TEXTES_SITE.md' : 'Aperçu de la synchronisation (rien n\'est écrit)');

        if ('prod' === $this->environment && !$input->getOption('production')) {
            $io->error([
                'En production, la commande exige l\'option --production (après une sauvegarde de la base).',
            ]);

            return Command::FAILURE;
        }

        $path = (string) $input->getArgument('path');
        if (!is_file($path) || !is_readable($path)) {
            $io->error(\sprintf('Fichier introuvable ou illisible : %s', $path));

            return Command::FAILURE;
        }

        try {
            $content = $this->parser->extract((string) file_get_contents($path));
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $changes = $this->synchronizer->synchronize($content, $apply);

        if ([] === $changes) {
            $io->success('Le contenu en base est déjà aligné sur le fichier.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Partie', 'Élément', 'Action', 'Actuel', 'Nouveau'],
            array_map(static fn (array $c): array => [$c['area'], $c['item'], $c['action'], mb_strimwidth((string) $c['old'], 0, 60, '…'), mb_strimwidth((string) $c['new'], 0, 60, '…')], $changes),
        );

        $report = $input->getOption('report');
        if (\is_string($report) && '' !== $report) {
            file_put_contents($report, $this->buildReport($changes));
            $io->note(\sprintf('Rapport complet : %s', $report));
        }

        if ([] !== $content['skipped']) {
            $io->note($content['skipped']);
        }

        if ($apply) {
            $io->success(\sprintf('%d changement(s) enregistré(s).', \count($changes)));
        } else {
            $io->warning(\sprintf('%d changement(s) à valider. Rien n\'a été écrit : relancez avec --apply pour les enregistrer.', \count($changes)));
        }

        return Command::SUCCESS;
    }

    /**
     * Construit le rapport Markdown complet (textes entiers), regroupé par partie.
     *
     * @param list<array{area: string, item: string, action: string, old: ?string, new: ?string}> $changes changements relevés
     */
    private function buildReport(array $changes): string
    {
        $lines = ['# Synchronisation du CV avec TEXTES_SITE.md', '', \sprintf('%d changement(s).', \count($changes))];
        $area = null;
        foreach ($changes as $change) {
            if ($change['area'] !== $area) {
                $area = $change['area'];
                $lines[] = '';
                $lines[] = '## '.$area;
            }
            $lines[] = '';
            $lines[] = \sprintf('- **%s** — %s', $change['item'], $change['action']);
            if (null !== $change['old']) {
                $lines[] = '  - Actuel : '.$change['old'];
            }
            if (null !== $change['new']) {
                $lines[] = '  - Nouveau : '.$change['new'];
            }
        }

        return implode("\n", $lines)."\n";
    }
}
