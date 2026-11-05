<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampTrait;
use App\Repository\ProfileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Profil affiché sur le site (une seule ligne en base).
 *
 * Les champs « private* » (téléphone, e-mail, adresse, prétentions) ne doivent jamais
 * apparaître dans le HTML public : ils sont réservés à l'espace recruteur validé (lot 6).
 *
 * Liens :
 * - Utilisée par : ProfileRepository::getOrCreate(), Admin\ProfileController, ProfileType
 * - Affichée par : accueil et CV public (lot 3)
 */
#[ORM\Entity(repositoryClass: ProfileRepository::class)]
#[ORM\Table(name: 'profile')]
#[ORM\HasLifecycleCallbacks]
class Profile
{
    use TimestampTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Nom complet affiché. */
    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $fullName = '';

    /** Titre de poste unique, identique partout sur le site. */
    #[ORM\Column(length: 160)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 160)]
    private string $jobTitle = '';

    /** Sous-titre (ex. « Conception · Chiffrage · Pilotage de projets »). */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $tagline = null;

    /** Accroche de l'accueil (deux phrases au maximum). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 600)]
    private ?string $pitch = null;

    /** Résumé du CV (4 à 5 lignes, orienté valeur). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 3000)]
    private ?string $summary = null;

    /** Statut de recherche (ex. « En recherche active, disponible immédiatement »). */
    #[ORM\Column(length: 160, nullable: true)]
    #[Assert\Length(max: 160)]
    private ?string $searchStatus = null;

    /** Localisation affichée (ex. « Lyon et Auvergne-Rhône-Alpes »). */
    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $location = null;

    /** Mobilité (ex. « Déplacements ponctuels »). */
    #[ORM\Column(length: 160, nullable: true)]
    #[Assert\Length(max: 160)]
    private ?string $mobility = null;

    /** Modalités de travail (ex. « Sur site · hybride · télétravail »). */
    #[ORM\Column(length: 160, nullable: true)]
    #[Assert\Length(max: 160)]
    private ?string $workModes = null;

    /** Postes visés (« Ce que je recherche »). */
    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $targetRoles = null;

    /** Structures visées (« Ce que je recherche »). */
    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $targetCompanies = null;

    /** Types de contrat recherchés (ex. « CDI, cadre »). */
    #[ORM\Column(length: 160, nullable: true)]
    #[Assert\Length(max: 160)]
    private ?string $contractTypes = null;

    /** Disponibilité (ex. « Immédiate »). */
    #[ORM\Column(length: 160, nullable: true)]
    #[Assert\Length(max: 160)]
    private ?string $availability = null;

