<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\FailedEmail;
use App\Entity\Recruiter;
use App\Repository\FailedEmailRepository;
use App\Service\Recruiter\RecruiterMailer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Email;

/**
 * Renvoie les e-mails gardés après un échec SMTP (bouton « Renvoyer » du tableau de bord de l'admin).
 *
 * - E-mails à lien de connexion (lien, validation, prolongation) : recréés avec un lien NEUF (l'ancien a pu
 *   expirer ou servir) ; si le recruteur n'existe plus ou n'a plus d'accès ouvert, l'e-mail est abandonné.
 * - Autres e-mails : renvoyés tels quels.
 * Un e-mail parti est supprimé ; un nouvel échec est noté (tentatives, erreur) et l'e-mail reste.
 *
 * Liens :
 * - Appelé par : Admin\MailController::resend()
 * - Utilise : FailedEmailRepository, RecruiterMailer (liens neufs), ResilientMailer::sendOrFail()
 */
final readonly class FailedEmailResender
{
    /** E-mails dont le lien de connexion est recréé au renvoi. */
    private const LINK_KINDS = [ResilientMailer::KIND_LOGIN_LINK, ResilientMailer::KIND_APPROVAL, ResilientMailer::KIND_RENEWAL];

    /**
     * Reçoit les dépendances.
     */
    public function __construct(
        private FailedEmailRepository $repository,
        private ResilientMailer $mailer,
        private RecruiterMailer $recruiterMailer,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Renvoie tous les e-mails gardés, du plus ancien au plus récent.
     *
     * Liens :
     * - Appelée par : Admin\MailController::resend()
     *
     * @return array{sent: int, failed: int, dropped: int} partis, encore en échec, abandonnés (recruteur absent)
     */
    public function resendAll(): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'dropped' => 0];
        foreach ($this->repository->findAllOldestFirst() as $failed) {
            $outcome = \in_array($failed->getKind(), self::LINK_KINDS, true) ? $this->resendWithNewLink($failed) : $this->resendAsIs($failed);
            ++$result[$outcome];
        }
        $this->entityManager->flush();

        return $result;
    }

    /**
     * Recrée l'e-mail avec un lien neuf (le nouvel échec éventuel est gardé par RecruiterMailer).
     *
     * @return 'sent'|'failed'|'dropped'
     */
    private function resendWithNewLink(FailedEmail $failed): string
    {
        $recruiter = null === $failed->getRecruiterId() ? null : $this->entityManager->find(Recruiter::class, $failed->getRecruiterId());
        $this->entityManager->remove($failed);
        if (!$recruiter instanceof Recruiter || !$recruiter->hasActiveAccess($this->clock->now())) {
            return 'dropped';
        }
        $sent = ResilientMailer::KIND_LOGIN_LINK === $failed->getKind()
            ? $this->recruiterMailer->sendLoginLink($recruiter)
            : $this->recruiterMailer->sendApproval($recruiter, ResilientMailer::KIND_RENEWAL === $failed->getKind());

        return $sent ? 'sent' : 'failed';
    }

    /**
     * Renvoie l'e-mail gardé tel quel.
     *
     * @return 'sent'|'failed'
     */
    private function resendAsIs(FailedEmail $failed): string
    {
        $email = unserialize($failed->getPayload(), ['allowed_classes' => true]);
        if (!$email instanceof Email) {
            $this->entityManager->remove($failed);

            return 'failed';
        }
        try {
            $this->mailer->sendOrFail($email, $failed->getKind());
            $this->entityManager->remove($failed);

            return 'sent';
        } catch (TransportExceptionInterface $exception) {
            $error = $this->mailer->describe($exception);
            $failed->recordFailure($error);
            $this->logger->error('Renvoi de l\'e-mail « {kind} » impossible : {error}', ['kind' => $failed->getKind(), 'error' => $error]);

            return 'failed';
        }
    }
}
