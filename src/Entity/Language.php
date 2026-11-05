<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\SortableTrait;
use App\Entity\Trait\TimestampTrait;
use App\Repository\LanguageRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Langue parlée, avec une description libre de la maîtrise
 * (ex. « Anglais » / « technique, lecture de documentation »).
 *
 * Liens :
 * - Utilisée par : Admin\LanguageController, LanguageType
 * - Affichée par : CV public (lot 3)
 */
#[ORM\Entity(repositoryClass: LanguageRepository::class)]
// « language » est un mot-clé SQL : préfixe cv_ pour éviter toute ambiguïté
#[ORM\Table(name: 'cv_language')]
#[ORM\HasLifecycleCallbacks]
class Language implements SortableInterface
{
    use SortableTrait;
    use TimestampTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Nom de la langue. */
    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    private string $name = '';

    /** Maîtrise, en texte libre (ex. « langue maternelle »). */
    #[ORM\Column(length: 160)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 160)]
    private string $proficiency = '';

    /** Renvoie l'identifiant technique. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** Renvoie le nom de la langue. */
    public function getName(): string
    {
        return $this->name;
    }

    /** Définit le nom de la langue. */
    public function setName(?string $name): static
    {
        $this->name = (string) $name;

        return $this;
    }

    /** Renvoie la maîtrise. */
    public function getProficiency(): string
    {
        return $this->proficiency;
    }

    /** Définit la maîtrise. */
    public function setProficiency(?string $proficiency): static
    {
        $this->proficiency = (string) $proficiency;

        return $this;
    }

    /** Texte affiché dans les listes de l'admin. */
    public function __toString(): string
    {
        return $this->name;
    }
}
