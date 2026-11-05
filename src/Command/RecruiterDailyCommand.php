<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Heartbeat;
use App\Entity\Recruiter;
use App\Enum\RecruiterStatus;
use App\Repository\HeartbeatRepository;
use App\Repository\RecruiterRepository;
use App\Service\Recruiter\InterestSignalNotifier;
use App\Service\Recruiter\RecruiterMailer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Tâches quotidiennes de l'espace recruteur (tâche planifiée de l’hébergeur, une fois par jour) :
 * 1. rappel par e-mail 7 jours avant l'échéance d'un accès, avec un lien signé (14 jours) pour
 *    demander une prolongation et un lien signé « Ne plus recevoir ces rappels » (aucun rappel au recruteur
 *    qui l'a utilisé) ;
 * 2. passage au statut « expiré » des accès échus ;
 * 3. récapitulatif de l'activité des recruteurs de la veille (« Activité d'un recruteur », une notification par
 *    recruteur au plus ; v2.0.3 : jamais titrée « Signal d'intérêt »).
 *
 * Usage : php bin/console app:recruiter:daily
 *
 * Liens :
 * - Utilise : RecruiterRepository, RecruiterMailer, InterestSignalNotifier, UriSigner
 * - Voir aussi : RecruiterAreaController::extension() (route recruiter_extension),
 *   RecruiterAreaController::stopReminders() (route recruiter_reminders_stop)
 */
#[AsCommand(name: 'app:recruiter:daily', description: 'Rappels d\'échéance, accès échus et récapitulatif de l\'activité des recruteurs')]
final class RecruiterDailyCommand extends Command
{
    /** Validité du lien de prolongation (jours). */
    public const EXTENSION_LINK_DAYS = 14;

    /**
     * Reçoit les dépendances (dont HeartbeatRepository : passage noté pour le tableau de bord).
     */
    public function __construct(
        private readonly RecruiterRepository $recruiterRepository,
        private readonly RecruiterMailer $mailer,
        private readonly InterestSignalNotifier $interestNotifier,
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly UriSigner $uriSigner,
        private readonly ClockInterface $clock,
        private readonly HeartbeatRepository $heartbeats,
    ) {
        parent::__construct();
    }

    /**
     * Exécute les trois tâches et affiche le bilan.
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

        $reminders = 0;
        foreach ($this->recruiterRepository->findApprovedExpiringWithoutReminder($now->modify(\sprintf('+%d days', Recruiter::REMINDER_DAYS))) as $recruiter) {
            if ($recruiter->hasActiveAccess($now) && $recruiter->acceptsReminders()) {
                $this->mailer->sendExpiryReminder($recruiter, $this->extensionUrl($recruiter, $now), $this->stopRemindersUrl($recruiter));
                $recruiter->setReminderSentAt($now);
                ++$reminders;
            }
        }

        $expired = 0;
        foreach ($this->recruiterRepository->findApprovedExpired($now) as $recruiter) {
            $recruiter->setStatus(RecruiterStatus::Expired)->touch($now);
            ++$expired;
        }
        $this->entityManager->flush();

        $digests = $this->interestNotifier->sendDigest();
        $this->heartbeats->beat(Heartbeat::RECRUITER_DAILY, true, ['reminders' => $reminders, 'expired' => $expired]);
        $io->success(\sprintf('%d rappel(s) d\'échéance, %d accès échu(s), %d récapitulatif(s) d\'activité.', $reminders, $expired, $digests));

        return Command::SUCCESS;
    }

    /**
     * Lien signé de demande de prolongation, valable 14 jours.
     *
     * Liens :
     * - Appelée par : execute()
     */
    private function extensionUrl(Recruiter $recruiter, \DateTimeImmutable $now): string
    {
        $url = $this->urlGenerator->generate('recruiter_extension', ['id' => $recruiter->getId()], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->uriSigner->sign($url, $now->modify(\sprintf('+%d days', self::EXTENSION_LINK_DAYS)));
    }

    /**
     * Lien signé « Ne plus recevoir ces rappels », sans date limite (il doit rester valable tant que l'e-mail existe).
     *
     * Liens :
     * - Appelée par : execute()
     * - Utilise : route recruiter_reminders_stop (RecruiterAreaController::stopReminders())
     */
    private function stopRemindersUrl(Recruiter $recruiter): string
    {
        return $this->uriSigner->sign($this->urlGenerator->generate('recruiter_reminders_stop', ['id' => $recruiter->getId()], UrlGeneratorInterface::ABSOLUTE_URL));
    }
}
