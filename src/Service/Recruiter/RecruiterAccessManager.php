<?php

declare(strict_types=1);

namespace App\Service\Recruiter;

use App\Entity\Recruiter;
use App\Enum\NotificationEvent;
use App\Enum\RecruiterStatus;
use App\Repository\RecruiterRepository;
use App\Repository\SiteSettingsRepository;
use App\Service\Notification\Notification;
use App\Service\Notification\NotificationDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Cycle de vie des accès à l'espace recruteur (CDC §7) : demande (avec accusé de réception), validation
 * (90 jours), refus, révocation, renouvellement, création manuelle, demande de prolongation, envoi du lien
 * de connexion, suppression de ses données par le recruteur.
 *
 * Règle de confidentialité : les actions publiques (demande, lien de connexion) donnent toujours la
 * même réponse au visiteur, que l'e-mail soit connu ou non ; seul ce service sait ce qui s'est passé.
 *
 * Liens :
 * - Utilisé par : RecruiterAreaController, Admin\RecruiterController, RemoteDecisionExecutor (décision à distance)
 * - Utilise : RecruiterRepository, RecruiterScorer, RecruiterMailer, NotificationDispatcher, SiteSettingsRepository,
 *   AccessRequestSummary (contenu de la notification d'une nouvelle demande)
 * - Testé par : tests/Controller/RecruiterAreaControllerTest, tests/Controller/Admin/RecruiterAdminTest
 */
final readonly class RecruiterAccessManager
{
    /** Délai minimal entre deux e-mails « demande déjà en cours d'examen » à la même adresse (heures). */
    public const PENDING_NOTICE_HOURS = 24;

    /**
     * Reçoit les dépendances.
     */
    public function __construct(
        private RecruiterRepository $recruiterRepository,
        private SiteSettingsRepository $settingsRepository,
        private RecruiterScorer $scorer,
        private RecruiterMailer $mailer,
        private NotificationDispatcher $notifier,
        private EntityManagerInterface $entityManager,
        private UrlGeneratorInterface $urlGenerator,
        private ClockInterface $clock,
        private AccessRequestSummary $summary,
    ) {
    }

    /**
     * Enregistre une demande d'accès (formulaire public).
     *
     * - adresse inconnue : nouvelle demande, score, notification, accusé de réception au recruteur
     *   (validation automatique si activée : l'e-mail de validation remplace l'accusé) ;
     * - demande déjà en attente : coordonnées et message mis à jour, sans nouvelle notification ni accusé ;
     * - accès déjà ouvert : un lien de connexion est envoyé ;
     * - accès refusé, révoqué ou échu : la demande repasse en attente, est notifiée et un accusé est envoyé.
     *
     * Liens :
     * - Appelée par : RecruiterAreaController::index()
     *
     * @param Recruiter   $data données du formulaire (e-mail, nom, entreprise, message)
     * @param string|null $ip   adresse IP du visiteur
     */
    public function requestAccess(Recruiter $data, ?string $ip): void
    {
        $now = $this->now();
        $recruiter = $this->recruiterRepository->findOneByEmail($data->getEmail());
        if ($recruiter?->hasActiveAccess($now)) {
            $this->mailer->sendLoginLink($recruiter);

            return;
        }
        $isNew = null === $recruiter;
        $wasPending = RecruiterStatus::Pending === $recruiter?->getStatus();
        $recruiter ??= (new Recruiter($now))->setEmail($data->getEmail());
        $recruiter->setFullName($data->getFullName())->setCompany($data->getCompany())->setJobTitle($data->getJobTitle())->setCompanyWebsite($data->getCompanyWebsite())->setMessage($data->getMessage())
            ->setIpAddress($ip)->setConsentAt($now)->setStatus(RecruiterStatus::Pending)->touch($now);
        $scoring = $this->scorer->score($recruiter, $ip);
        $recruiter->setScoring($scoring['score'], $scoring['details']);
        if ($isNew) {
            $this->entityManager->persist($recruiter);
        }
        $this->entityManager->flush();

        $settings = $this->settingsRepository->getOrCreate();
        if ($settings->isAutoApprovalEnabled() && $recruiter->getScore() >= $settings->getAutoApprovalThreshold()) {
            $this->approve($recruiter);
        }
        if (!$wasPending && RecruiterStatus::Pending === $recruiter->getStatus()) {
            $this->mailer->sendRequestReceived($recruiter);
        }
        if ($wasPending) {
            $this->sendPendingNotice($recruiter, $now);
        }
        if (!$wasPending) {
            $this->notifier->notify($this->summary->notification($recruiter));
        }
    }

    /**
     * Demande renvoyée alors que la précédente attend encore : e-mail « déjà en cours d'examen » au plus une
     * fois par 24 h et par adresse (PENDING_NOTICE_HOURS), sans renotifier l'admin. La réponse affichée au
     * visiteur ne change pas.
     *
     * Liens :
     * - Appelée par : requestAccess()
     * - Utilise : RecruiterMailer::sendRequestPending()
     */
    private function sendPendingNotice(Recruiter $recruiter, \DateTimeImmutable $now): void
    {
        $last = $recruiter->getPendingNoticeSentAt();
        if (null !== $last && $last > $now->modify(\sprintf('-%d hours', self::PENDING_NOTICE_HOURS))) {
            return;
        }
        $recruiter->setPendingNoticeSentAt($now);
        $this->entityManager->flush();
        $this->mailer->sendRequestPending($recruiter);
    }

    /**
     * Envoie un lien de connexion si l'accès est ouvert ; sinon ne fait rien (même réponse publique).
     *
     * Liens :
     * - Appelée par : RecruiterAreaController::index() (formulaire « Recevoir un lien »)
     *
     * @return bool vrai si un e-mail est parti (jamais révélé au visiteur)
     */
    public function sendLoginLink(string $email): bool
    {
        $recruiter = $this->recruiterRepository->findOneByEmail($email);
        if (null === $recruiter || !$recruiter->hasActiveAccess($this->now())) {
            return false;
        }
        $this->mailer->sendLoginLink($recruiter);

        return true;
    }

    /**
     * Valide un accès pour 90 jours et envoie l'e-mail de validation avec un lien de connexion.
     *
     * Liens :
     * - Appelée par : Admin\RecruiterController::decide(), requestAccess() (validation automatique), createManually()
     */
    public function approve(Recruiter $recruiter): void
    {
        $now = $this->now();
        $recruiter->setStatus(RecruiterStatus::Approved)->setDecidedAt($now)->setAccessExpiresAt($now->modify(\sprintf('+%d days', Recruiter::ACCESS_DAYS)))
            ->setReminderSentAt(null)->setExtensionRequestedAt(null)->touch($now);
        $this->entityManager->flush();
        $this->mailer->sendApproval($recruiter, false);
    }

    /**
     * Prolonge un accès de 90 jours (à partir de l'échéance si elle n'est pas passée) et prévient le recruteur.
     *
     * Liens :
     * - Appelée par : Admin\RecruiterController::decide()
     */
    public function renew(Recruiter $recruiter): void
    {
        $now = $this->now();
        $from = null !== $recruiter->getAccessExpiresAt() && $recruiter->getAccessExpiresAt() > $now ? $recruiter->getAccessExpiresAt() : $now;
        $recruiter->setStatus(RecruiterStatus::Approved)->setDecidedAt($now)->setAccessExpiresAt($from->modify(\sprintf('+%d days', Recruiter::ACCESS_DAYS)))
            ->setReminderSentAt(null)->setExtensionRequestedAt(null)->touch($now);
        $this->entityManager->flush();
        $this->mailer->sendApproval($recruiter, true);
    }

    /**
     * Refuse une demande et prévient le demandeur par un e-mail sobre, sans motif ni adresse de réponse
     * (décision du 2026-10-29 ; avant, le refus était silencieux).
     *
     * Liens :
     * - Appelée par : Admin\RecruiterController::decide(), RemoteDecisionExecutor (validation à distance)
     * - Utilise : RecruiterMailer::sendRefusal()
     */
    public function refuse(Recruiter $recruiter): void
    {
        $now = $this->now();
        $recruiter->setStatus(RecruiterStatus::Refused)->setDecidedAt($now)->setAccessExpiresAt(null)->touch($now);
        $this->entityManager->flush();
        $this->mailer->sendRefusal($recruiter);
    }

    /**
     * Retire un accès : les liens de connexion en circulation deviennent invalides (statut signé).
     *
     * Liens :
     * - Appelée par : Admin\RecruiterController::decide()
     */
    public function revoke(Recruiter $recruiter): void
    {
        $now = $this->now();
        $recruiter->setStatus(RecruiterStatus::Revoked)->setDecidedAt($now)->setAccessExpiresAt(null)->touch($now);
        $this->entityManager->flush();
    }

    /**
     * Crée un accès à la main (sans demande), validé tout de suite.
     *
     * Liens :
     * - Appelée par : Admin\RecruiterController::new()
     *
     * @param Recruiter $recruiter nouveau recruteur (e-mail, nom, entreprise)
     */
    public function createManually(Recruiter $recruiter): void
    {
        $recruiter->setCreatedManually(true)->setScoring(0, []);
        $this->entityManager->persist($recruiter);
        $this->approve($recruiter);
    }

    /**
     * Enregistre une demande de prolongation (lien de l'e-mail de rappel) et prévient Guillaume.
     *
     * Liens :
     * - Appelée par : RecruiterAreaController::extension()
     *
     * @return bool faux si la demande avait déjà été faite (pas de nouvelle notification)
     */
    public function requestExtension(Recruiter $recruiter): bool
    {
        if (null !== $recruiter->getExtensionRequestedAt()) {
            return false;
        }
        $now = $this->now();
        $recruiter->setExtensionRequestedAt($now)->touch($now);
        $this->entityManager->flush();
        $this->notifier->notify(new Notification(NotificationEvent::Extension, 'Demande de prolongation d\'accès', [
            'Nom' => $recruiter->getFullName(), 'Entreprise' => $recruiter->getCompany(),
            'Échéance' => $recruiter->getAccessExpiresAt()?->format('d/m/Y') ?? '—',
        ], $this->adminUrl($recruiter), senderEmail: $recruiter->getEmail(), emailContact: $recruiter->getEmail()));

        return true;
    }

    /**
     * Supprime toutes les données d'un recruteur, à sa demande (bouton « Supprimer mes données » de son
     * espace) : la fiche, ses évènements et ses échanges avec l'assistant (suppression en cascade dans la
     * base). Guillaume est prévenu, sans aucune donnée personnelle dans la notification.
     *
     * Liens :
     * - Appelée par : RecruiterAreaController::deleteData()
     * - Voir aussi : Admin\RecruiterController::delete() (suppression par l'admin)
     */
    public function deleteBySelf(Recruiter $recruiter): void
    {
        $email = $recruiter->getEmail();
        $this->entityManager->remove($recruiter);
        $this->entityManager->flush();
        $this->notifier->notify(new Notification(
            NotificationEvent::DataDeletion,
            'Un recruteur a supprimé ses données',
            ['Action' => 'Suppression depuis l\'espace recruteur (fiche, évènements et échanges avec l\'assistant)'],
            $this->urlGenerator->generate('admin_recruiter_index', [], UrlGeneratorInterface::ABSOLUTE_URL),
            senderEmail: $email,
        ));
    }

    /**
     * Lien absolu vers la fiche du recruteur dans l'admin (notifications).
     *
     * Liens :
     * - Appelée par : requestAccess(), requestExtension(), InterestSignalNotifier
     */
    public function adminUrl(Recruiter $recruiter): string
    {
        return $this->urlGenerator->generate('admin_recruiter_show', ['id' => $recruiter->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /** Date courante (horloge injectable, pour les tests). */
    private function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }
}
