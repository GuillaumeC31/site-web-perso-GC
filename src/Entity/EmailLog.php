<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TestTraceTrait;
use App\Enum\MailCategory;
use App\Repository\EmailLogRepository;
use App\Service\Dev\TestContext;
use Doctrine\ORM\Mapping as ORM;

/**
 * Historique des envois d'e-mails, affiché dans Communication › E-mails.
 *
 * Une ligne par envoi : date, type, catégorie, destinataire masqué (« j***@entreprise.fr »), envoyé ou en échec.
 * Jamais l'objet, le contenu ni les liens de connexion. Conservé 90 jours (app:data:purge).
 *
 * Liens :
 * - Écrite par : Service\Mail\ResilientMailer (chaque envoi, réussi ou non)
 * - Lue par : Admin\CommunicationController (50 derniers envois, dernier envoi réussi)
 * - Purge : Command\DataPurgeCommand (EmailLogRepository::deleteBefore())
 */
#[ORM\Entity(repositoryClass: EmailLogRepository::class)]
#[ORM\Table(name: 'email_log')]
#[ORM\Index(name: 'idx_email_log_sent_at', columns: ['sent_at'])]
class EmailLog implements TestTraceableInterface
{
    use TestTraceTrait;

    /** Durée de conservation (jours). */
    public const RETENTION_DAYS = 90;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $sentAt;

    /** Nature de l'e-mail (ResilientMailer::KIND_*, ex. « recruiter_login_link »). */
    #[ORM\Column(length: 40)]
    private string $kind;

    #[ORM\Column(length: 20, nullable: true, enumType: MailCategory::class)]
    private ?MailCategory $category;

    /** Destinataire masqué (self::maskAddress()). */
    #[ORM\Column(length: 190)]
    private string $recipient;

    #[ORM\Column]
    private bool $success;

    /**
     * Crée une ligne d'historique ; le destinataire est masqué ici, jamais gardé en clair.
     *
     * @param string            $kind      nature de l'e-mail (ResilientMailer::KIND_*)
     * @param MailCategory|null $category  catégorie (null : inconnue, ex. renvoi d'un e-mail ancien)
     * @param string            $recipient adresse du destinataire, masquée avant enregistrement
     * @param bool              $success   vrai si l'e-mail est parti
     */
    public function __construct(\DateTimeImmutable $sentAt, string $kind, ?MailCategory $category, string $recipient, bool $success)
    {
        $this->sentAt = $sentAt;
        $this->kind = mb_substr($kind, 0, 40);
        $this->category = $category;
        $this->recipient = self::maskAddress($recipient);
        $this->success = $success;
        // Destinataire du domaine des tests : repère précis, même hors requête de test (tâche planifiée)
        if (TestContext::isTestAddress($recipient)) {
            $this->markAsTestTrace();
        }
    }

    /**
     * Masque une adresse : première lettre, trois étoiles, domaine (« jean@acme.test » donne « j***@acme.fr »).
     *
     * Liens :
     * - Appelée par : __construct() ; tests/Service/Mail/EmailLogTest
     *
     * @return string adresse masquée ; « *** » si l'adresse n'a pas d'arobase
     */
    public static function maskAddress(string $address): string
    {
        $address = trim($address);
        $at = strrpos($address, '@');
        if (false === $at || 0 === $at) {
            return '***';
        }

        return mb_substr($address, 0, 1).'***'.mb_substr($address, $at, 180);
    }

    /** Renvoie l'identifiant technique. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** Renvoie la date de l'envoi. */
    public function getSentAt(): \DateTimeImmutable
    {
        return $this->sentAt;
    }

    /** Renvoie la nature de l'e-mail. */
    public function getKind(): string
    {
        return $this->kind;
    }

    /** Renvoie la catégorie (null : inconnue). */
    public function getCategory(): ?MailCategory
    {
        return $this->category;
    }

    /** Renvoie le destinataire masqué. */
    public function getRecipient(): string
    {
        return $this->recipient;
    }

    /** Indique si l'e-mail est parti. */
    public function isSuccess(): bool
    {
        return $this->success;
    }
}