    /** Téléphone (privé : espace recruteur validé uniquement). */
    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(max: 30)]
    private ?string $privatePhone = null;

    /** E-mail de contact (privé : espace recruteur validé uniquement). */
    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private ?string $privateEmail = null;

    /** Adresse postale (privée ; jamais transmise au chatbot). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $privatePostalAddress = null;

    /** Prétentions salariales (privées : espace recruteur validé uniquement). */
    #[ORM\Column(length: 160, nullable: true)]
    #[Assert\Length(max: 160)]
    private ?string $privateSalaryExpectation = null;

    /** Affiche les prétentions dans l'espace recruteur (décoché par défaut, décision du 2026-10-23). */
    #[ORM\Column(options: ['default' => false])]
    private bool $showSalaryInRecruiterArea = false;

    /**
     * Rubrique « Langues » sur le CV public (correction de recette n° 2, 2026-10-30 : réservée à l'espace recruteur ;
     * repère 🔒 du titre du § 9 de TEXTES_SITE.md, ou admin › Profil).
     */
    #[ORM\Column(options: ['default' => true])]
    private bool $languagesPublic = true;

    /**
     * Rubrique « Formations et certifications » (habilitations comprises) sur le CV public (correction de recette
     * n° 2 : réservée à l'espace recruteur ; repère 🔒 du titre du § 10 de TEXTES_SITE.md, ou admin › Profil).
     */
    #[ORM\Column(options: ['default' => true])]
    private bool $educationsPublic = true;

    /** Nom du fichier de la photo dans public/uploads/profile (null sans photo). */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $photoFilename = null;

    /** Renvoie l'identifiant technique. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** Renvoie le nom complet. */
    public function getFullName(): string
    {
        return $this->fullName;
    }

    /** Définit le nom complet. */
    public function setFullName(?string $fullName): static
    {
        $this->fullName = (string) $fullName;

        return $this;
    }

    /** Renvoie le titre de poste. */
    public function getJobTitle(): string
    {
        return $this->jobTitle;
    }

    /** Définit le titre de poste. */
    public function setJobTitle(?string $jobTitle): static
    {
        $this->jobTitle = (string) $jobTitle;

        return $this;
    }

    /** Renvoie le sous-titre. */
    public function getTagline(): ?string
    {
        return $this->tagline;
    }

    /** Définit le sous-titre. */
    public function setTagline(?string $tagline): static
    {
        $this->tagline = $tagline;

        return $this;
    }

    /** Renvoie l'accroche. */
    public function getPitch(): ?string
    {
        return $this->pitch;
    }

    /** Définit l'accroche. */
    public function setPitch(?string $pitch): static
    {
        $this->pitch = $pitch;

        return $this;
    }

    /** Renvoie le résumé. */
    public function getSummary(): ?string
    {
        return $this->summary;
    }

    /** Définit le résumé. */
    public function setSummary(?string $summary): static
    {
        $this->summary = $summary;

        return $this;
    }

    /** Renvoie le statut de recherche. */
    public function getSearchStatus(): ?string
    {
        return $this->searchStatus;
    }

    /** Définit le statut de recherche. */
    public function setSearchStatus(?string $searchStatus): static
    {
        $this->searchStatus = $searchStatus;

        return $this;
    }

    /** Renvoie la localisation. */
    public function getLocation(): ?string
    {
        return $this->location;
    }

    /** Définit la localisation. */
    public function setLocation(?string $location): static
    {
        $this->location = $location;

        return $this;
    }

    /** Renvoie la mobilité. */
    public function getMobility(): ?string
    {
        return $this->mobility;
    }

    /** Définit la mobilité. */
    public function setMobility(?string $mobility): static
    {
        $this->mobility = $mobility;

        return $this;
    }

    /** Renvoie les modalités de travail. */
    public function getWorkModes(): ?string
    {
        return $this->workModes;
    }

    /** Définit les modalités de travail. */
    public function setWorkModes(?string $workModes): static
    {
        $this->workModes = $workModes;

        return $this;
    }

    /** Renvoie les postes visés. */
    public function getTargetRoles(): ?string
    {
        return $this->targetRoles;
    }

    /** Définit les postes visés. */
    public function setTargetRoles(?string $targetRoles): static
    {
        $this->targetRoles = $targetRoles;

        return $this;
    }

    /** Renvoie les structures visées. */
    public function getTargetCompanies(): ?string
    {
        return $this->targetCompanies;
    }

    /** Définit les structures visées. */
    public function setTargetCompanies(?string $targetCompanies): static
    {
        $this->targetCompanies = $targetCompanies;

        return $this;
    }

    /** Renvoie les types de contrat recherchés. */
    public function getContractTypes(): ?string
    {
        return $this->contractTypes;
    }

    /** Définit les types de contrat recherchés. */
    public function setContractTypes(?string $contractTypes): static
    {
        $this->contractTypes = $contractTypes;

        return $this;
    }

    /** Renvoie la disponibilité. */
    public function getAvailability(): ?string
    {
        return $this->availability;
    }

    /** Définit la disponibilité. */
    public function setAvailability(?string $availability): static
    {
        $this->availability = $availability;

        return $this;
    }

    /** Renvoie le téléphone privé. */
    public function getPrivatePhone(): ?string
    {
        return $this->privatePhone;
    }

    /** Définit le téléphone privé. */
    public function setPrivatePhone(?string $privatePhone): static
    {
        $this->privatePhone = $privatePhone;

        return $this;
    }

    /** Renvoie l'e-mail de contact privé. */
    public function getPrivateEmail(): ?string
    {
        return $this->privateEmail;
    }

    /** Définit l'e-mail de contact privé. */
    public function setPrivateEmail(?string $privateEmail): static
    {
        $this->privateEmail = $privateEmail;

        return $this;
    }

    /** Renvoie l'adresse postale privée. */
    public function getPrivatePostalAddress(): ?string
    {
        return $this->privatePostalAddress;
    }

    /** Définit l'adresse postale privée. */
    public function setPrivatePostalAddress(?string $privatePostalAddress): static
    {
        $this->privatePostalAddress = $privatePostalAddress;

        return $this;
    }

    /** Renvoie les prétentions salariales privées. */
    public function getPrivateSalaryExpectation(): ?string
    {
        return $this->privateSalaryExpectation;
    }

    /** Définit les prétentions salariales privées. */
    public function setPrivateSalaryExpectation(?string $privateSalaryExpectation): static
    {
        $this->privateSalaryExpectation = $privateSalaryExpectation;

        return $this;
    }

    /** Indique si la rubrique « Langues » s'affiche sur le CV public. */
    public function isLanguagesPublic(): bool
    {
        return $this->languagesPublic;
    }

    /** Affiche la rubrique « Langues » sur le CV public, ou la réserve à l'espace recruteur. */
    public function setLanguagesPublic(bool $languagesPublic): static
    {
        $this->languagesPublic = $languagesPublic;

        return $this;
    }

    /** Indique si la rubrique « Formations et certifications » s'affiche sur le CV public. */
    public function isEducationsPublic(): bool
    {
        return $this->educationsPublic;
    }

    /** Affiche la rubrique « Formations et certifications » sur le CV public, ou la réserve à l'espace recruteur. */
    public function setEducationsPublic(bool $educationsPublic): static
    {
        $this->educationsPublic = $educationsPublic;

        return $this;
    }

    /** Indique si les prétentions s'affichent dans l'espace recruteur. */
    public function isShowSalaryInRecruiterArea(): bool
    {
        return $this->showSalaryInRecruiterArea;
    }

    /** Affiche ou masque les prétentions dans l'espace recruteur. */
    public function setShowSalaryInRecruiterArea(bool $showSalaryInRecruiterArea): static
    {
        $this->showSalaryInRecruiterArea = $showSalaryInRecruiterArea;

        return $this;
    }

    /** Renvoie le nom du fichier de la photo, ou null. */
    public function getPhotoFilename(): ?string
    {
        return $this->photoFilename;
    }

    /** Définit le nom du fichier de la photo (null pour la retirer). */
    public function setPhotoFilename(?string $photoFilename): static
    {
        $this->photoFilename = $photoFilename;

        return $this;
    }
}
