<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Project;
use App\Entity\ProjectCategory;
use App\Entity\ProjectImage;
use App\Enum\ProjectType;
use App\Service\Admin\ProjectImageStorage;
use App\Tests\Support\AdminTestTrait;
use App\Tests\Support\ConfirmationTrait;
use App\Tests\Support\VisibleTextAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Field\FileFormField;

/**
 * Tests fonctionnels de l'admin des projets (lot 4), avec un admin entièrement connecté.
 *
 * Règles vérifiées : images converties en WebP de moins de 200 Ko (couverture 1200 × 675,
 * variante 600 px), fichiers effacés au retrait et à la suppression, aperçu Markdown nettoyé
 * et protégé par jeton, aperçu d'un brouillon réservé à l'admin, export puis import d'une
 * archive (projet recréé en brouillon), import d'une archive de l'ancien site, archive
 * dangereuse refusée.
 *
 * Liens :
 * - Couvre : Admin\ProjectController, Admin\ProjectCategoryController, ProjectImageStorage,
 *   ProjectExporter, ProjectImporter, AbstractCrudController (points d'extension)
 */
final class ProjectAdminTest extends WebTestCase
{
    use ConfirmationTrait;
    use VisibleTextAssertionsTrait;
    use AdminTestTrait;

    /** @var list<string> fichiers présents dans public/uploads/projects avant le test */
    private array $existingUploads = [];

    /** @var list<string> fichiers temporaires créés par le test */
    private array $temporaryFiles = [];

    /**
     * Note les fichiers déjà présents, pour n'effacer ensuite que ceux du test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->existingUploads = $this->listUploads();
    }

    /**
     * Efface les images produites par le test et les fichiers temporaires.
     */
    protected function tearDown(): void
    {
        foreach (array_diff($this->listUploads(), $this->existingUploads) as $file) {
            @unlink($this->uploadDirectory().'/'.$file);
        }
        array_map(static fn (string $path): bool => @unlink($path), $this->temporaryFiles);
        parent::tearDown();
    }

    /**
     * Vérifie l'ajout d'un projet avec couverture et galerie : slug calculé, images WebP conformes.
     */
    public function testCreateProjectWithImages(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);

