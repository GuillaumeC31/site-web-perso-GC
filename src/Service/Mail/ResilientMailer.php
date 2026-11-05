<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\EmailLog;
use App\Entity\FailedEmail;
use App\Entity\Heartbeat;
use App\Enum\MailCategory;
use App\Repository\HeartbeatRepository;
use App\Service\Dev\TestContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\BodyRendererInterface;
use Symfony\Component\Mime\Email;

/**
 * Envoi des e-mails du site sans jamais faire échouer la page : si le serveur SMTP est injoignable (ou
 * refuse l'e-mail), l'erreur est journalisée SANS donnée personnelle (nature de l'e-mail et message du
 * transport seulement), l'e-mail est gardé (FailedEmail) et l'admin voit « X e-mails non envoyés » sur son
 * tableau de bord, avec un bouton « Renvoyer ». Le visiteur, lui, voit la réponse normale (neutre).
 *
 * L'e-mail est rendu (gabarits Twig) AVANT l'envoi : on garde ainsi exactement ce qui aurait dû partir.
 *
 * Chaque envoi, réussi ou non, est noté dans l'historique des envois (EmailLog : date, nature, catégorie,
 * destinataire MASQUÉ, statut ; jamais l'objet, le contenu ni les liens), conservé 90 jours.
 *
 * Liens :
 * - Utilisé par : RecruiterMailer, NotificationDispatcher, DashboardWeeklySummaryCommand
 * - Utilise : MailerInterface (envoi synchrone), BodyRendererInterface, FailedEmail, EmailLog (historique),
 *   HeartbeatRepository (date du dernier e-mail parti, tuile « Communication » du tableau de bord)
 * - Voir aussi : FailedEmailResender (renvoi), Admin\MailController (renvoi), Admin\CommunicationController
 *   (e-mails de test, historique)
 */
