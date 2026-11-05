<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\FGasRule;
use App\Entity\PanelModel;
use App\Entity\Refrigerant;
use App\Tests\Support\AdminTestTrait;
use App\Tests\Support\VisibleTextAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * Tests de l'admin des données de la chambre froide v2 (lot 5e) : fluides frigorigènes, règles
 * F-Gas, modèles de panneaux, avec un admin entièrement connecté.
 *
 * Liens :
 * - Couvre : Admin\RefrigerantController, Admin\FGasRuleController, Admin\PanelModelController,
 *   RefrigerantType, FGasRuleType, PanelModelType, PanelModel::validateThermalValue()
 */
final class RefrigerationAdminTest extends WebTestCase
{
    use VisibleTextAssertionsTrait;
    use AdminTestTrait;

    /**
     * Vérifie que les trois listes s'affichent et figurent dans la navigation.
     */
    public function testListsAreReachable(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);

        foreach (['fluides-frigorigenes' => 'Fluides frigorigènes', 'regles-fgas' => 'Règles F-Gas', 'panneaux' => 'Panneaux isolants'] as $path => $title) {
            $client->request('GET', $this->adminUrl('/calculatrice/'.$path));
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', $title);
            self::assertSelectorTextContains('nav', $title);
        }
    }

    /**
     * Vérifie l'ajout d'un fluide et le refus d'un code au mauvais format.
     */
    public function testCreateRefrigerantChecksCode(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $fields = [
            'refrigerant[name]' => 'R-999X', 'refrigerant[family]' => 'HFO', 'refrigerant[gwp]' => '5',
            'refrigerant[gwpReport]' => 'AR4', 'refrigerant[status]' => 'Avenir', 'refrigerant[safetyClass]' => 'A2L', 'refrigerant[glide]' => '0,5',
        ];

        $crawler = $client->request('GET', $this->adminUrl('/calculatrice/fluides-frigorigenes/nouveau'));
        $client->submit($crawler->selectButton('Enregistrer')->form(['refrigerant[code]' => 'R-999X'] + $fields), [], ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Le code commence par R, sans tiret ni espace');

        $crawler = $client->request('GET', $this->adminUrl('/calculatrice/fluides-frigorigenes/nouveau'));
        $client->submit($crawler->selectButton('Enregistrer')->form(['refrigerant[code]' => 'R999X'] + $fields), [], ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseRedirects($this->adminUrl('/calculatrice/fluides-frigorigenes'));
        $fluid = $this->getEntityManager()->getRepository(Refrigerant::class)->findOneBy(['code' => 'R999X']);
        self::assertNotNull($fluid);
        self::assertSame(0.5, $fluid->getGlide());
        self::assertFalse($fluid->isVerified());
    }

    /**
     * Vérifie l'ajout d'une règle F-Gas (types cochés, date), non vérifiée par défaut.
     */
    public function testCreateFGasRule(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);

        $crawler = $client->request('GET', $this->adminUrl('/calculatrice/regles-fgas/nouveau'));
        $form = $crawler->selectButton('Enregistrer')->form([
            'f_gas_rule[code]' => 'T1', 'f_gas_rule[equipment]' => 'Équipement de test', 'f_gas_rule[gwpThreshold]' => '150',
            'f_gas_rule[since]' => '2030-01-01', 'f_gas_rule[article]' => 'art. 11', 'f_gas_rule[source]' => 'Source de test',
        ]);
        $split = $form['f_gas_rule[installationTypes][1]'];
        self::assertInstanceOf(ChoiceFormField::class, $split);
        $split->tick();
        $client->submit($form, [], ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseRedirects($this->adminUrl('/calculatrice/regles-fgas'));
        $rule = $this->getEntityManager()->getRepository(FGasRule::class)->findOneBy(['code' => 'T1']);
        self::assertNotNull($rule);
        self::assertSame(['split'], $rule->getInstallationTypes());
        self::assertSame('2030-01-01', $rule->getSince()?->format('Y-m-d'));
        self::assertFalse($rule->isVerified());
    }

    /**
     * Vérifie qu'un panneau exige U ou λ, et que U est calculé depuis λ et l'épaisseur.
     */
    public function testCreatePanelModelRequiresUOrLambda(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $fields = [
            'panel_model[brand]' => 'Marque test', 'panel_model[productRange]' => 'Gamme test', 'panel_model[insulation]' => 'PIR',
            'panel_model[thickness]' => '100', 'panel_model[sourceDocument]' => 'Fiche test', 'panel_model[sourceDate]' => '2025-12-15',
        ];

        $crawler = $client->request('GET', $this->adminUrl('/calculatrice/panneaux/nouveau'));
        $client->submit($crawler->selectButton('Enregistrer')->form($fields), [], ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Renseignez le coefficient U de la fiche ou la conductivité λ');

        $crawler = $client->request('GET', $this->adminUrl('/calculatrice/panneaux/nouveau'));
        $client->submit($crawler->selectButton('Enregistrer')->form(['panel_model[lambda]' => '0.022'] + $fields), [], ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseRedirects($this->adminUrl('/calculatrice/panneaux'));
        $panel = $this->getEntityManager()->getRepository(PanelModel::class)->findOneBy(['productRange' => 'Gamme test']);
        self::assertNotNull($panel);
        // U = 1 / (0,17 + 0,100 / 0,022) = 1 / 4,7155 = 0,2121 W/(m²·K)
        self::assertEqualsWithDelta(0.2121, $panel->getEffectiveU(), 0.0001);
        self::assertSame('Marque test Gamme test 100 mm', $panel->toCalculatorArray()['label']);
    }
}
