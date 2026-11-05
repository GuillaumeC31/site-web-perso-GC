<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\SortableTrait;
use App\Entity\Trait\TimestampTrait;
use App\Enum\CvEntrySection;
use App\Repository\CvEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Élément d'une rubrique « liste » du CV : outils techniques, expertises métiers, logiciels,
 * savoir-être, centres d'intérêt (ex. « Conception » : « SpaceClaim 2023 R1 (ANSYS), AutoCAD LT… »).
 *
 * L'ordre se règle dans l'admin à l'intérieur de chaque rubrique ; les rubriques suivent l'ordre du CV
 * de référence (CvEntrySection).
 *
 * Liens :
 * - Utilisée par : Admin\CvEntryController, CvEntryType, CvContentProvider, app:content:sync-textes
 * - Affichée par : page /cv, CV PDF, espace recruteur
 */
#[ORM\Entity(repositoryClass: CvEntryRepository::class)]
#[ORM\HasLifecycleCallbacks]
class CvEntry implements SortableInterface
{
    use SortableTrait;
    use TimestampTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Rubrique du CV. */
    #[ORM\Column(length: 20, enumType: CvEntrySection::class)]
    #[Assert\NotNull]
    private CvEntrySection $section = CvEntrySection::Tools;

    /** Titre en gras (ex. « Conception »), facultatif (les outils techniques n'en ont pas). */
    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $title = null;

    /** Texte de l'élément. */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 1000)]
    private string $content = '';

    /**
     * Résumé public (une ligne) des rubriques « Outils techniques », « Expertises métiers » et « Logiciels » : seul
     * affiché sur le CV public et les PDF synthétiques ; sans résumé, l'élément n'y figure pas. Le texte complet
     * reste dans l'espace recruteur (correction de recette n° 1, 2026-10-30).
     */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $publicSummary = null;

    /** Renvoie l'identifiant technique. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** Renvoie la rubrique. */
    public function getSection(): CvEntrySection
    {
        return $this->section;
    }

    /** Définit la rubrique. */
    public function setSection(CvEntrySection $section): static
    {
        $this->section = $section;

        return $this;
    }

    /** Renvoie le titre (null : sans titre). */
    public function getTitle(): ?string
    {
        return $this->title;
    }

    /** Définit le titre (texte vide : sans titre). */
    public function setTitle(?string $title): static
    {
        $this->title = '' === trim((string) $title) ? null : trim((string) $title);

        return $this;
    }

    /** Renvoie le texte. */
    public function getContent(): string
    {
        return $this->content;
    }

    /** Définit le texte. */
    public function setContent(?string $content): static
    {
        $this->content = trim((string) $content);

        return $this;
    }

    /** Renvoie le résumé public (null : élément absent du CV public). */
    public function getPublicSummary(): ?string
    {
        return $this->publicSummary;
    }

    /** Définit le résumé public (texte vide : aucun). */
    public function setPublicSummary(?string $publicSummary): static
    {
        $this->publicSummary = '' === trim((string) $publicSummary) ? null : trim((string) $publicSummary);

        return $this;
    }

    /** Texte affiché dans les listes de l'admin. */
    public function __toString(): string
    {
        return $this->title ?? mb_strimwidth($this->content, 0, 60, '…');
    }
}