final readonly class ResilientMailer
{
    /** Accusé de réception d'une demande d'accès. */
    public const KIND_REQUEST_RECEIVED = 'recruiter_request_received';

    /** Demande déjà en cours d'examen (nouvelle demande d'une adresse en attente). */
    public const KIND_REQUEST_PENDING = 'recruiter_request_pending';

    /** Lien de connexion (recréé au renvoi). */
    public const KIND_LOGIN_LINK = 'recruiter_login_link';

    /** Accès validé, avec lien de connexion (recréé au renvoi). */
    public const KIND_APPROVAL = 'recruiter_approval';

    /** Accès prolongé, avec lien de connexion (recréé au renvoi). */
    public const KIND_RENEWAL = 'recruiter_renewal';

    /** Rappel d'échéance de l'accès. */
    public const KIND_EXPIRY_REMINDER = 'recruiter_expiry_reminder';

    /** Refus d'une demande d'accès (sans adresse de réponse). */
    public const KIND_REFUSAL = 'recruiter_refusal';

    /** Notification à l'admin (demande, message, signal d'intérêt, alerte). */
    public const KIND_NOTIFICATION = 'notification';

    /** Résumé hebdomadaire du tableau de bord. */
    public const KIND_DASHBOARD_SUMMARY = 'dashboard_summary';

    /** E-mail de test (Communication › E-mails). */
    public const KIND_TEST = 'test';

    /**
     * Reçoit les dépendances.
     *
     * @param MailerInterface        $mailer        envoi (synchrone)
     * @param BodyRendererInterface  $bodyRenderer  rendu des gabarits Twig de l'e-mail
     * @param EntityManagerInterface $entityManager enregistrement des e-mails non envoyés
     * @param LoggerInterface        $logger        journal des échecs
     * @param ClockInterface         $clock         date de l'échec
     * @param HeartbeatRepository    $heartbeats    date du dernier e-mail parti (null : non notée)
     * @param TestContext|null       $testContext   repère des e-mails de test (étiquette Mailpit, aucun battement)
     */
    public function __construct(
        private MailerInterface $mailer,
        private BodyRendererInterface $bodyRenderer,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private ClockInterface $clock,
        private ?HeartbeatRepository $heartbeats = null,
        private ?TestContext $testContext = null,
    ) {
    }

    /**
     * Envoie l'e-mail ; en cas d'échec du transport, le garde pour un renvoi et renvoie faux (sans exception).
     *
     * Liens :
     * - Appelée par : RecruiterMailer::send*(), NotificationDispatcher::notify()
     *
     * @param Email             $email       e-mail à envoyer (TemplatedEmail : rendu ici)
     * @param string            $kind        nature (self::KIND_*), pour le journal et le renvoi
     * @param int|null          $recruiterId recruteur concerné (e-mails à lien de connexion), ou null
     * @param MailCategory|null $category    catégorie pour l'historique (null : déduite de la nature)
     *
     * @return bool vrai si l'e-mail est parti ; faux s'il a été gardé pour un renvoi
     */
    public function send(Email $email, string $kind, ?int $recruiterId = null, ?MailCategory $category = null): bool
    {
        $this->bodyRenderer->render($email);
        $test = $this->tagIfTest($email);
        try {
            $this->mailer->send($email);
            $this->recordSent($kind, $test);
            $this->log($email, $kind, $category, true);

            return true;
        } catch (TransportExceptionInterface $exception) {
            $error = $this->describe($exception);
            // Journal : nature de l'e-mail et erreur du transport, jamais le destinataire ni le contenu
            $this->logger->error('E-mail « {kind} » non envoyé, gardé pour un renvoi : {error}', ['kind' => $kind, 'error' => $error]);
            $failed = (new FailedEmail($this->clock->now(), $kind, $this->recipient($email), (string) $email->getSubject(), serialize(self::plainCopy($email)), $recruiterId))
                ->setLastError($error);
            $this->entityManager->persist($failed);
            $this->entityManager->flush();
            $this->log($email, $kind, $category, false);

            return false;
        }
    }

    /**
     * Envoie un e-mail tel quel, en laissant passer l'erreur du transport (renvoi, e-mail de test).
     *
     * Liens :
     * - Appelée par : FailedEmailResender::resendAll(), Admin\CommunicationController::sendTestEmail()
     *
     * @param string            $kind     nature (self::KIND_*, ou « direct »), pour l'historique
     * @param MailCategory|null $category catégorie pour l'historique (null : déduite de la nature)
     *
     * @throws TransportExceptionInterface si le serveur SMTP est injoignable ou refuse l'e-mail
     */
    public function sendOrFail(Email $email, string $kind = 'direct', ?MailCategory $category = null): void
    {
        $this->bodyRenderer->render($email);
        $test = $this->tagIfTest($email);
        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $exception) {
            $this->log($email, $kind, $category, false);

            throw $exception;
        }
        $this->recordSent($kind, $test);
        $this->log($email, $kind, $category, true);
    }

    /**
     * Repère un e-mail des tests de bout en bout (requête de test, ou destinataire en example.test) et lui ajoute
     * l'étiquette Mailpit « e2e-trace » (en-tête X-Tags), seul critère de sa suppression en fin de suite.
     *
     * @return bool vrai si l'e-mail vient des tests
     */
    private function tagIfTest(Email $email): bool
    {
        if (null === $this->testContext || !$this->testContext->isDevelopment()
            || (!$this->testContext->isTestRequest() && !TestContext::isTestAddress($this->recipient($email)))) {
            return false;
        }
        if (!$email->getHeaders()->has('X-Tags')) {
            $email->getHeaders()->addTextHeader('X-Tags', TestContext::MAILPIT_TAG);
        }

        return true;
    }

    /**
     * Déduit la catégorie d'un e-mail de sa nature : e-mails aux recruteurs → Recrutement ; résumé → Général.
     *
     * @return MailCategory|null null si la nature ne suffit pas (notification, test, renvoi)
     */
    public static function categoryOfKind(string $kind): ?MailCategory
    {
        return match (true) {
            str_starts_with($kind, 'recruiter_') => MailCategory::Recruitment,
            self::KIND_DASHBOARD_SUMMARY === $kind => MailCategory::General,
            default => null,
        };
    }

    /**
     * Note l'envoi dans l'historique (destinataire masqué) ; une erreur de la base n'empêche jamais l'envoi.
     *
     * @param bool $success vrai si l'e-mail est parti
     */
    private function log(Email $email, string $kind, ?MailCategory $category, bool $success): void
    {
        try {
            $this->entityManager->persist(new EmailLog($this->clock->now(), $kind, $category ?? self::categoryOfKind($kind), $this->recipient($email), $success));
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $this->logger->warning('Envoi non noté dans l\'historique : {error}', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * Note la date du dernier e-mail parti (tableau de bord) ; une erreur de la base n'empêche pas l'envoi. Un e-mail
     * des tests de bout en bout n'est pas noté (le battement n'a ainsi jamais à être remis en état).
     *
     * @param string $kind nature de l'e-mail (self::KIND_*, ou « direct » : renvoi, e-mail de test)
     * @param bool   $test vrai : e-mail des tests (Dev\TestContext)
     */
    private function recordSent(string $kind, bool $test): void
    {
        if ($test) {
            return;
        }
        try {
            $this->heartbeats?->beat(Heartbeat::MAIL_SENT, true, ['kind' => $kind]);
        } catch (\Throwable $exception) {
            $this->logger->warning('Date du dernier e-mail parti non notée : {error}', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * Message court d'une erreur du transport, sans donnée personnelle (le transport ne cite que l'hôte).
     */
    public function describe(TransportExceptionInterface $exception): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/', ' ', $exception->getMessage())), 0, 200);
    }

    /**
     * Copie simple d'un e-mail rendu (sans le contexte des gabarits, qui contient des entités) : expéditeur,
     * destinataires, réponse, objet, textes. Sérialisable pour le renvoi.
     */
    public static function plainCopy(Email $email): Email
    {
        return (new Email())
            ->from(...$email->getFrom())
            ->to(...$email->getTo())
            ->replyTo(...$email->getReplyTo())
            ->subject((string) $email->getSubject())
            ->text($email->getTextBody())
            ->html($email->getHtmlBody());
    }

    /**
     * Premier destinataire de l'e-mail.
     */
    private function recipient(Email $email): string
    {
        $to = $email->getTo();

        return [] === $to ? '' : $to[0]->getAddress();
    }
}
