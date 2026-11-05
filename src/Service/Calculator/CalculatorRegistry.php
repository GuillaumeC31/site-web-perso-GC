<?php

declare(strict_types=1);

namespace App\Service\Calculator;

use App\Repository\CalculatorToolSettingRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Registre de la calculatrice (CDC § 8) : catégories et outils lus dans
 * config/calculator/tools.yaml, complétés par les réglages de l'admin.
 *
 * Remplace les catégories écrites en dur dans le contrôleur de l'ancien site. Le fichier est
 * contrôlé au chargement (catégorie inconnue, outil en double, statut ou fusion invalide) :
 * une erreur de saisie est signalée tout de suite plutôt que d'afficher une page cassée.
 *
 * Liens :
 * - Utilisé par : CalculatorController (pages publiques), Admin\CalculatorToolController,
 *   ContentCompletenessChecker (normes à vérifier), templates (nombre d'outils)
 * - Utilise : CalculatorToolSettingRepository (réglages de l'admin)
 */
final class CalculatorRegistry
{
    /** Statuts admis dans le registre. */
    private const STATUSES = [CalculatorTool::STATUS_AVAILABLE, CalculatorTool::STATUS_LEGACY, CalculatorTool::STATUS_PLANNED, CalculatorTool::STATUS_MERGED];

    /** @var list<CalculatorCategory>|null catégories chargées (une fois par requête) */
    private ?array $categories = null;

    /**
     * Reçoit l'emplacement du registre et les réglages de l'admin.
     *
     * @param string                          $registryPath      chemin de config/calculator/tools.yaml
     * @param CalculatorToolSettingRepository $settingRepository réglages de l'admin
     */
    public function __construct(
        #[Autowire('%kernel.project_dir%/config/calculator/tools.yaml')]
        private readonly string $registryPath,
        private readonly CalculatorToolSettingRepository $settingRepository,
    ) {
    }

    /**
     * Renvoie toutes les catégories avec tous leurs outils (admin).
     *
     * Liens :
     * - Appelée par : Admin\CalculatorToolController::index(), getPublicCategories()
     *
     * @return list<CalculatorCategory>
     */
    public function getCategories(): array
    {
        return $this->categories ??= $this->load();
    }

    /**
     * Renvoie les catégories qui ont au moins un outil public.
     *
     * Liens :
     * - Appelée par : CalculatorController::dashboard()
     *
     * @return list<CalculatorCategory>
     */
    public function getPublicCategories(): array
    {
        return array_values(array_filter($this->getCategories(), static fn (CalculatorCategory $category): bool => [] !== $category->getPublicTools()));
    }

    /**
     * Retrouve une catégorie par son slug.
     *
     * Liens :
     * - Appelée par : CalculatorController::category(), ::tool()
     */
    public function getCategory(string $slug): ?CalculatorCategory
    {
        foreach ($this->getCategories() as $category) {
            if ($category->slug === $slug) {
                return $category;
            }
        }

        return null;
    }

    /**
     * Retrouve un outil par sa catégorie et son slug (quel que soit son statut).
     *
     * Liens :
     * - Appelée par : CalculatorController::tool(), Admin\CalculatorToolController::edit()
     */
    public function getTool(string $category, string $slug): ?CalculatorTool
    {
        foreach ($this->getCategory($category)->tools ?? [] as $tool) {
            if ($tool->slug === $slug) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * Renvoie tous les outils visibles sur le site public.
     *
     * Liens :
     * - Appelée par : CalculatorController::dashboard(), countPublicTools()
     *
     * @return list<CalculatorTool>
     */
    public function getPublicTools(): array
    {
        return array_merge(...array_map(static fn (CalculatorCategory $category): array => $category->getPublicTools(), $this->getCategories()));
    }

    /**
     * Compte les outils visibles sur le site public (texte « %count% outils gratuits »).
     *
     * Liens :
     * - Appelée par : CalculatorController, HomeController
     */
    public function countPublicTools(): int
    {
        return \count($this->getPublicTools());
    }

    /**
     * Renvoie les outils publics dont la norme citée n'a pas encore été vérifiée.
     *
     * Liens :
     * - Appelée par : ContentCompletenessChecker::findMissingItems()
     *
     * @return list<CalculatorTool>
     */
    public function getToolsWithUnverifiedReference(): array
    {
        return array_values(array_filter(
            $this->getPublicTools(),
            static fn (CalculatorTool $tool): bool => null !== $tool->reference && !$tool->referenceVerified,
        ));
    }

    /**
     * Oublie les données chargées (après une modification dans l'admin).
     *
     * Liens :
     * - Appelée par : Admin\CalculatorToolController::edit()
     */
    public function reset(): void
    {
        $this->categories = null;
    }

    /**
     * Lit et contrôle le registre, puis applique les réglages de l'admin.
     *
     * @return list<CalculatorCategory>
     *
     * @throws \LogicException si le registre est incohérent
     */
    private function load(): array
    {
        /** @var array{categories: array<string, array{name: string, description: string, icon: string}>, tools: list<array<string, string>>} $data */
        $data = Yaml::parseFile($this->registryPath);
        $settings = $this->settingRepository->findAllIndexedByKey();
        $toolsByCategory = array_fill_keys(array_keys($data['categories']), []);
        $keys = [];

        foreach ($data['tools'] as $index => $row) {
            $key = $row['category'].'/'.$row['slug'];
            if (!isset($toolsByCategory[$row['category']])) {
                throw new \LogicException(\sprintf('Registre de la calculatrice : catégorie inconnue pour « %s ».', $key));
            }
            if (isset($keys[$key])) {
                throw new \LogicException(\sprintf('Registre de la calculatrice : outil « %s » en double.', $key));
            }
            if (!\in_array($row['status'], self::STATUSES, true)) {
                throw new \LogicException(\sprintf('Registre de la calculatrice : statut « %s » invalide pour « %s ».', $row['status'], $key));
            }
            if (CalculatorTool::STATUS_MERGED === $row['status'] && empty($row['merged_into'])) {
                throw new \LogicException(\sprintf('Registre de la calculatrice : « %s » est fusionné sans destination.', $key));
            }
            $keys[$key] = true;

            $setting = $settings[$key] ?? null;
            $available = CalculatorTool::STATUS_AVAILABLE === $row['status'];
            $toolsByCategory[$row['category']][] = new CalculatorTool(
                slug: $row['slug'],
                category: $row['category'],
                group: $row['group'] ?? null,
                status: $row['status'],
                title: $row['title'],
                summary: $row['summary'] ?? null,
                mergedInto: $row['merged_into'] ?? null,
                // Seul un outil porté peut être visible ; l'admin peut le masquer.
                enabled: $available && ($setting?->isEnabled() ?? true),
                helpText: $setting?->getHelpText(),
                reference: $setting?->getReference() ?? ($row['reference'] ?? null),
                defaultReference: $row['reference'] ?? null,
                referenceVerified: $setting?->isReferenceVerified() ?? false,
                position: $setting?->getPosition() ?? ($index + 1) * 10,
                metaDescription: $row['meta_description'] ?? null,
            );
        }

        foreach ($data['tools'] as $row) {
            if (CalculatorTool::STATUS_MERGED === $row['status'] && !isset($keys[$row['merged_into']])) {
                throw new \LogicException(\sprintf('Registre de la calculatrice : destination « %s » inconnue.', $row['merged_into']));
            }
        }

        $categories = [];
        foreach ($data['categories'] as $slug => $category) {
            $tools = $toolsByCategory[$slug];
            usort($tools, static fn (CalculatorTool $a, CalculatorTool $b): int => $a->position <=> $b->position);
            $categories[] = new CalculatorCategory($slug, $category['name'], $category['description'], $category['icon'], $tools);
        }

        return $categories;
    }
}
