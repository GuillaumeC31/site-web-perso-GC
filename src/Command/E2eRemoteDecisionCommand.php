<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Recruiter;
use App\Enum\NotificationChannel;
use App\Enum\RecruiterStatus;
use App\Repository\SiteSettingsRepository;
use App\Service\Recruiter\RemoteDecisionExecutor;
use App\Service\Recruiter\RemoteDecisionTokens;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\PasswordHasher\Hasher\NativePasswordHasher;

/**
 * Aides du parcours Playwright des liens Discord de la validation à distance. Commande enregistrée SEULEMENT
 * en développement (#[When('dev')]) : elle n'existe pas en production. Aucune notification n'est envoyée :
 * les jetons sont émis directement.
 *
 * - prepare : garde l'état des réglages (interrupteur, empreinte du code PIN) dans var/e2e/, active la
 *   validation à distance avec un code PIN FICTIF, crée une demande en attente (@e2e-distance.example.test) et
 *   écrit en JSON les jetons Discord « accepter » et « refuser » et le code PIN ;
 * - cleanup : supprime les demandes de test (jetons supprimés avec elles) et remet les réglages en l'état,
 *   seulement s'ils sont encore ceux du parcours (un changement fait entre-temps dans Paramètres est gardé).
 *
 * Usage : php bin/console app:e2e:remote-decision prepare|cleanup
 *
 * Liens :
 * - Appelée par : tests/e2e/remote-decision.spec.js
 * - Utilise : RemoteDecisionTokens::issue(), RemoteDecisionExecutor::hashPin()
 */
#[When(env: 'dev')]
#[AsCommand(name: 'app:e2e:remote-decision', description: 'Développement : aides du parcours Playwright de la validation à distance (prepare / cleanup)')]
final class E2eRemoteDecisionCommand extends Command
{
    /** Domaine des demandes de test (RFC 2606). */
    public const EMAIL_DOMAIN = 'e2e-distance.example.test';

    /** Code PIN fictif du parcours. */
    public const FICTIVE_PIN = '135792';

    /** État des réglages avant le parcours, relatif au dossier du projet. */
    private const STATE_FILE = '/var/e2e/remote-decision-settings.json';

    /**
     * Reçoit les dépendances.
     *
     * @param string $projectDir dossier du projet (état des réglages)
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SiteSettingsRepository $settingsRepository,
        private readonly RemoteDecisionTokens $tokens,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    /**
     * Déclare l'action.
     */
    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::REQUIRED, 'prepare ou cleanup');
    }

    /**
     * Exécute l'action demandée.
     *
     * Liens :
     * - Appelée par : la console Symfony (Playwright)
     *
     * @return int Command::SUCCESS, ou Command::INVALID si l'action est inconnue
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return match ((string) $input->getArgument('action')) {
            'prepare' => $this->prepare($output),
            'cleanup' => $this->cleanup($output),
            default => Command::INVALID,
        };
    }

    /**
     * Active la validation à distance (code PIN fictif), crée une demande et écrit ses jetons en JSON.
     */
    private function prepare(OutputInterface $output): int
    {
        $settings = $this->settingsRepository->getOrCreate();
        $state = $this->projectDir.self::STATE_FILE;
        // Un seul état gardé : celui d'avant le premier « prepare » (jamais l'état fictif)
        if (!is_file($state)) {
            if (!is_dir(\dirname($state))) {
                mkdir(\dirname($state), 0700, true);
            }
            file_put_contents($state, json_encode(['enabled' => $settings->isRemoteValidationEnabled(), 'pin_hash' => $settings->getRemotePinHash()], \JSON_THROW_ON_ERROR));
        }
        $settings->setRemoteValidationEnabled(true)->setRemotePinHash(RemoteDecisionExecutor::hashPin(self::FICTIVE_PIN));
        $recruiter = (new Recruiter(new \DateTimeImmutable()))->setEmail('demande-'.bin2hex(random_bytes(4)).'@'.self::EMAIL_DOMAIN)
            ->setFullName('Camille Essai')->setCompany('Acme Essais')->setJobTitle('Chargée de recrutement')
            ->setCompanyWebsite('https://www.acme.example')->setMessage('Poste de responsable de bureau d\'études à Lyon.')
            ->setStatus(RecruiterStatus::Pending);
        $this->entityManager->persist($recruiter);
        $this->entityManager->flush();

        $output->writeln(json_encode(['tokens' => $this->tokens->issue($recruiter, NotificationChannel::Discord), 'pin' => self::FICTIVE_PIN, 'name' => \sprintf('%s (%s)', $recruiter->getFullName(), $recruiter->getCompany())], \JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }

    /**
     * Supprime les demandes de test et remet les réglages d'avant le parcours.
     */
    private function cleanup(OutputInterface $output): int
    {
        $recruiters = $this->entityManager->getRepository(Recruiter::class)->createQueryBuilder('r')
            ->where('r.email LIKE :domain')->setParameter('domain', '%@'.self::EMAIL_DOMAIN)
            ->getQuery()->getResult();
        foreach ($recruiters as $recruiter) {
            $this->entityManager->remove($recruiter);
        }
        $state = $this->projectDir.self::STATE_FILE;
        if (is_file($state)) {
            $previous = json_decode((string) file_get_contents($state), true, flags: \JSON_THROW_ON_ERROR);
            $settings = $this->settingsRepository->getOrCreate();
            $hash = $settings->getRemotePinHash();
            // Réglages remis en l'état SEULEMENT s'ils sont encore ceux du parcours (code PIN fictif) : un
            // changement fait par Guillaume pendant la suite n'est jamais écrasé
            if (null !== $hash && (new NativePasswordHasher())->verify($hash, self::FICTIVE_PIN)) {
                $settings->setRemoteValidationEnabled((bool) $previous['enabled'])->setRemotePinHash($previous['pin_hash']);
            }
            unlink($state);
        }
        $this->entityManager->flush();
        $output->writeln(\sprintf('%d demande(s) de test supprimée(s), réglages de la validation à distance remis en l\'état.', \count($recruiters)));

        return Command::SUCCESS;
    }
}
