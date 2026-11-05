<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\SkillPillar;
use App\Repository\SkillPillarRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Crée les quatre piliers de compétences de départ (TEXTES_SITE §5), si aucun pilier n'existe.
 *
 * Sans effet si des piliers existent déjà : on peut la relancer sans risque. Lancée par
 * scripts/deploy.sh après les migrations (les migrations ne contiennent que
 * la structure de la base, jamais de contenu).
 *
 * Usage : php bin/console app:skill-pillar:create-defaults
 *
 * Liens :
 * - Utilise : SkillPillar, SkillPillarRepository
 * - Voir aussi : Admin\SkillPillarController (modification des noms et de l'ordre)
 */
#[AsCommand(name: 'app:skill-pillar:create-defaults', description: 'Crée les piliers de compétences de départ s\'il n\'y en a aucun')]
final class CreateDefaultSkillPillarsCommand extends Command
{
    /** Piliers de départ, dans l'ordre d'affichage (décision du 2026-10-21). */
    public const DEFAULT_PILLARS = [
        'Génie climatique et frigorifique',
        'Électrotechnique et automatisme',
        'Bureau d\'études et gestion de projet',
        'Numérique industriel',
    ];

    /**
     * Reçoit les dépendances.
     *
     * @param SkillPillarRepository  $skillPillarRepository dépôt des piliers
     * @param EntityManagerInterface $entityManager         gestionnaire Doctrine
     */
    public function __construct(
        private readonly SkillPillarRepository $skillPillarRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    /**
     * Crée les piliers de départ si la table est vide.
     *
     * Liens :
     * - Appelée par : la console Symfony, scripts/deploy.sh
     *
     * @return int Command::SUCCESS
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->skillPillarRepository->count([]) > 0) {
            $io->note('Des piliers existent déjà : rien n\'a été créé.');

            return Command::SUCCESS;
        }

        foreach (self::DEFAULT_PILLARS as $index => $name) {
            $this->entityManager->persist((new SkillPillar())->setName($name)->setPosition($index + 1));
        }
        $this->entityManager->flush();

        $io->success(\sprintf('%d piliers créés.', \count(self::DEFAULT_PILLARS)));

        return Command::SUCCESS;
    }
}
