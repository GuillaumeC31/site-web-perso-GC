<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Heartbeat;
use App\Enum\EmailType;
use App\Enum\NotificationEvent;
use App\Repository\EmailLogRepository;
use App\Repository\HeartbeatRepository;
use App\Repository\SiteSettingsRepository;
use App\Service\Notification\Notification;
use App\Service\Notification\NotificationDispatcher;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Plafond quotidien des e-mails envoyés à des personnes non validées (décision du 2026-10-29) : accusé de réception,
 * « demande déjà en cours d'examen » et refus (EmailType::isForUnvalidatedRequest()). Protège la réputation du domaine
 * si quelqu'un remplit le formulaire en masse avec les adresses d'autres personnes.
 *
 * Au-delà du plafond (Paramètres › Communication › E-mails, 30 par défaut), ces e-mails ne partent plus jusqu'au
 * lendemain (minuit, heure du serveur) ; les demandes restent enregistrées et visibles dans l'admin. La première
 * suspension du jour prévient Guillaume (événement EmailCap, « Général du site » : e-mail, Telegram, Discord selon les
 * canaux cochés) et le tableau de bord l'affiche (tuile Communication). Jamais appliqué aux recruteurs validés (lien
 * de connexion, validation, rappels) ni aux notifications de Guillaume.
 *
 * Liens :
 * - Utilisée par : Recruiter\RecruiterMailer::send()
 * - Utilise : EmailLogRepository::countKindsSince() (historique des envois), SiteSettingsRepository (plafond),
 *   HeartbeatRepository (Heartbeat::EMAIL_CAP : jour et nombre de suspensions), NotificationDispatcher
 * - Voir aussi : Dashboard\DashboardMetrics::communication()
 * - Testée par : tests/Service/Mail/UnvalidatedEmailCapTest
 */
final readonly class UnvalidatedEmailCap
{
    /**
     * Reçoit les dépendances.
     */
    public function __construct(
        private EmailLogRepository $emailLogs,
        private SiteSettingsRepository $settingsRepository,
        private HeartbeatRepository $heartbeats,
        private NotificationDispatcher $notifier,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Dit si un e-mail peut partir ; sinon note la suspension (et prévient Guillaume la première fois du jour).
     *
     * Liens :
     * - Appelée par : RecruiterMailer::send(), juste avant l'envoi
     *
     * @param EmailType $type type de l'e-mail
     *
     * @return bool vrai si l'e-mail peut partir (toujours vrai hors des e-mails aux personnes non validées)
     */
    public function allows(EmailType $type): bool
    {
        if (!$type->isForUnvalidatedRequest()) {
            return true;
        }
        $cap = $this->settingsRepository->getOrCreate()->getUnvalidatedEmailDailyCap();
        if ($this->countToday() < $cap) {
            return true;
        }
        $this->suspend($type, $cap);

        return false;
    }

    /**
     * Compte les e-mails aux personnes non validées partis aujourd'hui (réussis ou non).
     *
     * Liens :
     * - Appelée par : allows()
     */
    public function countToday(): int
    {
        return $this->emailLogs->countKindsSince(self::kinds(), $this->clock->now()->setTime(0, 0));
    }

    /**
     * Renvoie le nombre d'e-mails suspendus aujourd'hui (0 : plafond non atteint aujourd'hui).
     *
     * Liens :
     * - Appelée par : DashboardMetrics::communication() (avec les passages déjà lus)
     *
     * @param Heartbeat|null $heartbeat passage Heartbeat::EMAIL_CAP, ou null s'il n'y en a jamais eu
     */
    public function suspendedToday(?Heartbeat $heartbeat): int
    {
        $details = $heartbeat?->getDetails() ?? [];

        return ($details['day'] ?? null) === $this->clock->now()->format('Y-m-d') ? (int) ($details['suspended'] ?? 0) : 0;
    }

    /**
     * Natures (historique des envois) des e-mails concernés par le plafond.
     *
     * @return list<string>
     */
    public static function kinds(): array
    {
        return array_values(array_map(static fn (EmailType $type): string => $type->kind(), array_filter(EmailType::cases(), static fn (EmailType $type): bool => $type->isForUnvalidatedRequest())));
    }

    /**
     * Note une suspension du jour ; la première prévient Guillaume (chiffres seulement, aucune donnée personnelle).
     *
     * @param int $cap plafond en vigueur
     */
    private function suspend(EmailType $type, int $cap): void
    {
        $day = $this->clock->now()->format('Y-m-d');
        $suspended = $this->suspendedToday($this->heartbeats->findAllByName()[Heartbeat::EMAIL_CAP] ?? null) + 1;
        $this->heartbeats->beat(Heartbeat::EMAIL_CAP, false, ['day' => $day, 'suspended' => $suspended]);
        $this->logger->warning('Plafond des e-mails aux personnes non validées atteint ({cap} par jour) : e-mail « {kind} » suspendu jusqu\'à demain.', ['cap' => $cap, 'kind' => $type->kind()]);
        if (1 !== $suspended) {
            return;
        }
        $this->notifier->notify(new Notification(
            NotificationEvent::EmailCap,
            $this->translator->trans(NotificationEvent::EmailCap->translationKey()),
            [
                $this->translator->trans('admin.email_cap.notification.cap') => (string) $cap,
                $this->translator->trans('admin.email_cap.notification.effect') => $this->translator->trans('admin.email_cap.notification.effect_text'),
            ],
            $this->urlGenerator->generate('admin_communication_emails', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ));
    }
}
