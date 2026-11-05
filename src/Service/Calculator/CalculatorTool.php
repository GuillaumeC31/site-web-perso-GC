<?php

declare(strict_types=1);

namespace App\Service\Calculator;

/**
 * Un outil de la calculatrice, tel qu'il s'affiche : définition du registre
 * (config/calculator/tools.yaml) complétée par les réglages de l'admin (CalculatorToolSetting).
 *
 * Liens :
 * - Créé par : CalculatorRegistry
 * - Utilisé par : CalculatorController (pages publiques), Admin\CalculatorToolController,
 *   templates/calculator/*
 */
final readonly class CalculatorTool
{
    /** Porté sur ce site (résultats identiques à l'ancien). */
    public const STATUS_AVAILABLE = 'available';

    /** Existe dans l'ancien site, portage prévu. */
    public const STATUS_LEGACY = 'legacy';

    /** N'existait pas dans l'ancien site, à créer. */
    public const STATUS_PLANNED = 'planned';

    /** Fusionné dans un autre outil (redirection). */
    public const STATUS_MERGED = 'merged';

    /**
     * Rassemble les informations de l'outil.
     *
     * @param string      $slug              identifiant d'URL (ex. « loi-ohm »)
     * @param string      $category          slug de la catégorie (ex. « electricite »)
     * @param string|null $group             sous-groupe dans la catégorie (ex. « Courant continu »)
     * @param string      $status            available, legacy, planned ou merged
     * @param string      $title             titre
     * @param string|null $summary           description (page, et balise meta description sans metaDescription)
     * @param string|null $mergedInto        « categorie/outil » de destination pour un outil fusionné
     * @param bool        $enabled           vrai : visible sur le site (outil porté et activé dans l'admin)
     * @param string|null $helpText          texte d'aide saisi dans l'admin
     * @param string|null $reference         norme ou source citée (celle de l'admin, sinon celle du registre)
     * @param string|null $defaultReference  norme proposée par le registre
     * @param bool        $referenceVerified vrai : norme validée dans l'admin (affichée en public)
     * @param int         $position          rang d'affichage dans la catégorie
     * @param string|null $metaDescription   description courte de la balise meta (160 caractères au plus), si le résumé est plus long
     */
    public function __construct(
        public string $slug,
        public string $category,
        public ?string $group,
        public string $status,
        public string $title,
        public ?string $summary,
        public ?string $mergedInto,
        public bool $enabled,
        public ?string $helpText,
        public ?string $reference,
        public ?string $defaultReference,
        public bool $referenceVerified,
        public int $position,
        public ?string $metaDescription = null,
    ) {
    }

    /**
     * Renvoie la clé unique de l'outil (« categorie/outil »), utilisée par les réglages de l'admin.
     */
    public function getKey(): string
    {
        return $this->category.'/'.$this->slug;
    }

    /**
     * Indique si l'outil est visible sur le site public.
     *
     * Liens :
     * - Appelée par : CalculatorRegistry (listes publiques), CalculatorController::tool()
     */
    public function isPublic(): bool
    {
        return self::STATUS_AVAILABLE === $this->status && $this->enabled;
    }

    /**
     * Indique si l'outil peut être activé depuis l'admin (seulement s'il est porté).
     */
    public function canBeEnabled(): bool
    {
        return self::STATUS_AVAILABLE === $this->status;
    }

    /**
     * Renvoie la norme ou source à afficher en public (seulement si elle a été validée).
     */
    public function getPublicReference(): ?string
    {
        return $this->referenceVerified && null !== $this->reference && '' !== trim($this->reference) ? $this->reference : null;
    }

    /**
     * Renvoie le template de l'interface de l'outil.
     */
    public function getTemplate(): string
    {
        return \sprintf('calculator/tools/%s/%s.html.twig', $this->category, $this->slug);
    }
}
