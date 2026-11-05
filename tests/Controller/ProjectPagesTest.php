<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Project;
use App\Entity\ProjectCategory;
use App\Entity\ProjectImage;
use App\Enum\ProjectType;
use App\Enum\ProjectVisibility;
use App\Service\Content\SlugGenerator;
use App\Tests\Support\VisibleTextAssertionsTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests du lot 4 : pages publiques des projets et études de cas de l'accueil.
 *
 * Règles vérifiées : seuls les projets publiés, publics et complets sont affichés ;
 * études de cas avant le Labo ; filtres par type et par catégorie ; contenu nettoyé.
 *
 * Liens :
 * - Couvre : ProjectController, ProjectRepository::findPublic(), Project::isPubliclyVisible(),
 *   HomeController (études de cas), templates project/ et home/
 */
final class ProjectPagesTest extends WebTestCase
{
    use VisibleTextAssertionsTrait;

    /**
     * Vérifie qu'une étude de cas complète et publiée apparaît dans la liste, sur l'accueil et en détail.
     */
    public function testCompleteCaseStudyIsShownEverywhere(): void
    {
        $client = self::createClient();
        $this->createProject('Enceinte de stabilité');

        $client->request('GET', '/projets');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#case-studies-title', 'Études de cas');
        self::assertSelectorExists('a[href="/projets/enceinte-de-stabilite"]');

        $client->request('GET', '/');
        self::assertSelectorExists('a[href="/projets/enceinte-de-stabilite"]');

        $client->request('GET', '/projets/enceinte-de-stabilite');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Enceinte de stabilité');
        self::assertSelectorTextContains('main', 'Un laboratoire pharmaceutique');
        self::assertSelectorTextContains('main', 'Stabilité de ±0,5 °C atteinte');
        self::assertSelectorTextContains('main', 'Régulation');
        self::assertSelectorExists('meta[name="robots"][content="index, follow"]');
    }

    /**
     * Vérifie que brouillon, projet réservé aux recruteurs et projet incomplet ne sont jamais publics.
     */
    public function testHiddenProjectsAreNeverPublic(): void
    {
        $client = self::createClient();
        $this->createProject('Brouillon caché', ['published' => false]);
        $this->createProject('Réservé recruteurs', ['visibility' => ProjectVisibility::Recruiter]);
        $this->createProject('Étude incomplète', ['result' => '[À COMPLÉTER : résultat chiffré]']);

        foreach (['/projets', '/'] as $url) {
            $client->request('GET', $url);
            self::assertSelectorTextNotContains('main', 'Brouillon caché');
            self::assertSelectorTextNotContains('main', 'Réservé recruteurs');
            self::assertSelectorTextNotContains('main', 'Étude incomplète');
            self::assertSelectorTextNotContains('main', 'COMPLÉTER');
        }

        foreach (['brouillon-cache', 'reserve-recruteurs', 'etude-incomplete'] as $slug) {
            $client->request('GET', '/projets/'.$slug);
            self::assertResponseStatusCodeSame(404);
        }
    }

    /**
     * Vérifie qu'avec des études de cas encore en brouillon (TEXTES_SITE § 12 : section masquée tant qu'elle
     * n'est pas rédigée), le public ne voit ni section, ni titre vide, ni onglet ou lien vers elles.
     */
    public function testDraftCaseStudiesLeaveNoEmptyTitleNorDeadLink(): void
    {
        $client = self::createClient();
        $this->createProject('Étude en brouillon', ['published' => false]);
        $this->createProject('Étude à rédiger', ['published' => false, 'result' => '[À COMPLÉTER]']);
        $this->createProject('Projet du labo', ['type' => ProjectType::Lab]);

        $crawler = $client->request('GET', '/projets');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#case-studies-title');
        self::assertCount(0, $crawler->filter('a[href*="type=etudes-de-cas"]'), 'Aucun onglet vers une liste vide.');
        self::assertSelectorTextNotContains('main', 'Études de cas');
        self::assertSelectorTextContains('main', 'Projet du labo');

        $crawler = $client->request('GET', '/');
        self::assertSelectorTextNotContains('main', 'Études de cas');
        self::assertCount(0, $crawler->filter('a[href^="/projets/etude-"]'));

        $client->request('GET', '/sitemap.xml');
        self::assertStringNotContainsString('/projets/etude-', (string) $client->getResponse()->getContent());
        foreach (['etude-en-brouillon', 'etude-a-rediger'] as $slug) {
            $client->request('GET', '/projets/'.$slug);
            self::assertResponseStatusCodeSame(404);
        }
    }

