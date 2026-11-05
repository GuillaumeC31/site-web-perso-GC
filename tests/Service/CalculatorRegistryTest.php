<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\CalculatorToolSetting;
use App\Service\Calculator\CalculatorRegistry;
use App\Service\Calculator\CalculatorTool;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests du registre de la calculatrice : contenu de config/calculator/tools.yaml et prise en
 * compte des réglages de l'admin.
 *
 * Liens :
 * - Couvre : CalculatorRegistry, CalculatorTool, CalculatorCategory, config/calculator/tools.yaml
 */
final class CalculatorRegistryTest extends KernelTestCase
{
    /**
     * Vérifie que le registre reprend les 69 outils de l'ancien site, avec l'état constaté au lot 5.
     */
    public function testRegistryListsTheSixtyNineToolsOfTheOldSite(): void
    {
        $tools = $this->allTools();

        self::assertCount(69, $tools);
        self::assertCount(10, $this->registry()->getCategories());
        $byStatus = array_count_values(array_map(static fn (CalculatorTool $tool): string => $tool->status, $tools));
        self::assertSame(28, $byStatus[CalculatorTool::STATUS_PLANNED], '22 pages « en construction » + 6 fichiers vides');
        self::assertSame(2, $byStatus[CalculatorTool::STATUS_MERGED], 'Boucle 4-20 mA et cercle de la loi d\'Ohm');
        self::assertSame(39, $byStatus[CalculatorTool::STATUS_AVAILABLE] + $byStatus[CalculatorTool::STATUS_LEGACY], 'Outils réels de l\'ancien site');
    }

    /**
     * Vérifie que chaque outil porté a sa description, son interface et sa norme proposée,
     * et qu'il est visible par défaut ; les autres ne le sont jamais.
     */
    public function testOnlyPortedToolsArePublic(): void
    {
        $projectDir = static::getContainer()->getParameter('kernel.project_dir');
        foreach ($this->allTools() as $tool) {
            if (CalculatorTool::STATUS_AVAILABLE !== $tool->status) {
                self::assertFalse($tool->isPublic(), $tool->getKey());
                continue;
            }
            self::assertTrue($tool->isPublic(), $tool->getKey());
            self::assertNotEmpty($tool->summary, $tool->getKey().' : description manquante');
            self::assertLessThanOrEqual(160, mb_strlen((string) ($tool->metaDescription ?? $tool->summary)), $tool->getKey().' : description trop longue pour la balise meta (ajouter meta_description)');
            self::assertFileExists($projectDir.'/templates/'.$tool->getTemplate());
            self::assertNotNull($tool->reference, $tool->getKey().' : norme ou source à proposer');
            self::assertNull($tool->getPublicReference(), 'Une norme proposée n\'est jamais affichée avant validation.');
        }
        self::assertSame(39, $this->registry()->countPublicTools());
    }

    /**
     * Vérifie que les outils fusionnés pointent vers un outil existant.
     */
    public function testMergedToolsPointToExistingTools(): void
    {
        foreach ($this->allTools() as $tool) {
            if (CalculatorTool::STATUS_MERGED === $tool->status) {
                [$category, $slug] = explode('/', (string) $tool->mergedInto);
                self::assertNotNull($this->registry()->getTool($category, $slug), $tool->getKey());
            }
        }
    }

    /**
     * Vérifie la prise en compte des réglages de l'admin : désactivation, norme validée, ordre.
     */
    public function testAdminSettingsOverrideRegistryDefaults(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist((new CalculatorToolSetting('electricite/steinmetz'))->setEnabled(false));
        $entityManager->persist((new CalculatorToolSetting('electricite/chute-tension'))->setReferenceVerified(true)->setPosition(1));
        $entityManager->persist((new CalculatorToolSetting('electricite/loi-ohm'))->setReference('Ma référence')->setReferenceVerified(true));
        // Un outil non porté ne devient jamais visible, même « activé ».
        $entityManager->persist((new CalculatorToolSetting('eclairage/lux-lumens'))->setEnabled(true));
        $entityManager->flush();

        $registry = $this->registry();
        self::assertFalse($registry->getTool('electricite', 'steinmetz')?->isPublic());
        self::assertSame('NF C 15-100 / UTE C 15-105 (chute de tension), méthode simplifiée', $registry->getTool('electricite', 'chute-tension')?->getPublicReference());
        self::assertSame('Ma référence', $registry->getTool('electricite', 'loi-ohm')?->getPublicReference());
        self::assertFalse($registry->getTool('eclairage', 'lux-lumens')?->isPublic());
        self::assertSame(38, $registry->countPublicTools());

        $electricity = $registry->getCategory('electricite');
        self::assertNotNull($electricity);
        self::assertSame('chute-tension', $electricity->getPublicTools()[0]->slug, 'Ordre réglé dans l\'admin');
    }

    /**
     * Renvoie le registre du conteneur de test (rechargé).
     */
    private function registry(): CalculatorRegistry
    {
        self::bootKernel();
        $registry = static::getContainer()->get(CalculatorRegistry::class);
        $registry->reset();

        return $registry;
    }

    /**
     * Renvoie tous les outils du registre.
     *
     * @return list<CalculatorTool>
     */
    private function allTools(): array
    {
        return array_merge(...array_map(static fn ($category): array => $category->tools, $this->registry()->getCategories()));
    }
}
