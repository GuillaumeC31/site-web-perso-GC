<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\CalculatorToolSetting;
use App\Entity\Fluid;
use App\Tests\Support\AdminTestTrait;
use App\Tests\Support\VisibleTextAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * Tests de l'admin de la calculatrice (lot 5a), avec un admin entièrement connecté.
 *
 * Liens :
 * - Couvre : Admin\CalculatorToolController, CalculatorToolSettingType, Admin\FluidController,
 *   ContentCompletenessChecker (normes à vérifier)
 */
final class CalculatorAdminTest extends WebTestCase
{
    use VisibleTextAssertionsTrait;
    use AdminTestTrait;

    /**
     * Vérifie la liste : les 69 outils du registre, avec leur état.
     */
    public function testToolListShowsAllRegistryTools(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);

        $crawler = $client->request('GET', $this->adminUrl('/calculatrice/outils'));
        self::assertResponseIsSuccessful();
        self::assertCount(69, $crawler->filter('tbody tr'));
        // Lot 5 terminé : tous les outils de l'ancien site sont portés (plus aucun « À porter »)
        self::assertSelectorTextContains('main', 'Porté');
        self::assertSelectorTextNotContains('main', 'À porter');
        self::assertSelectorTextContains('main', 'À créer');
        self::assertSelectorTextContains('main', 'Fusionné');
        self::assertSelectorTextContains('main', 'à vérifier');
    }

    /**
     * Vérifie le réglage d'un outil porté : désactivation, norme validée et texte d'aide.
     */
    public function testEditPortedToolSettings(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);

        $crawler = $client->request('GET', $this->adminUrl('/calculatrice/outils/electricite/chute-tension'));
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Enregistrer')->form([
            'calculator_tool_setting[helpText]' => 'Aide de test.',
        ]);
        foreach (['calculator_tool_setting[enabled]' => false, 'calculator_tool_setting[referenceVerified]' => true] as $name => $checked) {
            $checkbox = $form[$name];
            self::assertInstanceOf(ChoiceFormField::class, $checkbox);
            $checked ? $checkbox->tick() : $checkbox->untick();
        }
        $client->submit($form, [], ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseRedirects($this->adminUrl('/calculatrice/outils'));

        $setting = $this->getEntityManager()->getRepository(CalculatorToolSetting::class)->findOneBy(['toolKey' => 'electricite/chute-tension']);
        self::assertNotNull($setting);
        self::assertFalse($setting->isEnabled());
        self::assertTrue($setting->isReferenceVerified());
        self::assertNull($setting->getReference(), 'Champ laissé vide : la norme proposée est gardée.');
        self::assertSame('Aide de test.', $setting->getHelpText());

        // Outil désactivé : l'ancienne adresse mène à sa catégorie (302, l'outil pourra être réactivé)
        $client->request('GET', '/calculatrice/electricite/chute-tension');
        self::assertResponseRedirects('/calculatrice/electricite', 302);
    }

    /**
     * Vérifie qu'un outil à créer (lot 5-bis) ne propose pas l'activation.
     */
    public function testPlannedToolCannotBeEnabled(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);

        $client->request('GET', $this->adminUrl('/calculatrice/outils/eclairage/lux-lumens'));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('input[name="calculator_tool_setting[enabled]"]');
        self::assertSelectorTextContains('main', 'Il sera créé au lot 5-bis');

        $client->request('GET', $this->adminUrl('/calculatrice/outils/electricite/inconnu'));
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Vérifie le tableau de bord : normes proposées à vérifier signalées.
     */
    public function testDashboardSignalsReferencesToVerify(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);

        $client->request('GET', $this->adminUrl());
        self::assertSelectorTextContains('main', 'vérifier la norme ou la source citée de 39 outil(s)');
    }

    /**
     * Vérifie l'ajout d'un fluide de référence par l'admin générique.
     */
    public function testCreateFluid(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);

        $crawler = $client->request('GET', $this->adminUrl('/calculatrice/fluides/nouveau'));
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Enregistrer')->form([
            'fluid[name]' => 'Fluide de test',
            'fluid[state]' => 'gaz',
            'fluid[density]' => '1.2',
            'fluid[specificHeatCapacity]' => '1005',
        ]), [], ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseRedirects($this->adminUrl('/calculatrice/fluides'));
        $fluid = $this->getEntityManager()->getRepository(Fluid::class)->findOneBy(['name' => 'Fluide de test']);
        self::assertNotNull($fluid);
        self::assertSame(1.2, $fluid->getDensity());
    }
}