        $crawler = $client->request('GET', $this->adminUrl('/projets/nouveau'));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller="markdown-editor"]');

        $form = $crawler->selectButton('Enregistrer')->form([
            'project[title]' => 'Enceinte de stabilité ICH',
            'project[summary]' => 'Résumé de test.',
            'project[content]' => "## Détails\n\nTexte.",
        ]);
        $this->upload($form['project[coverFile]'], $this->createImage(1600, 1000));
        $newImages = $form['project[newImages]'][0];
        self::assertInstanceOf(FileFormField::class, $newImages);
        $newImages->upload($this->createImage(2400, 1800));
        $client->submit($form, [], ['HTTP_ORIGIN' => 'http://localhost']);

        $project = $this->findProject('enceinte-de-stabilite-ich');
        self::assertResponseRedirects($this->adminUrl('/projets/'.$project->getId().'/modifier'));
        self::assertSame(ProjectType::CaseStudy, $project->getType());
        self::assertFalse($project->isPublished(), 'Un nouveau projet est un brouillon.');

        $this->assertWebp((string) $project->getCoverImage(), 1200, 675);
        $this->assertWebp(ProjectImageStorage::variantFilename((string) $project->getCoverImage()), 600, 338);
        self::assertCount(1, $project->getImages());
        $image = $project->getImages()->first();
        self::assertInstanceOf(ProjectImage::class, $image);
        $this->assertWebp($image->getFilename(), 1600, 1200);
        self::assertSame([1600, 1200], [$image->getWidth(), $image->getHeight()]);

        // Le formulaire de modification signale le brouillon et les sections manquantes.
        $client->followRedirect();
        self::assertSelectorTextContains('main', 'n\'est pas visible sur le site');
        self::assertSelectorTextContains('main', 'Contexte');
    }

    /**
     * Vérifie qu'une couverture trop petite est refusée (formulaire réaffiché, code 422).
     */
    public function testTooSmallCoverIsRejected(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);

        $crawler = $client->request('GET', $this->adminUrl('/projets/nouveau'));
        $form = $crawler->selectButton('Enregistrer')->form(['project[title]' => 'Projet refusé']);
        $this->upload($form['project[coverFile]'], $this->createImage(800, 450));
        $client->submit($form, [], ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->getEntityManager()->getRepository(Project::class)->count([]));
        self::assertSame($this->existingUploads, $this->listUploads(), 'Aucun fichier ne reste sur le disque.');
    }

    /**
     * Vérifie que retirer la couverture et une image de la galerie efface les fichiers, et que
     * supprimer le projet efface le reste.
     */
    public function testRemovingImagesAndDeletingProjectCleansFiles(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $project = $this->createProjectWithImages();
        $cover = (string) $project->getCoverImage();
        $images = array_map(static fn (ProjectImage $image): string => $image->getFilename(), $project->getImages()->toArray());

        $crawler = $client->request('GET', $this->adminUrl('/projets/'.$project->getId().'/modifier'));
        $form = $crawler->selectButton('Enregistrer')->form();
        foreach (['project[removeCover]', 'project[images][0][remove]'] as $name) {
            $checkbox = $form[$name];
            self::assertInstanceOf(ChoiceFormField::class, $checkbox);
            $checkbox->tick();
        }
        $client->submit($form, [], ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseRedirects();

        self::assertFileDoesNotExist($this->uploadDirectory().'/'.$cover);
        self::assertFileDoesNotExist($this->uploadDirectory().'/'.ProjectImageStorage::variantFilename($cover));
        self::assertFileDoesNotExist($this->uploadDirectory().'/'.$images[0]);
        self::assertFileExists($this->uploadDirectory().'/'.$images[1]);

        $crawler = $client->request('GET', $this->adminUrl('/projets'));
        $client->submit($crawler->filter('form[action$="/suppression"]')->form(), [], ['HTTP_ORIGIN' => 'http://localhost']);
        $this->confirmAction($client, null, ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseRedirects($this->adminUrl('/projets'));
        self::assertFileDoesNotExist($this->uploadDirectory().'/'.$images[1]);
        self::assertSame(0, $this->getEntityManager()->getRepository(Project::class)->count([]));
    }

    /**
     * Vérifie l'aperçu Markdown : HTML nettoyé avec un jeton valide, refus sans jeton.
     */
    public function testMarkdownPreviewIsSanitizedAndRequiresToken(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);

        $crawler = $client->request('GET', $this->adminUrl('/projets/nouveau'));
        $token = (string) $crawler->filter('[data-controller="markdown-editor"]')->attr('data-markdown-editor-token-value');

        $client->request('POST', $this->adminUrl('/projets/apercu-markdown'), ['markdown' => "## Titre\n\n<img src=x onerror=alert(1)>[a](javascript:alert(1))", '_token' => $token], [], ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<h2>Titre</h2>', $html);
        self::assertStringNotContainsString('onerror', $html);
        self::assertStringNotContainsString('javascript:', $html);

        $client->request('POST', $this->adminUrl('/projets/apercu-markdown'), ['markdown' => 'Texte', '_token' => 'faux'], [], ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Vérifie que l'admin voit l'aperçu d'un brouillon (non indexé), invisible du public.
     */
    public function testDraftPreviewIsAdminOnly(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $project = $this->createProjectWithImages();

        $client->request('GET', $this->adminUrl('/projets/'.$project->getId().'/apercu'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Projet de test');
        self::assertSelectorTextContains('main', 'Aperçu de l\'administration');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');

        $client->request('GET', '/projets/'.$project->getSlug());
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Vérifie l'export puis l'import d'un projet : archive complète, projet recréé en brouillon
     * avec un slug libre, images converties et liens du contenu réécrits.
     */
    public function testExportThenImportRecreatesProjectAsDraft(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $project = $this->createProjectWithImages();
        $project->setIsPublished(true);
        $firstImage = $project->getImages()->first();
        self::assertInstanceOf(ProjectImage::class, $firstImage);
        $project->setContent('![Schéma](/uploads/projects/'.$firstImage->getFilename().')');
        $this->getEntityManager()->flush();

        $client->request('GET', $this->adminUrl('/projets/'.$project->getId().'/export'));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/zip');
        // Contenu capturé par le navigateur de test (le fichier temporaire est effacé après envoi).
        $archive = $this->temporaryPath('.zip');
        file_put_contents($archive, $client->getInternalResponse()->getContent());

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($archive));
        self::assertNotFalse($zip->locateName('manifest.json'));
        self::assertNotFalse($zip->locateName('images/couverture.webp'));
        self::assertNotFalse($zip->locateName('images/galerie-02.webp'));
        $zip->close();

        $this->importArchive($client, $archive);
        $imported = $this->findProject('projet-de-test-2');
        self::assertResponseRedirects($this->adminUrl('/projets/'.$imported->getId().'/modifier'));
        self::assertFalse($imported->isPublished(), 'Un projet importé est toujours un brouillon.');
        $category = $imported->getCategories()->first();
        self::assertInstanceOf(ProjectCategory::class, $category);
        self::assertSame('Froid industriel', $category->getName());
        self::assertCount(2, $imported->getImages());
        self::assertNotNull($imported->getCoverImage());
        self::assertNotSame($project->getCoverImage(), $imported->getCoverImage());
        $importedFirst = $imported->getImages()->first();
        self::assertInstanceOf(ProjectImage::class, $importedFirst);
        self::assertStringContainsString($importedFirst->getFilename(), (string) $imported->getContent());
    }

    /**
     * Vérifie l'import d'une archive de l'ancien site : projet du Labo en brouillon, HTML conservé,
     * image PNG convertie et lien réécrit, SVG écarté avec un message.
     */
    public function testImportLegacyArchive(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);

        $archive = $this->temporaryPath('.zip');
        $zip = new \ZipArchive();
        $zip->open($archive, \ZipArchive::CREATE);
        $zip->addFromString('manifest.json', (string) json_encode([
            'format' => 'guillaume_c_project_package',
            'version' => 1,
            'assets' => [
                ['original' => '/uploads/projects/Schema-123.png', 'archive' => 'assets/uploads/Schema-123.png', 'target' => 'uploads/projects'],
                ['original' => '/images/logo.svg', 'archive' => 'assets/images/logo.svg', 'target' => 'public/images'],
            ],
        ]));
        $zip->addFromString('project.json', (string) json_encode([
            'format_version' => 1,
            'title' => 'Simulateur d\'aube',
            'slug' => 'simulateur-aube',
            'excerpt' => 'Un réveil lumineux à base d\'ESP32.',
            'content' => '<h2>Montage</h2><p>Texte <script>alert(1)</script></p><img src="/uploads/projects/Schema-123.png">',
            'is_published' => true,
            'repository_url' => 'https://github.com/exemple/aube',
            'cover_archive' => null,
        ]));
        $zip->addFromString('categories.json', (string) json_encode([['name' => 'IoT', 'slug' => 'iot']]));
        $zip->addFile($this->createImage(1000, 800), 'assets/uploads/Schema-123.png');
        $zip->addFromString('assets/images/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $zip->close();

        $this->importArchive($client, $archive);
        $project = $this->findProject('simulateur-aube');
        self::assertSame(ProjectType::Lab, $project->getType());
        self::assertFalse($project->isPublished());
        self::assertSame('Un réveil lumineux à base d\'ESP32.', $project->getSummary());
        $category = $project->getCategories()->first();
        self::assertInstanceOf(ProjectCategory::class, $category);
        self::assertSame('iot', $category->getSlug());
        self::assertCount(1, $project->getImages());
        $image = $project->getImages()->first();
        self::assertInstanceOf(ProjectImage::class, $image);
        self::assertStringContainsString('/uploads/projects/'.$image->getFilename(), (string) $project->getContent());
        self::assertStringNotContainsString('Schema-123.png', (string) $project->getContent());

        $client->followRedirect();
        self::assertSelectorTextContains('main', 'logo.svg');

        // Le HTML de l'ancien site est nettoyé à l'affichage.
        $client->request('GET', $this->adminUrl('/projets/'.$project->getId().'/apercu'));
        self::assertStringNotContainsString('<script>alert', (string) $client->getResponse()->getContent());
        self::assertSelectorTextContains('.prose h2', 'Montage');
    }

    /**
     * Vérifie qu'une archive contenant un chemin dangereux (« ../ ») est refusée sans rien créer.
     */
    public function testArchiveWithPathTraversalIsRejected(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);

        $archive = $this->temporaryPath('.zip');
        $zip = new \ZipArchive();
        $zip->open($archive, \ZipArchive::CREATE);
        $zip->addFromString('manifest.json', (string) json_encode(['format' => 'guillaume_couget_project_package', 'version' => 2]));
        $zip->addFromString('project.json', (string) json_encode(['title' => 'Piège']));
        $zip->addFromString('../../public/evil.php', '<?php echo 1;');
        $zip->close();

        $this->importArchive($client, $archive);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'chemin de fichier invalide');
        self::assertSame(0, $this->getEntityManager()->getRepository(Project::class)->count([]));
    }

    /**
     * Vérifie l'ajout d'une catégorie avec slug calculé à partir du nom.
     */
    public function testCreateCategoryComputesSlug(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);

        $crawler = $client->request('GET', $this->adminUrl('/categories-de-projets/nouveau'));
        $client->submit($crawler->selectButton('Enregistrer')->form(['project_category[name]' => 'Électronique & IoT']), [], ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseRedirects($this->adminUrl('/categories-de-projets'));
        self::assertNotNull($this->getEntityManager()->getRepository(ProjectCategory::class)->findOneBy(['slug' => 'electronique-iot']));
    }

    /**
     * Crée un projet en brouillon avec une couverture, deux images et une catégorie, par le vrai stockage.
     */
    private function createProjectWithImages(): Project
    {
        $storage = static::getContainer()->get(ProjectImageStorage::class);
        $entityManager = $this->getEntityManager();
        $category = (new ProjectCategory())->setName('Froid industriel')->setSlug('froid-industriel');
        $entityManager->persist($category);

        $project = (new Project())
            ->setTitle('Projet de test')
            ->setSlug('projet-de-test')
            ->setSummary('Résumé.')
            ->setCoverImage($storage->storeCover(new \Symfony\Component\HttpFoundation\File\File($this->createImage(1400, 900))))
            ->addCategory($category);
        foreach ([[1200, 900], [900, 1200]] as [$width, $height]) {
            $stored = $storage->storeGalleryImage(new \Symfony\Component\HttpFoundation\File\File($this->createImage($width, $height)));
            $project->addImage((new ProjectImage())->setFilename($stored['filename'])->setDimensions($stored['width'], $stored['height']));
        }
        $entityManager->persist($project);
        $entityManager->flush();

        return $project;
    }

    /**
     * Envoie une archive par le formulaire d'import.
     */
    private function importArchive(KernelBrowser $client, string $archive): void
    {
        $crawler = $client->request('GET', $this->adminUrl('/projets/import'));
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Importer')->form();
        $this->upload($form['project_import[archive]'], $archive);
        $client->submit($form, [], ['HTTP_ORIGIN' => 'http://localhost']);
    }

    /**
     * Joint un fichier à un champ d'envoi du formulaire.
     *
     * @param mixed $field champ du formulaire (FileFormField attendu)
     */
    private function upload(mixed $field, string $path): void
    {
        self::assertInstanceOf(FileFormField::class, $field);
        $field->upload($path);
    }

    /**
     * Vérifie qu'un fichier produit est un WebP aux dimensions attendues, de moins de 200 Ko.
     */
    private function assertWebp(string $filename, int $width, int $height): void
    {
        $path = $this->uploadDirectory().'/'.$filename;
        self::assertFileExists($path);
        $info = getimagesize($path);
        self::assertNotFalse($info);
        self::assertSame('image/webp', $info['mime']);
        self::assertSame([$width, $height], [$info[0], $info[1]]);
        self::assertLessThanOrEqual(200 * 1024, filesize($path));
    }

    /**
     * Recharge un projet par son slug (données à jour).
     */
    private function findProject(string $slug): Project
    {
        $this->getEntityManager()->clear();
        $project = $this->getEntityManager()->getRepository(Project::class)->findOneBy(['slug' => $slug]);
        self::assertNotNull($project, 'Projet introuvable : '.$slug);

        return $project;
    }

    /**
     * Crée une image PNG (dégradé, pour une compression réaliste).
     *
     * @return string chemin du fichier temporaire
     */
    private function createImage(int $width, int $height): string
    {
        $path = $this->temporaryPath('.png');
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        for ($y = 0; $y < $height; ++$y) {
            $color = imagecolorallocate($image, (int) (255 * $y / $height), 110, 150);
            self::assertNotFalse($color);
            imageline($image, 0, $y, $width - 1, $y, $color);
        }
        imagepng($image, $path);

        return $path;
    }

    /**
     * Réserve un chemin de fichier temporaire (effacé en fin de test).
     */
    private function temporaryPath(string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'projet_test_').$extension;
        $this->temporaryFiles[] = $path;

        return $path;
    }

    /**
     * Renvoie le dossier des images des projets.
     */
    private function uploadDirectory(): string
    {
        return static::getContainer()->getParameter('kernel.project_dir').'/public/'.ProjectImageStorage::PUBLIC_DIRECTORY;
    }

    /**
     * Liste les fichiers du dossier des images des projets.
     *
     * @return list<string>
     */
    private function listUploads(): array
    {
        $directory = \dirname(__DIR__, 3).'/public/'.ProjectImageStorage::PUBLIC_DIRECTORY;

        return is_dir($directory) ? array_values(array_diff(scandir($directory) ?: [], ['.', '..'])) : [];
    }
}
