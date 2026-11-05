<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\SortableTrait;
use App\Entity\Trait\TimestampTrait;
use App\Repository\KeyFigureRepository;
use App\Service\Content\PlaceholderDetector;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Chiffre clé de l'accueil (ex. « Près de 20 ans » / « d'expérience, du terrain au bureau d'études »).
 *
 * Règle du CDC : uniquement des chiffres vrais et vérifiables. L'accueil en affiche trois.
 *
 * Liens :
 * - Utilisée par : Admin\KeyFigureController, KeyFigureType
 * - Affichée par : composant <twig:KeyFigure> (accueil, lot 3)
 */
#[ORM\Entity(repositoryClass: KeyFigureRepository::class)]
#[ORM\Table(name: 'key_figure')]
#[ORM\HasLifecycleCallbacks]
class KeyFigure implements SortableInterface
{
    use SortableTrait;
    use TimestampTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Valeur mise en avant (ex. « Près de 20 ans », « 3 collaborateurs »). */
    #[ORM\Column(length: 40)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 40)]
    private string $value = '';

    /** Libellé sous la valeur. */
    #[ORM\Column(length: 160)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 160)]
    private string $label = '';

    /** Renvoie l'identifiant technique. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** Renvoie la valeur. */
    public function getValue(): string
    {
        return $this->value;
    }

    /** Définit la valeur. */
    public function setValue(?string $value): static
    {
        $this->value = (string) $value;

        return $this;
    }

    /** Renvoie le libellé. */
    public function getLabel(): string
    {
        return $this->label;
    }

    /** Définit le libellé. */
    public function setLabel(?string $label): static
    {
        $this->label = (string) $label;

        return $this;
    }

    /**
     * Indique si le chiffre est publiable (valeur et libellé sans texte à compléter).
     *
     * Liens :
     * - Appelée par : HomeController (seuls les chiffres complets sont affichés), ContentCompletenessChecker
     * - Utilise : PlaceholderDetector::isComplete()
     */
    public function isComplete(): bool
    {
        return PlaceholderDetector::isComplete($this->value) && PlaceholderDetector::isComplete($this->label);
    }

    /** Texte affiché dans les listes de l'admin. */
    public function __toString(): string
    {
        return $this->value.' '.$this->label;
    }
}
