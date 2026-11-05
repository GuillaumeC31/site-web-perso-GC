<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CalculatorToolSetting;
use App\Entity\EnergyProvider;
use App\Entity\FGasRule;
use App\Entity\Fluid;
use App\Entity\PanelModel;
use App\Entity\Printer3d;
use App\Entity\Refrigerant;
use App\Enum\FluidState;
use App\Tests\Support\VisibleTextAssertionsTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests des pages publiques de la calculatrice (lot 5a).
 *
 * Règles vérifiées : une page par outil porté (titre, description, avertissement, encart de
 * l'auteur), outils non portés ou désactivés introuvables, anciennes adresses fusionnées
 * redirigées, norme affichée seulement une fois validée, nombre d'outils calculé.
 *
 * Liens :
 * - Couvre : CalculatorController, CalculatorRegistry, templates/calculator/*
 */
final class CalculatorPagesTest extends WebTestCase
{
    use VisibleTextAssertionsTrait;

    /**
     * Vérifie le tableau de bord : nombre d'outils calculé, recherche, liens vers les outils portés.
     */
    public function testDashboardListsPublicTools(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/calculatrice');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Outils de calcul technique');
        self::assertSelectorTextContains('main', '39 outils gratuits');
        self::assertSelectorExists('input[type="search"][data-action="input->calculator--dashboard#search"]');
        self::assertCount(39, $crawler->filter('[data-calculator--dashboard-target="tool"]'));
        self::assertSelectorExists('a[href="/calculatrice/electricite/loi-ohm"]');
        self::assertSelectorExists('a[href="/calculatrice/convertisseur/unites"]');
        self::assertSelectorNotExists('a[href="/calculatrice/eclairage/lux-lumens"]');

        // Navigation par catégorie (colonne et pastilles) : ancres vers les sections, catégories publiques seulement
        self::assertCount(2, $crawler->filter('nav[aria-label="Catégories d\'outils"]'));
        self::assertCount(2, $crawler->filter('nav a[href="#electricite"]'));
        self::assertSelectorExists('section#electricite h2#category-electricite');
        self::assertSelectorExists('section#thermodynamique');
        self::assertSelectorExists('nav a[href="#terrariophilie"]');
        self::assertSelectorNotExists('nav a[href="#reseaux-it"]');
        self::assertSelectorTextContains('nav a[href="#electricite"] [data-count]', '6');
        self::assertSelectorNotExists('[data-calculator--dashboard-target="recents"]');
        self::assertSelectorNotExists('a[href="/calculatrice/electricite/trigonometrie"]');
    }

    /**
     * Vérifie la page de chaque outil porté : titre, description, interface, avertissement, auteur.
     */
    #[DataProvider('providePortedTools')]
    public function testPortedToolPageIsComplete(string $path, string $controller): void
    {
        $client = self::createClient();
        $client->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('h1');
        self::assertSelectorExists('meta[name="description"][content]');
        self::assertSelectorExists('[data-controller~="'.$controller.'"]');
        self::assertSelectorExists('[data-controller="calculator--tool"]');
        self::assertSelectorTextContains('main', 'Résultats fournis à titre indicatif');
        self::assertSelectorTextContains('main', 'Outil conçu par');
        self::assertSelectorExists('aside a[href="/cv"]');
        self::assertSelectorTextNotContains('main', 'Norme ou source');
    }

    /**
     * Outils portés (lots 5a à 5d) et leur contrôleur Stimulus.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function providePortedTools(): iterable
    {
        $tools = [
            'electricite' => ['loi-ohm', 'puissances-ac', 'relevement-cos-phi', 'steinmetz', 'calculs-cables', 'chute-tension'],
            'electronique' => ['resistance-led', 'diviseur-tension', 'autonomie-batterie', 'code-couleur-resistance'],
            'mecanique' => ['engrenages', 'verin', 'flexion-poutre'],
            'impression-3d' => ['steps-mm', 'cout-impression'],
            'convertisseur' => ['unites'],
            'eclairage' => ['adresse-dmx', 'eclairage-phyto', 'vieillissement-uv'],
            'terrariophilie' => ['eclairage-reptiles', 'chauffage-terrarium', 'volume-substrat', 'brumisation', 'bassin-paludarium', 'ventilation-air'],
            'thermodynamique' => ['chauffe-eau', 'chauffe-fluide', 'point-rosee', 'gaz-parfaits', 'fluides-frigorifiques', 'chambre-froide', 'enceinte-climatique'],
        ];
        foreach ($tools as $category => $slugs) {
            foreach ($slugs as $slug) {
                yield $slug => ['/calculatrice/'.$category.'/'.$slug, 'calculator--'.$slug];
            }
        }
        // Pages partageant un même contrôleur (gabarits _pt-sensor et _scaling).
        foreach (['pt100', 'pt1000'] as $slug) {
            yield $slug => ['/calculatrice/mesures-capteurs/'.$slug, 'calculator--pt-sensor'];
        }
        foreach (['signal-4-20ma', 'signal-0-20ma', 'signal-0-10v', 'transmetteur-humidite', 'transmetteur-temperature'] as $slug) {
            yield $slug => ['/calculatrice/mesures-capteurs/'.$slug, 'calculator--scaling'];
        }
    }

    /**
     * Vérifie que l'outil de chauffe d'un fluide propose les fluides de l'admin, avec leurs propriétés.
     */
    public function testFluidHeatingListsReferenceFluids(): void
    {
        $client = self::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist((new Fluid())->setName('Fluide test')->setState(FluidState::Gas)->setDensity(1.5)->setSpecificHeatCapacity(1234));
        $entityManager->flush();

        $client->request('GET', '/calculatrice/thermodynamique/chauffe-fluide');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#fh-fluid option[data-cp="1234"][data-density="1.5"]');
        self::assertSelectorTextContains('#fh-fluid', 'Fluide test (gaz)');
        self::assertSelectorExists('#fh-fluid option[value="custom"]');
    }

    /**
     * Vérifie que l'outil « Fluides frigorigènes » reçoit la base de l'admin (valeur JSON du contrôleur).
     */
    public function testRefrigerantToolReceivesAdminData(): void
    {
        $client = self::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist((new Refrigerant())->setCode('R999X')->setName('R-999X')->setFamily('HFO')->setGwp(5)
            ->setStatus('Avenir')->setSafetyClass('A2L'));
        $entityManager->flush();

        $crawler = $client->request('GET', '/calculatrice/thermodynamique/fluides-frigorifiques');
        self::assertResponseIsSuccessful();
        $fluids = json_decode((string) $crawler->filter('[data-controller="calculator--fluides-frigorifiques"]')->attr('data-calculator--fluides-frigorifiques-fluids-value'), true);
        self::assertIsArray($fluids);
        self::assertContains('R999X', array_column($fluids, 'code'));
    }

    /**
     * Vérifie que la chambre froide ne reçoit que les règles F-Gas vérifiées dans l'admin, avec les
     * fluides et les modèles de panneaux de l'admin.
     */
    public function testColdRoomReceivesOnlyVerifiedFGasRules(): void
    {
        $client = self::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $rule = (new FGasRule())->setCode('T1')->setEquipment('Test')->setInstallationTypes(['split'])->setGwpThreshold(150)
            ->setSince(new \DateTimeImmutable('2030-01-01'))->setArticle('art. 11')->setSource('Test');
        $entityManager->persist($rule);
        $entityManager->persist((new PanelModel())->setBrand('Marque')->setProductRange('Gamme')->setInsulation('PIR')->setThickness(100)
            ->setUValue(0.2)->setSourceDocument('Fiche')->setSourceDate(new \DateTimeImmutable('2025-12-01')));
        $entityManager->flush();

        $read = function () use ($client): array {
            $crawler = $client->request('GET', '/calculatrice/thermodynamique/chambre-froide');
            self::assertResponseIsSuccessful();
            $root = $crawler->filter('[data-controller~="calculator--chambre-froide"]');

            return [
                json_decode((string) $root->attr('data-calculator--chambre-froide-rules-value'), true),
                json_decode((string) $root->attr('data-calculator--chambre-froide-panels-value'), true),
            ];
        };
        [$rules, $panels] = $read();
        self::assertSame([], $rules);
        self::assertSame('Marque Gamme 100 mm', $panels[0]['label']);
        self::assertSelectorTextContains('#cf-s-panel', 'Marque Gamme 100 mm');

        $rule->setVerified(true);
        $entityManager->flush();
        [$rules] = $read();
        self::assertSame(['T1'], array_column($rules, 'code'));
    }

    /**
     * Vérifie que l'outil de coût d'impression propose les imprimantes et offres de l'admin.
     */
    public function testPrintCostListsReferenceData(): void
    {
        $client = self::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist((new Printer3d())->setBrand('Marque test')->setModel('Modèle test')->setPowerConsumption(321));
        $entityManager->persist((new EnergyProvider())->setName('Fournisseur test')->setPlanName('Offre test')->setPriceKwh(0.1952));
        $entityManager->flush();

        $client->request('GET', '/calculatrice/impression-3d/cout-impression');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#cost-printer option[value="321"]');
        self::assertSelectorTextContains('#cost-printer', 'Marque test Modèle test (321 W)');
        self::assertSelectorTextContains('#cost-provider', 'Fournisseur test — Offre test (0,1952 €/kWh)');
    }

    /**
     * Vérifie les adresses d'outils indisponibles : outil inconnu ou catégorie inconnue → 404 ; outil à créer
     * ou désactivé (adresse de l'ancien site) → redirection temporaire (302) vers sa catégorie, puis vers la
     * calculatrice si la catégorie n'a encore aucun outil public.
     */
    public function testUnavailableToolsReturn404(): void
    {
        $client = self::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist((new CalculatorToolSetting('electricite/steinmetz'))->setEnabled(false));
        $entityManager->flush();

        foreach (['/calculatrice/electricite/inconnu', '/calculatrice/inconnue'] as $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
        foreach (['/calculatrice/eclairage/lux-lumens' => '/calculatrice/eclairage', '/calculatrice/electricite/trigonometrie' => '/calculatrice/electricite', '/calculatrice/electricite/steinmetz' => '/calculatrice/electricite', '/calculatrice/reseaux-it' => '/calculatrice'] as $url => $target) {
            $client->request('GET', $url);
            self::assertResponseRedirects($target, 302, $url);
        }

        $client->request('GET', '/calculatrice');
        self::assertSelectorNotExists('a[href="/calculatrice/electricite/steinmetz"]');
        self::assertSelectorTextContains('main', '38 outils gratuits');
    }

    /**
     * Vérifie que les anciennes adresses des outils fusionnés redirigent (301).
     */
    public function testMergedToolsRedirectPermanently(): void
    {
        $client = self::createClient();

        $client->request('GET', '/calculatrice/electricite/cercle-loi-ohm');
        self::assertResponseRedirects('/calculatrice/electricite/loi-ohm', 301);

        $client->request('GET', '/calculatrice/electronique/boucle-4-20');
        self::assertResponseRedirects('/calculatrice/mesures-capteurs/signal-4-20ma', 301);
    }

    /**
     * Vérifie que la norme n'est affichée qu'une fois validée, et que le texte d'aide s'affiche.
     */
    public function testVerifiedReferenceAndHelpTextAreShown(): void
    {
        $client = self::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist((new CalculatorToolSetting('electricite/chute-tension'))->setReferenceVerified(true)->setHelpText("Premier paragraphe.\n\nSecond paragraphe."));
        $entityManager->flush();

        $client->request('GET', '/calculatrice/electricite/chute-tension');
        self::assertSelectorTextContains('main', 'Norme ou source : NF C 15-100 / UTE C 15-105 (chute de tension), méthode simplifiée');
        self::assertSelectorTextContains('#tool-help-title + div', 'Second paragraphe.');
    }

    /**
     * Vérifie la page d'une catégorie (outils regroupés).
     */
    public function testCategoryPageGroupsTools(): void
    {
        $client = self::createClient();
        $client->request('GET', '/calculatrice/electricite');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Électricité');
        self::assertSelectorTextContains('main', 'Courant alternatif');
        self::assertSelectorExists('a[href="/calculatrice/electricite/relevement-cos-phi"]');
    }
}
