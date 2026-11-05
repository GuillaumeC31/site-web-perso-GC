<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\SortableTrait;
use App\Entity\Trait\TimestampTrait;
use App\Repository\ProjectCategoryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Catégorie de projet (ex. « Froid industriel », « IoT »), pour filtrer la page Projets.
 *
 * Le slug sert dans l'URL du filtre (/projets?categorie=iot) ; laissé vide, il est
 * calculé à partir du nom (Admin\ProjectCategoryController).
 *
 * Liens :
 * - Utilisée par : Admin\ProjectCategoryController, ProjectCategoryType, ProjectType (formulaire)
 * - Liée à : Project (côté inverse de la relation)
 */
#[ORM\Entity(repositoryClass: ProjectCategoryRepository::class)]
#[ORM\Table(name: 'project_category')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['slug'], message: 'project_category.slug_taken')]
class ProjectCategory implements SortableInterface
{
    use SortableTrait;
    use TimestampTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Nom affiché. */
    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    private string $name = '';

    /** Identifiant d'URL (minuscules, chiffres et tirets). */
    #[ORM\Column(length: 90, unique: true)]
    #[Assert\Length(max: 90)]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', message: 'project.slug_format')]
    private string $slug = '';

    /** Renvoie l'identifiant technique. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** Renvoie le nom affiché. */
    public function getName(): string
    {
        return $this->name;
    }

    /** Définit le nom affiché. */
    public function setName(?string $name): static
    {
        $this->name = trim((string) $name);

        return $this;
    }

    /** Renvoie l'identifiant d'URL. */
    public function getSlug(): string
    {
        return $this->slug;
    }

    /** Définit l'identifiant d'URL. */
    public function setSlug(?string $slug): static
    {
        $this->slug = trim((string) $slug);

        return $this;
    }

    /** Renvoie le nom (listes de l'admin, confirmations). */
    public function __toString(): string
    {
        return $this->name;
    }
}
