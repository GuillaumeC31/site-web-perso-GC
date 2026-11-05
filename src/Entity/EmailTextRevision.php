<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TestTraceTrait;
use App\Enum\EmailType;
use App\Repository\EmailTextRevisionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Historique des modifications des textes d'un e-mail : une ligne par enregistrement ou par retour au texte
 * d'origine, avec les textes tels qu'ils étaient APRÈS l'action (null : texte d'origine).
 *
 * Liens :
 * - Écrite par : Mail\EmailTexts::save(), Mail\EmailTexts::reset()
 * - Affichée par : templates/admin/communication/email_text.html.twig (Communication › E-mails)
 */
#[ORM\Entity(repositoryClass: EmailTextRevisionRepository::class)]
#[ORM\Table(name: 'email_text_revision')]
#[ORM\Index(name: 'idx_email_text_revision_type', columns: ['type', 'saved_at'])]
class EmailTextRevision implements TestTraceableInterface
{
    use TestTraceTrait;

    /** Action : textes enregistrés. */
    public const ACTION_SAVED = 'saved';

    /** Action : retour au texte d'origine. */
    public const ACTION_RESET = 'reset';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40, enumType: EmailType::class)]
    private EmailType $type;

    #[ORM\Column(length: 10)]
    private string $action;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $subject;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $intro;

    #[ORM\Column(length: 300, nullable: true)]
    private ?string $signature;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $savedAt;

    /**
     * Crée une ligne d'historique.
     *
     * @param string $action self::ACTION_SAVED ou self::ACTION_RESET
     */
    public function __construct(EmailType $type, string $action, ?string $subject, ?string $intro, ?string $signature, \DateTimeImmutable $savedAt)
    {
        $this->type = $type;
        $this->action = $action;
        $this->subject = $subject;
        $this->intro = $intro;
        $this->signature = $signature;
        $this->savedAt = $savedAt;
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

    /** Renvoie l'action (saved, reset). */
    public function getAction(): string
    {
        return $this->action;
    }

    /** Renvoie l'objet après l'action (null : texte d'origine). */
    public function getSubject(): ?string
    {
        return $this->subject;
    }

    /** Renvoie l'introduction après l'action (null : texte d'origine). */
    public function getIntro(): ?string
    {
        return $this->intro;
    }

    /** Renvoie la signature après l'action (null : texte d'origine). */
    public function getSignature(): ?string
    {
        return $this->signature;
    }

    /** Renvoie la date de l'action. */
    public function getSavedAt(): \DateTimeImmutable
    {
        return $this->savedAt;
    }
}
