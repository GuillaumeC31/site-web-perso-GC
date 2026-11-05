<?php

declare(strict_types=1);

namespace App\Service\Calculator;

/**
 * Une catégorie de la calculatrice et ses outils (dans l'ordre d'affichage).
 *
 * Liens :
 * - Créé par : CalculatorRegistry
 * - Utilisé par : CalculatorController, Admin\CalculatorToolController, templates/calculator/*
 */
final readonly class CalculatorCategory
{
    /**
     * Rassemble les informations de la catégorie.
     *
     * @param string               $slug        identifiant d'URL (ex. « electricite »)
     * @param string               $name        nom affiché
     * @param string               $description description courte
     * @param string               $icon        tracé SVG de l'icône (Heroicons, contour 24 px)
     * @param list<CalculatorTool> $tools       outils de la catégorie, dans l'ordre d'affichage
     */
    public function __construct(
        public string $slug,
        public string $name,
        public string $description,
        public string $icon,
        public array $tools,
    ) {
    }

    /**
     * Renvoie les outils visibles sur le site public.
     *
     * @return list<CalculatorTool>
     */
    public function getPublicTools(): array
    {
        return array_values(array_filter($this->tools, static fn (CalculatorTool $tool): bool => $tool->isPublic()));
    }

    /**
     * Regroupe les outils publics par sous-groupe (« Courant continu »…), dans l'ordre d'affichage.
     *
     * @return array<string, list<CalculatorTool>> nom du groupe (chaîne vide : sans groupe) => outils
     */
    public function getPublicToolsByGroup(): array
    {
        $groups = [];
        foreach ($this->getPublicTools() as $tool) {
            $groups[$tool->group ?? ''][] = $tool;
        }

        return $groups;
    }
}
