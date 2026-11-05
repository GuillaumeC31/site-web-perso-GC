<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\SortableTrait;
use App\Entity\Trait\TimestampTrait;
use App\Enum\CertificationType;
use App\Repository\CertificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Certification ou habilitation du CV, avec dates d'obtention et de validité.
 *
 * Liens :
 * - Utilisée par : Admin\CertificationController, App\Form\CertificationType
 * - Affichée par : CV public (lot 3)
 */
#[ORM\Entity(repositoryClass: CertificationRepository::class)]
#[ORM\Table(name: 'certification')]
#[ORM\HasLifecycleCallbacks]
class Certification implements SortableInterface
{
    use SortableTrait;
    use TimestampTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Intitulé (ex. « Habilitation électrique B2V-BR-BC »). */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name = '';

    /** Certification ou habilitation. */
    #[ORM\Column(length: 20, enumType: CertificationType::class)]
    private CertificationType $type = CertificationType::Certification;

    /** Organisme (ex. « Centre de formation Exemple »). */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $issuer = null;

    /** Date d'obtention. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $obtainedOn = null;

    /** Date de fin de validité (null : sans limite). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresOn = null;

    /**
     * Vérifie que la fin de validité est postérieure à l'obtention.
     *
     * Liens :
     * - Appelée par : le validateur Symfony (formulaire App\Form\CertificationType)
     *
     * @param ExecutionContextInterface $context contexte de validation
     */
    #[Assert\Callback]
    public function validateDates(ExecutionContextInterface $context): void
    {
        if (null !== $this->obtainedOn && null !== $this->expiresOn && $this->expiresOn < $this->obtainedOn) {
            $context->buildViolation('certification.expires_on.before_obtained')
                ->atPath('expiresOn')
                ->addViolation();
        }
    }

    /**
     * Indique si la certification a expiré à la date donnée (une expirée reste affichée, avec mention).
     *
     * Liens :
     * - Appelée par : templates/admin/certification (badge), CV public (lot 3)
     *
     * @param \DateTimeImmutable|null $today date de référence (aujourd'hui par défaut)
     */
    public function isExpired(?\DateTimeImmutable $today = null): bool
    {
        return null !== $this->expiresOn && $this->expiresOn < ($today ?? new \DateTimeImmutable('today'));
    }

    /** Renvoie l'identifiant technique. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** Renvoie l'intitulé. */
    public function getName(): string
    {
        return $this->name;
    }

    /** Définit l'intitulé. */
    public function setName(?string $name): static
    {
        $this->name = (string) $name;

        return $this;
    }

    /** Renvoie la nature (certification ou habilitation). */
    public function getType(): CertificationType
    {
        return $this->type;
    }

    /** Définit la nature. */
    public function setType(CertificationType $type): static
    {
        $this->type = $type;

        return $this;
    }

    /** Renvoie l'organisme. */
    public function getIssuer(): ?string
    {
        return $this->issuer;
    }

    /** Définit l'organisme. */
    public function setIssuer(?string $issuer): static
    {
        $this->issuer = $issuer;

        return $this;
    }

    /** Renvoie la date d'obtention. */
    public function getObtainedOn(): ?\DateTimeImmutable
    {
        return $this->obtainedOn;
    }

    /** Définit la date d'obtention. */
    public function setObtainedOn(?\DateTimeImmutable $obtainedOn): static
    {
        $this->obtainedOn = $obtainedOn;

        return $this;
    }

    /** Renvoie la date de fin de validité. */
    public function getExpiresOn(): ?\DateTimeImmutable
    {
        return $this->expiresOn;
    }

    /** Définit la date de fin de validité. */
    public function setExpiresOn(?\DateTimeImmutable $expiresOn): static
    {
        $this->expiresOn = $expiresOn;

        return $this;
    }

    /** Texte affiché dans les listes de l'admin. */
    public function __toString(): string
    {
        return $this->name;
    }
}
