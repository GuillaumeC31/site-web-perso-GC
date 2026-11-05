<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\SortableTrait;
use App\Entity\Trait\TimestampTrait;
use App\Repository\RecruitmentArgumentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Argument du bloc « Pourquoi me recruter » de l'accueil : un titre court et un texte d'une à deux phrases.
 *
 * Chaque argument a son interrupteur « Afficher » (masqué par défaut) ; le bloc entier a le sien
 * (SiteSettings::isRecruitmentBlockEnabled(), coupé par défaut). Un argument masqué est un brouillon : il n'est
 * montré ni sur l'accueil ni à l'assistant IA (RecruitmentArguments::getDisplayed()). Jamais touché par
 * app:content:sync-textes.
 *
 * Liens :
 * - Utilisée par : Admin\RecruitmentArgumentController, RecruitmentArgumentType, Service\Content\RecruitmentArguments
 * - Affichée par : templates/_partials/_recruitment_arguments.html.twig (accueil et aperçu de l'admin)
 */
#[ORM\Entity(repositoryClass: RecruitmentArgumentRepository::class)]
#[ORM\Table(name: 'recruitment_argument')]
#[ORM\HasLifecycleCallbacks]
class RecruitmentArgument implements SortableInterface
{
    use SortableTrait;
    use TimestampTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Titre de l'argument (ex. « Du terrain au bureau d'études »). */
    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $title = '';

    /** Texte d'une à deux phrases. */
    #[ORM\Column(length: 400)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 400)]
    private string $text = '';

    /** Affiché sur l'accueil (si le bloc est activé) ; faux = brouillon. */
    #[ORM\Column(options: ['default' => false])]
    private bool $visible = false;

    /** Renvoie l'identifiant technique. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** Renvoie le titre. */
    public function getTitle(): string
    {
        return $this->title;
    }

    /** Définit le titre. */
    public function setTitle(?string $title): static
    {
        $this->title = trim((string) $title);

        return $this;
    }

    /** Renvoie le texte. */
    public function getText(): string
    {
        return $this->text;
    }

    /** Définit le texte. */
    public function setText(?string $text): static
    {
        $this->text = trim((string) $text);

        return $this;
    }

    /** Indique si l'argument est affiché (faux : brouillon). */
    public function isVisible(): bool
    {
        return $this->visible;
    }

    /** Affiche ou masque l'argument. */
    public function setVisible(bool $visible): static
    {
        $this->visible = $visible;

        return $this;
    }

    /** Renvoie le titre (listes de l'admin, confirmation de suppression). */
    public function __toString(): string
    {
        return $this->title;
    }
}
