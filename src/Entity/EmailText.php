<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EmailType;
use App\Repository\EmailTextRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Textes modifiés d'un type d'e-mail (Communication › E-mails › Contenu des e-mails) : objet, phrase
 * d'introduction, signature. Un champ null reprend le texte d'origine (EmailType::default*()). Texte simple
 * seulement, contrôlé par Mail\EmailTexts::validate() (aucun HTML, variables {nom}, {entreprise}, {delai}).
 *
 * Liens :
 * - Utilisée par : Mail\EmailTexts, Admin\CommunicationController (enregistrement, « Rétablir le texte d'origine »)
 * - Voir aussi : EmailTextRevision (historique des modifications)
 */
#[ORM\Entity(repositoryClass: EmailTextRepository::class)]
#[ORM\Table(name: 'email_text')]
#[ORM\UniqueConstraint(name: 'uniq_email_text_type', columns: ['type'])]
class EmailText
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40, enumType: EmailType::class)]
    private EmailType $type;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $subject = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $intro = null;

    #[ORM\Column(length: 300, nullable: true)]
    private ?string $signature = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /**
     * Crée les textes modifiés d'un type d'e-mail.
     *
     * @param EmailType          $type      type d'e-mail
     * @param \DateTimeImmutable $updatedAt date de la modification
     */
    public function __construct(EmailType $type, \DateTimeImmutable $updatedAt)
    {
        $this->type = $type;
        $this->updatedAt = $updatedAt;
    }

    /** Renvoie l'identifiant technique. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** Renvoie le type d'e-mail. */
    public function getType(): EmailType
    {
        return $this->type;
    }

    /** Renvoie l'objet modifié (null : texte d'origine). */
    public function getSubject(): ?string
    {
        return $this->subject;
    }

    /** Renvoie l'introduction modifiée (null : texte d'origine). */
    public function getIntro(): ?string
    {
        return $this->intro;
    }

    /** Renvoie la signature modifiée (null : texte d'origine). */
    public function getSignature(): ?string
    {
        return $this->signature;
    }

    /** Renvoie la date de la dernière modification. */
    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Enregistre les trois textes (null : texte d'origine).
     *
     * Liens :
     * - Appelée par : Mail\EmailTexts::save()
     */
    public function update(?string $subject, ?string $intro, ?string $signature, \DateTimeImmutable $now): static
    {
        $this->subject = $subject;
        $this->intro = $intro;
        $this->signature = $signature;
        $this->updatedAt = $now;

        return $this;
    }
}
