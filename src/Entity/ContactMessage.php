<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ContactSource;
use App\Enum\ContactStatus;
use App\Enum\ContactSubject;
use App\Repository\ContactMessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Message reçu par le formulaire de contact (TEXTES_SITE § 10), consulté dans l'admin (lu, traité).
 * Conservé 12 mois, adresse IP effacée après 30 jours (app:data:purge).
 *
 * Liens :
 * - Utilisée par : ContactController, ContactType, Admin\ContactMessageController, DataPurgeCommand
 */
#[ORM\Entity(repositoryClass: ContactMessageRepository::class)]
#[ORM\Table(name: 'contact_message')]
class ContactMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $name = '';

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private string $email = '';

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $company = null;

    #[ORM\Column(length: 20, enumType: ContactSubject::class)]
    // Aucun objet par défaut : le visiteur choisit (option « Choisir un objet » du formulaire)
    #[Assert\NotNull(message: 'contact.subject_required')]
    private ?ContactSubject $subject = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 10, max: 5000)]
    private string $message = '';

    #[ORM\Column(length: 20, enumType: ContactStatus::class)]
    private ContactStatus $status = ContactStatus::New;

    /** Adresse IP (limite d'envoi), effacée après 30 jours. */
    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $consentAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** Origine : formulaire de contact public ou question d'un recruteur (filtre « type » de l'admin). */
    #[ORM\Column(length: 30, enumType: ContactSource::class, options: ['default' => 'contact_form'])]
    private ContactSource $source = ContactSource::ContactForm;

    /** Date à laquelle Guillaume a indiqué avoir répondu (depuis webmail ; le site ne peut pas le savoir seul). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $repliedAt = null;

    /** Date d'archivage (null : dans la boîte). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    /**
     * Crée un message à la date donnée.
     *
     * @param \DateTimeImmutable $now date de réception
     */
    public function __construct(\DateTimeImmutable $now)
    {
        $this->createdAt = $now;
    }

    /** Renvoie l'identifiant technique. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** Renvoie le nom. */
    public function getName(): string
    {
        return $this->name;
    }

    /** Définit le nom. */
    public function setName(?string $name): static
    {
        $this->name = trim((string) $name);

        return $this;
    }

    /** Renvoie l'e-mail de l'expéditeur. */
    public function getEmail(): string
    {
        return $this->email;
    }

    /** Définit l'e-mail de l'expéditeur. */
    public function setEmail(?string $email): static
    {
        $this->email = mb_strtolower(trim((string) $email));

        return $this;
    }

    /** Renvoie l'entreprise. */
    public function getCompany(): ?string
    {
        return $this->company;
    }

    /** Définit l'entreprise (vide : null). */
    public function setCompany(?string $company): static
    {
        $company = trim((string) $company);
        $this->company = '' === $company ? null : $company;

        return $this;
    }

    /** Renvoie l'objet. */
    public function getSubject(): ?ContactSubject
    {
        return $this->subject;
    }

    /** Définit l'objet. */
    public function setSubject(?ContactSubject $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    /** Renvoie le message. */
    public function getMessage(): string
    {
        return $this->message;
    }

    /** Définit le message. */
    public function setMessage(?string $message): static
    {
        $this->message = trim((string) $message);

        return $this;
    }

    /** Renvoie le statut. */
    public function getStatus(): ContactStatus
    {
        return $this->status;
    }

    /** Définit le statut. */
    public function setStatus(ContactStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    /** Renvoie l'adresse IP (null après 30 jours). */
    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    /** Définit l'adresse IP. */
    public function setIpAddress(?string $ipAddress): static
    {
        $this->ipAddress = $ipAddress;

        return $this;
    }

    /** Renvoie la date du consentement. */
    public function getConsentAt(): ?\DateTimeImmutable
    {
        return $this->consentAt;
    }

    /** Définit la date du consentement. */
    public function setConsentAt(?\DateTimeImmutable $consentAt): static
    {
        $this->consentAt = $consentAt;

        return $this;
    }

    /** Renvoie la date de réception. */
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Renvoie l'origine du message. */
    public function getSource(): ContactSource
    {
        return $this->source;
    }

    /**
     * Définit l'origine du message.
     *
     * Liens :
     * - Appelée par : RecruiterQuestionController::ask() (question d'un recruteur)
     */
    public function setSource(ContactSource $source): static
    {
        $this->source = $source;

        return $this;
    }

    /** Renvoie la date de réponse (null : pas encore répondu). */
    public function getRepliedAt(): ?\DateTimeImmutable
    {
        return $this->repliedAt;
    }

    /**
     * Marque le message comme répondu (il devient lu), ou non répondu avec null.
     *
     * Liens :
     * - Appelée par : Admin\ContactMessageController (action sur un message, actions groupées)
     */
    public function setRepliedAt(?\DateTimeImmutable $repliedAt): static
    {
        $this->repliedAt = $repliedAt;
        if (null !== $repliedAt) {
            $this->status = ContactStatus::Read;
        }

        return $this;
    }

    /** Indique si le message a reçu une réponse. */
    public function isReplied(): bool
    {
        return null !== $this->repliedAt;
    }

    /** Renvoie la date d'archivage (null : dans la boîte). */
    public function getArchivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    /**
     * Archive le message, ou le remet dans la boîte avec null.
     *
     * Liens :
     * - Appelée par : Admin\ContactMessageController (action sur un message, actions groupées)
     */
    public function setArchivedAt(?\DateTimeImmutable $archivedAt): static
    {
        $this->archivedAt = $archivedAt;

        return $this;
    }

    /** Indique si le message est archivé. */
    public function isArchived(): bool
    {
        return null !== $this->archivedAt;
    }
}
