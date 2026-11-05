<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\EnergyProvider;
use App\Entity\FGasRule;
use App\Entity\Fluid;
use App\Entity\Printer3d;
use App\Entity\Refrigerant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests du chargement des données de référence de la calculatrice (app:calculator:load-reference-data).
 *
 * Liens :
 * - Couvre : LoadReferenceDataCommand, config/calculator/reference_data.yaml,
 *   config/calculator/refrigerants.json, config/calculator/fgas_rules.json
 */
final class LoadReferenceDataCommandTest extends KernelTestCase
{
    /**
     * Vérifie le chargement des données de l'ancien site (20 fluides, 33 imprimantes, 11 offres).
     */
    public function testLoadsReferenceDataOfTheOldSite(): void
    {
        $tester = $this->loadReferenceData();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $entityManager = $this->getEntityManager();
        self::assertSame(20, $entityManager->getRepository(Fluid::class)->count([]));
        self::assertSame(33, $entityManager->getRepository(Printer3d::class)->count([]));
        self::assertSame(11, $entityManager->getRepository(EnergyProvider::class)->count([]));

        $water = $entityManager->getRepository(Fluid::class)->findOneBy(['name' => 'Eau liquide']);
        self::assertNotNull($water);
        self::assertSame(1000.0, $water->getDensity());
        self::assertSame(4185.0, $water->getSpecificHeatCapacity());
    }

    /**
     * Vérifie qu'un second lancement n'ajoute rien et ne modifie pas une valeur changée dans l'admin.
     */
    public function testSecondRunAddsNothingAndKeepsAdminChanges(): void
    {
        $this->loadReferenceData();
        $entityManager = $this->getEntityManager();
        $provider = $entityManager->getRepository(EnergyProvider::class)->findOneBy(['name' => 'EDF', 'planName' => 'Tarif Bleu (Base)']);
        self::assertNotNull($provider);
        $provider->setPriceKwh(0.2516);
        $entityManager->flush();

        $tester = $this->loadReferenceData();
        self::assertStringContainsString('Ajoutés : 0 fluide(s), 0 imprimante(s), 0 offre(s) d\'électricité, 0 fluide(s) frigorigène(s), 0 règle(s) F-Gas', $tester->getDisplay());
        $entityManager->clear();
        self::assertSame(0.2516, $entityManager->getRepository(EnergyProvider::class)->findOneBy(['name' => 'EDF', 'planName' => 'Tarif Bleu (Base)'])?->getPriceKwh());
        self::assertSame(11, $entityManager->getRepository(EnergyProvider::class)->count([]));
    }

    /**
     * Vérifie le chargement des fluides frigorigènes (22 de l'ancien site et 4 ajoutés) et des règles
     * F-Gas R1 à R4 (mise sur le marché) et S1, S2 (entretien), toutes non vérifiées (donc masquées sur le site).
     */
    public function testLoadsRefrigerantsAndUnverifiedFGasRules(): void
    {
        $this->loadReferenceData();
        $entityManager = $this->getEntityManager();

        self::assertSame(26, $entityManager->getRepository(Refrigerant::class)->count([]));
        self::assertSame(0, $entityManager->getRepository(Refrigerant::class)->count(['verified' => true]));
        $r449a = $entityManager->getRepository(Refrigerant::class)->findOneBy(['code' => 'R449A']);
        self::assertNotNull($r449a);
        self::assertSame(1397, $r449a->getGwp());
        self::assertSame('AR4', $r449a->getGwpReport());

        $rules = $entityManager->getRepository(FGasRule::class)->findBy([], ['position' => 'ASC']);
        self::assertSame(['R1', 'R2', 'R3', 'R4', 'S1', 'S2'], array_map(static fn (FGasRule $rule): string => $rule->getCode(), $rules));
        self::assertSame(['placing', 'placing', 'placing', 'placing', 'servicing', 'servicing'], array_map(static fn (FGasRule $rule): string => $rule->getKind(), $rules));
        self::assertSame([2500, 150, 150, 150, 2500, 750], array_map(static fn (FGasRule $rule): int => $rule->getGwpThreshold(), $rules));
        self::assertSame(40.0, $rules[1]->getMinCapacityKw());
        self::assertSame('2030-01-01', $rules[3]->getSince()?->format('Y-m-d'));
        self::assertSame(0, $entityManager->getRepository(FGasRule::class)->count(['verified' => true]));
        self::assertSame(0.038, $entityManager->getRepository(Refrigerant::class)->findOneBy(['code' => 'R290'])?->getLfl());
        // Fluide par défaut de la chambre froide : R-290 seul (décision du 2026-10-23)
        self::assertSame(['R290'], array_map(static fn (Refrigerant $fluid): string => $fluid->getCode(), $entityManager->getRepository(Refrigerant::class)->findBy(['defaultChoice' => true])));
    }

    /**
     * Lance la commande.
     */
    private function loadReferenceData(): CommandTester
    {
        self::bootKernel();
        $tester = new CommandTester((new Application(self::$kernel))->find('app:calculator:load-reference-data'));
        $tester->execute([]);

        return $tester;
    }

    /**
     * Renvoie le gestionnaire Doctrine du conteneur de test.
     */
    private function getEntityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
