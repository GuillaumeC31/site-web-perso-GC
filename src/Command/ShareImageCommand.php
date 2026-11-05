<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Seo\ShareImageGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Produit l'image de partage Open Graph (1200 × 630) à partir du profil : photo, nom, titre et sous-titre.
 *
 * Idempotente : sans effet si l'image du contenu actuel existe déjà. Lancée par scripts/deploy.sh
 * après les migrations ; l'image est aussi refaite automatiquement quand le nom, le titre, le sous-titre ou la
 * photo changent dans l'admin (ShareImageRegenerationListener).
 *
 * Usage : php bin/console app:seo:share-image
 *
 * Liens :
 * - Utilise : ShareImageGenerator::generate()
 * - Voir aussi : SeoContext::getShareImage() (balises og:image et twitter:image)
 */
#[AsCommand(name: 'app:seo:share-image', description: 'Produit l\'image de partage (Open Graph) à partir du profil')]
final class ShareImageCommand extends Command
{
    /**
     * Reçoit le générateur d'image.
     *
     * @param ShareImageGenerator $generator générateur de l'image de partage
     */
    public function __construct(private readonly ShareImageGenerator $generator)
    {
        parent::__construct();
    }

    /**
     * Produit l'image si elle n'est pas à jour.
     *
     * Liens :
     * - Appelée par : la console Symfony, scripts/deploy.sh
     *
     * @return int Command::SUCCESS, ou Command::FAILURE si l'image n'a pas pu être produite
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $result = $this->generator->generate();
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success($result['created'] ? \sprintf('Image de partage produite : public/%s.', $result['path']) : \sprintf('Image de partage déjà à jour : public/%s.', $result['path']));

        return Command::SUCCESS;
    }
}