    /**
     * Vérifie l'ordre (études de cas, puis Labo) et le filtre par type.
     */
    public function testCaseStudiesComeFirstAndTypeFilterWorks(): void
    {
        $client = self::createClient();
        $this->createProject('Projet du labo', ['type' => ProjectType::Lab]);
        $this->createProject('Étude de cas visible');

        $crawler = $client->request('GET', '/projets');
        $html = (string) $client->getResponse()->getContent();
        self::assertLessThan(strpos($html, 'id="lab-title"'), strpos($html, 'id="case-studies-title"'));
        self::assertCount(1, $crawler->filter('img[src$="embleme-labo.webp"]'));

        $client->request('GET', '/projets?type=labo');
        self::assertSelectorTextContains('main', 'Projet du labo');
        self::assertSelectorTextNotContains('main', 'Étude de cas visible');
        self::assertSelectorExists('a[aria-current="page"][href="/projets?type=labo"]');

        // Valeur inconnue : ignorée, liste complète.
        $client->request('GET', '/projets?type=inconnu');
        self::assertSelectorTextContains('main', 'Projet du labo');
        self::assertSelectorTextContains('main', 'Étude de cas visible');
    }

    /**
     * Vérifie le filtre par catégorie (seules les catégories utilisées sont proposées).
     */
    public function testCategoryFilterWorks(): void
    {
        $client = self::createClient();
        $entityManager = $this->getEntityManager();
        $cold = (new ProjectCategory())->setName('Froid industriel')->setSlug('froid-industriel');
        $unused = (new ProjectCategory())->setName('Catégorie vide')->setSlug('categorie-vide');
        $entityManager->persist($cold);
        $entityManager->persist($unused);
        $entityManager->flush();

        $this->createProject('Projet froid', [], [$cold]);
        $this->createProject('Projet sans catégorie');

        $client->request('GET', '/projets');
        self::assertSelectorExists('a[href="/projets?categorie=froid-industriel"]');
        self::assertSelectorNotExists('a[href="/projets?categorie=categorie-vide"]');

        $client->request('GET', '/projets?categorie=froid-industriel');
        self::assertSelectorTextContains('main', 'Projet froid');
        self::assertSelectorTextNotContains('main', 'Projet sans catégorie');
    }

    /**
     * Vérifie que le contenu Markdown est nettoyé et que la galerie n'affiche pas les images déjà insérées.
     */
    public function testContentIsSanitizedAndGallerySkipsInlineImages(): void
    {
        $client = self::createClient();
        $project = $this->createProject('Projet illustré', [
            'content' => "## Détails\n\n![Schéma](/uploads/projects/aaaa.webp)\n\n<script>alert('x')</script>\n\n[Lien](javascript:alert(1))",
        ]);
        $project->addImage((new ProjectImage())->setFilename('aaaa.webp')->setDimensions(1600, 900)->setAlt('Schéma'));
        $project->addImage((new ProjectImage())->setFilename('bbbb.webp')->setDimensions(1600, 1200)->setAlt('Photo du banc'));
        $this->getEntityManager()->flush();

        $client->request('GET', '/projets/projet-illustre');
        $html = (string) $client->getResponse()->getContent();

        self::assertSelectorTextContains('.prose h2', 'Détails');
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertSelectorCount(1, '#gallery-title + ul li');
        self::assertSelectorExists('#gallery-title + ul img[alt="Photo du banc"][width="1600"][height="1200"]');
    }

    /**
     * Vérifie qu'un projet inconnu répond 404.
     */
    public function testUnknownProjectReturns404(): void
    {
        $client = self::createClient();
        $client->request('GET', '/projets/projet-inexistant');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Crée un projet complet et publié (étude de cas par défaut), avec des valeurs modifiables.
     *
     * @param array<string, mixed>  $overrides  valeurs à remplacer (type, published, visibility, result, content)
     * @param list<ProjectCategory> $categories catégories du projet
     */
    private function createProject(string $title, array $overrides = [], array $categories = []): Project
    {
        $slug = (new SlugGenerator())->slugify($title);
        $project = (new Project())
            ->setType($overrides['type'] ?? ProjectType::CaseStudy)
            ->setTitle($title)
            ->setSlug($slug)
            ->setSummary('Résumé de '.$title.'.')
            ->setClientLabel('Un laboratoire pharmaceutique')
            ->setContext('Contexte du projet.')
            ->setProblem('Problème à résoudre.')
            ->setSolution('Solution mise en place.')
            ->setResult($overrides['result'] ?? 'Stabilité de ±0,5 °C atteinte.')
            ->setCompetences("Froid\nRégulation")
            ->setContent($overrides['content'] ?? null)
            ->setIsPublished($overrides['published'] ?? true)
            ->setVisibility($overrides['visibility'] ?? ProjectVisibility::Public);
        foreach ($categories as $category) {
            $project->addCategory($category);
        }

        $this->getEntityManager()->persist($project);
        $this->getEntityManager()->flush();

        return $project;
    }

    /**
     * Renvoie le gestionnaire Doctrine du conteneur de test.
     */
    private function getEntityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
