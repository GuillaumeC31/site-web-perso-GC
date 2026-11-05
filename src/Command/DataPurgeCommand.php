<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\EmailLog;
use App\Entity\Heartbeat;
use App\Repository\AiUsageRepository;
use App\Repository\ChatMessageRepository;
use App\Repository\ContactMessageRepository;
use App\Repository\EmailLogRepository;
use App\Repository\HeartbeatRepository;
use App\Repository\RecruiterRepository;
use App\Repository\UnansweredQuestionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Durées de conservation des données personnelles (RGPD, CDC §10), à lancer chaque jour (cron) :
 * - demandes recruteurs refusées : supprimées 30 jours après la décision ;
 * - autres recruteurs sans accès ouvert : supprimés 12 mois après leur dernière activité ;
 * - messages de contact : supprimés 12 mois après leur réception ;
 * - journal de l'assistant IA (échanges et consommation) : supprimé après 12 mois (lot 7) ;
 * - adresses IP (demandes, messages) : effacées après 30 jours ;
 * - historique des envois d'e-mails (destinataires masqués) : supprimé après 90 jours (EmailLog::RETENTION_DAYS).
 *
 * Usage : php bin/console app:data:purge
 *
 * Liens :
 * - Utilise : RecruiterRepository, ContactMessageRepository, ChatMessageRepository, AiUsageRepository, EmailLogRepository
 * - Voir aussi : page /confidentialite (LegalController::privacy())
 */
#[AsCommand(name: 'app:data:purge', description: 'Supprime les données personnelles arrivées au terme de leur durée de conservation')]
final class DataPurgeCommand extends Command
{
    /** Conservation d'une demande refusée (jours). */
    public const REFUSED_DAYS = 30;

    /** Conservation des recruteurs inactifs, des messages et du journal de l'assistant (mois). */
    public const RETENTION_MONTHS = 12;

    /** Conservation des adresses IP (jours). */
    public const IP_DAYS = 30;

    /**
     * Reçoit les dépendances (dont HeartbeatRepository : passage noté pour le tableau de bord).
     */
    public function __construct(
        private readonly RecruiterRepository $recruiterRepository,
        private readonly ContactMessageRepository $contactMessageRepository,
        private readonly ChatMessageRepository $chatMessageRepository,
        private readonly AiUsageRepository $aiUsageRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly HeartbeatRepository $heartbeats,
        private readonly UnansweredQuestionRepository $unansweredRepository,
        private readonly EmailLogRepository $emailLogRepository,
    ) {
        parent::__construct();
    }

    /**
     * Supprime et anonymise, puis affiche le bilan.
     *
     * Liens :
     * - Appelée par : la console Symfony (cron quotidien)
     *
     * @return int Command::SUCCESS
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = $this->clock->now();

        $recruiters = $this->recruiterRepository->findToPurge(
            $now->modify(\sprintf('-%d days', self::REFUSED_DAYS)),
            $now->modify(\sprintf('-%d months', self::RETENTION_MONTHS)),
        );
        foreach ($recruiters as $recruiter) {
            $this->entityManager->remove($recruiter);
        }
        $this->entityManager->flush();
        $retentionLimit = $now->modify(\sprintf('-%d months', self::RETENTION_MONTHS));
        $messages = $this->contactMessageRepository->deleteBefore($retentionLimit);
        $chatMessages = $this->chatMessageRepository->deleteBefore($retentionLimit);
        $aiUsages = $this->aiUsageRepository->deleteBefore($retentionLimit);
        // Questions sans réponse de l'assistant : même durée que le journal
        $chatMessages += $this->unansweredRepository->deleteBefore($retentionLimit);
        $ipBefore = $now->modify(\sprintf('-%d days', self::IP_DAYS));
        $ips = $this->recruiterRepository->clearIpAddressesBefore($ipBefore) + $this->contactMessageRepository->clearIpAddressesBefore($ipBefore);
        $emailLogs = $this->emailLogRepository->deleteBefore($now->modify(\sprintf('-%d days', EmailLog::RETENTION_DAYS)));

        $this->heartbeats->beat(Heartbeat::DATA_PURGE, true, ['recruiters' => \count($recruiters), 'messages' => $messages]);
        $io->success(\sprintf(
            '%d recruteur(s) et %d message(s) supprimés, %d échange(s) et %d ligne(s) de consommation de l\'assistant supprimés, %d adresse(s) IP effacée(s), %d envoi(s) d\'e-mail retirés de l\'historique.',
            \count($recruiters), $messages, $chatMessages, $aiUsages, $ips, $emailLogs,
        ));

        return Command::SUCCESS;
    }
}
