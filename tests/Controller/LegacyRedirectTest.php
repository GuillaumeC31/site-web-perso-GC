<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\LegacyRedirectController;
use App\Entity\Project;
use App\Enum\ProjectType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Redirections 301 des adresses publiques de l'ancien site (v1) vers la v2 : chaque adresse du tableau,
 * les anciens projets (publié → sa page, inconnu → la liste), les adresses inchangées (aucune redirection)
 * et la barre oblique finale.
 *
 * Liens :
 * - Couvre : LegacyRedirectController, CalculatorController (adresses inchangées)
 */
final class LegacyRedirectTest extends WebTestCase
{
    /**
     * Vérifie chaque ancienne adresse fixe : 301 vers l'adresse v2 attendue.
     */
    #[DataProvider('fixedRedirectProvider')]
    public function testOldAddressRedirectsPermanently(string $oldUrl, string $newUrl): void
    {
        $client = self::createClient();
        $client->request('GET', $oldUrl);

        self::assertResponseRedirects($newUrl, 301);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function fixedRedirectProvider(): iterable
    {
        $expected = [
            '/projects' => '/projets',
            '/pro' => '/espace-recruteur',
            '/pro/request-access' => '/espace-recruteur',
            '/pro/login' => '/espace-recruteur',
            '/pro/login-check' => '/espace-recruteur',
            '/pro/link-expired' => '/espace-recruteur/lien-invalide',
            '/private' => '/espace-recruteur/accueil',
            '/private/profile' => '/espace-recruteur/accueil',
            '/private/cv/ats' => '/espace-recruteur/cv/ats',
            '/private/cv/design' => '/espace-recruteur/cv/design',
            '/private/cv/design/preview' => '/espace-recruteur/cv/design',
            '/politique-de-confidentialite' => '/confidentialite',
            '/maintenance' => '/',
            '/logout' => '/',
        ];
        // Le tableau du contrôleur et celui du test doivent couvrir exactement les mêmes adresses
        self::assertSame(array_keys(LegacyRedirectController::REDIRECTS), array_keys($expected));
        foreach ($expected as $old => $new) {
            yield $old => [$old, $new];
        }
    }

    /**
     * Vérifie les anciens projets : publié et complet → sa page v2 ; inconnu ou brouillon → la liste.
     */
    public function testOldProjectAddresses(): void
    {
        $client = self::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $project = (new Project())->setType(ProjectType::Lab)->setTitle('Simulateur d\'aube')->setSlug('simulateur-aube-crepuscule-esp32')
            ->setSummary('Résumé du projet.')->setContent('Contenu du projet.')->setIsPublished(true);
        $entityManager->persist($project);
        $entityManager->flush();
        self::assertTrue($project->isPubliclyVisible(), 'Le projet de test doit être public.');

        $client->request('GET', '/projects/simulateur-aube-crepuscule-esp32');
        self::assertResponseRedirects('/projets/simulateur-aube-crepuscule-esp32', 301);
        $client->request('GET', '/projects/projet-disparu');
        self::assertResponseRedirects('/projets', 301);
    }

    /**
     * Vérifie que les adresses identiques sur les deux sites ne sont pas redirigées, et qu'une barre oblique
     * finale de l'ancien site mène aussi à la bonne page.
     */
    public function testUnchangedAddressesAndTrailingSlash(): void
    {
        $client = self::createClient();
        foreach (['/', '/mentions-legales', '/calculatrice', '/calculatrice/electricite/loi-ohm'] as $url) {
            $client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }

        $client->request('GET', '/projects/');
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseRedirects('/projets', 301);
    }
}
