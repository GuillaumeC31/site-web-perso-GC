<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\ClimaticProfile;
use App\Tests\Support\AdminTestTrait;
use App\Tests\Support\VisibleTextAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests de l'admin des profils de consigne de l'enceinte climatique v2, avec un admin entièrement connecté.
 *
 * Liens :
 * - Couvre : Admin\ClimaticProfileController, ClimaticProfileType, ClimaticProfile::toCalculatorArray()
 */
final class ClimaticProfileAdminTest extends WebTestCase
{
    use VisibleTextAssertionsTrait;
    use AdminTestTrait;

    /**
     * Vérifie que la liste s'affiche et figure dans la navigation.
     */
    public function testListIsReachable(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);

        $client->request('GET', $this->adminUrl('/calculatrice/profils-climatiques'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Profils climatiques');
        self::assertSelectorTextContains('nav', 'Profils climatiques');
    }

    /**
     * Vérifie le refus d'un code au mauvais format, puis l'ajout d'un profil sans humidité régulée.
     */
    public function testCreateProfileChecksCodeAndAllowsUnregulatedHumidity(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $fields = [
            'climatic_profile[sector]' => 'Industriel', 'climatic_profile[name]' => 'Essai à froid', 'climatic_profile[temperature]' => '-10',
            'climatic_profile[temperatureTolerance]' => '3', 'climatic_profile[source]' => 'Cahier des charges client',
        ];

        $crawler = $client->request('GET', $this->adminUrl('/calculatrice/profils-climatiques/nouveau'));
        $client->submit($crawler->selectButton('Enregistrer')->form(['climatic_profile[code]' => 'Essai froid'] + $fields), [], ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Le code ne contient que des minuscules');

        $crawler = $client->request('GET', $this->adminUrl('/calculatrice/profils-climatiques/nouveau'));
        $client->submit($crawler->selectButton('Enregistrer')->form(['climatic_profile[code]' => 'test_cold'] + $fields), [], ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseRedirects($this->adminUrl('/calculatrice/profils-climatiques'));
        $profile = $this->getEntityManager()->getRepository(ClimaticProfile::class)->findOneBy(['code' => 'test_cold']);
        self::assertNotNull($profile);
        self::assertNull($profile->getHumidity());
        self::assertFalse($profile->isVerified());
        self::assertSame(['tSet' => -10.0, 'rhSet' => null], array_intersect_key($profile->toCalculatorArray(), ['tSet' => 0, 'rhSet' => 0]));
    }
}
