<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\MailCategory;
use App\Service\Mail\MailRecipients;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Vérifie, avant une mise en ligne, que les adresses « Recrutement » et « Général du site » effectives (enregistrées
 * dans Communication › E-mails, sinon adresse du compte admin) existent et ne sont pas fictives (example.test,
 * example.com, localhost…). Échoue sinon : scripts/deploy.sh refuse alors de terminer le déploiement.
 *
 * Usage : php bin/console app:communication:check --env=prod
 *
 * Liens :
 * - Appelée par : scripts/deploy.sh (après les migrations)
 * - Utilise : MailRecipients::findProblems(), addressFor()
 * - Testée par : tests/Command/CommunicationCheckCommandTest
 */
#[AsCommand(name: 'app:communication:check', description: 'Vérifie les adresses de réception avant une mise en ligne (ni vides ni fictives)')]
final class CommunicationCheckCommand extends Command
{
    /**
     * Reçoit le service des adresses.
     */
    public function __construct(private readonly MailRecipients $recipients)
    {
        parent::__construct();
    }

    /**
     * Affiche les adresses effectives (masquées) et échoue si l'une manque ou est fictive.
     *
     * Liens :
     * - Appelée par : la console Symfony
     *
     * @return int Command::SUCCESS si tout va bien, Command::FAILURE sinon
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $problems = $this->recipients->findProblems();
        foreach ([MailCategory::Recruitment->value => 'Recrutement', MailCategory::General->value => 'Général du site'] as $key => $label) {
            $problem = $problems[$key] ?? null;
            if (null === $problem) {
                $io->writeln(\sprintf('Adresse « %s » : OK.', $label));
                continue;
            }
            $io->error(\sprintf('Adresse « %s » %s : renseignez-la dans l\'admin (Communication › E-mails).', $label, 'missing' === $problem ? 'absente' : 'fictive (example.test, example.com, localhost…)'));
        }

        return [] === $problems ? Command::SUCCESS : Command::FAILURE;
    }
}
