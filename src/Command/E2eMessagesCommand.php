<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ContactMessage;
use App\Enum\ContactSource;
use App\Enum\ContactStatus;
use App\Enum\ContactSubject;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Développement seulement : messages de test de la page Messages pour Playwright (tests/e2e/messages.spec.js).
 *
 * - prepare --set=<nom> : crée une série de messages (entreprise « Essai E2E <nom> fin » : recherche sans ambiguïté
 *   entre séries ; adresses en
 *
 *   @e2e-messages.example.test) : 3 non lus du formulaire, 2 questions de recruteur lues, 1 répondu, 1 archivé ;
 *   une série par projet Playwright (aucun conflit entre navigateurs lancés en parallèle) ;
 * - cleanup : supprime tous les messages en @e2e-messages.example.test (repère précis, jamais selon une date).
 *
 * Usage : php bin/console app:e2e:messages prepare --set=mobile-375 | cleanup
 *
 * Liens :
 * - Appelée par : tests/e2e/messages.spec.js, tests/e2e/support/global-teardown.js
 * - Voir aussi : Service\Dev\TestTraceCleaner (supprime aussi tout message en example.test)
 */
#[When(env: 'dev')]
#[AsCommand(name: 'app:e2e:messages', description: 'Développement : messages de test de la page Messages (prepare / cleanup)')]
final class E2eMessagesCommand extends Command
{
    /** Domaine des adresses de test (domaine réservé aux tests, RFC 2606). */
    public const EMAIL_DOMAIN = 'e2e-messages.example.test';

    /**
     * Reçoit les dépendances.
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    /**
     * Déclare l'action et le nom de la série.
     */
    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::REQUIRED, 'prepare ou cleanup')
            ->addOption('set', null, InputOption::VALUE_REQUIRED, 'nom de la série (projet Playwright)', 'e2e');
    }

    /**
     * Exécute l'action.
     *
     * Liens :
     * - Appelée par : la console Symfony (Playwright)
     *
     * @return int Command::SUCCESS, ou Command::INVALID pour une action inconnue
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $set = preg_replace('/[^a-z0-9-]/i', '', (string) $input->getOption('set')) ?: 'e2e';

        return match ((string) $input->getArgument('action')) {
            'prepare' => $this->prepare($set, $output),
            'cleanup' => $this->cleanup($output),
            default => Command::INVALID,
        };
    }

    /**
     * Crée (ou recrée) la série de messages.
     */
    private function prepare(string $set, OutputInterface $output): int
    {
        $this->delete('%.'.$set.'@'.self::EMAIL_DOMAIN);
        $now = $this->clock->now();
        $rows = [
            ['Ana', ContactSource::ContactForm, ContactStatus::New, false, false],
            ['Basile', ContactSource::ContactForm, ContactStatus::New, false, false],
            ['Cyril', ContactSource::ContactForm, ContactStatus::New, false, false],
            ['Daphné', ContactSource::RecruiterQuestion, ContactStatus::Read, false, false],
            ['Élio', ContactSource::RecruiterQuestion, ContactStatus::Read, false, false],
            ['Fanny', ContactSource::ContactForm, ContactStatus::Read, true, false],
            ['Gaspard', ContactSource::ContactForm, ContactStatus::Read, false, true],
        ];
        foreach ($rows as $index => [$name, $source, $status, $replied, $archived]) {
            $received = $now->modify(\sprintf('-%d hours', $index + 1));
            $message = (new ContactMessage($received))->setName($name.' Essai')->setEmail(strtolower($name).'.'.$set.'@'.self::EMAIL_DOMAIN)
                ->setCompany('Essai E2E '.$set.' fin')->setSubject(ContactSubject::Technical)->setMessage('Message de test de bout en bout ('.$name.').')
                ->setSource($source)->setStatus($status)->setConsentAt($received)
                ->setRepliedAt($replied ? $received : null)->setArchivedAt($archived ? $received : null);
            $this->entityManager->persist($message);
        }
        $this->entityManager->flush();
        $output->writeln(\sprintf('%d messages de test créés (Essai E2E %s).', \count($rows), $set));

        return Command::SUCCESS;
    }

    /**
     * Supprime tous les messages de test.
     */
    private function cleanup(OutputInterface $output): int
    {
        $output->writeln(\sprintf('%d message(s) de test supprimé(s).', $this->delete('%@'.self::EMAIL_DOMAIN)));

        return Command::SUCCESS;
    }

    /**
     * Supprime les messages dont l'adresse correspond au motif SQL.
     *
     * @return int nombre de messages supprimés
     */
    private function delete(string $pattern): int
    {
        return (int) $this->entityManager->getConnection()->executeStatement('DELETE FROM contact_message WHERE email LIKE ?', [$pattern]);
    }
}
