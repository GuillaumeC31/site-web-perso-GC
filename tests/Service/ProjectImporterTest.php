<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Project;
use App\Service\ProjectPackage\ProjectImporter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests des garde-fous de l'import d'archives ZIP : noms de fichiers dangereux refusés
 * avant toute lecture, sans rien créer.
 *
 * Liens :
 * - Couvre : ProjectImporter::import() (assertSafeArchive(), isSafeEntryName())
 * - Voir aussi : tests/Controller/Admin/ProjectAdminTest (import par le formulaire)
 */
final class ProjectImporterTest extends KernelTestCase
{
    /**
     * Vérifie qu'une archive contenant un nom de fichier dangereux est refusée et ne crée rien.
     */
    #[DataProvider('provideDangerousEntryNames')]
    public function testDangerousEntryNameIsRejected(string $name): void
    {
        self::bootKernel();
        $archive = $this->createArchive($name);

        try {
            static::getContainer()->get(ProjectImporter::class)->import($archive);
            self::fail('L\'archive aurait dû être refusée : '.$name);
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('chemin de fichier invalide', $exception->getMessage());
        } finally {
            unlink($archive);
        }

        self::assertSame(0, static::getContainer()->get(EntityManagerInterface::class)->getRepository(Project::class)->count([]));
    }

    /**
     * Noms de fichiers à refuser.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function provideDangerousEntryNames(): iterable
    {
        yield 'remontée avec /' => ['../../public/evil.php'];
        yield 'remontée avec \\' => ['..\\..\\public\\evil.php'];
        yield 'remontée \\ au milieu' => ['assets\\..\\..\\evil.php'];
        yield 'remontée en fin de chemin' => ['assets/..'];
        yield 'remontée mixte' => ['assets/..\\evil.php'];
        yield 'lettre de lecteur' => ['C:/Windows/evil.php'];
        yield 'lettre de lecteur et \\' => ['C:\\Windows\\evil.php'];
        yield 'lettre de lecteur relative' => ['c:evil.php'];
        yield 'chemin absolu /' => ['/etc/evil.php'];
        yield 'chemin absolu \\' => ['\\evil.php'];
        yield 'chemin réseau' => ['\\\\serveur\\partage\\evil.php'];
        yield 'flux NTFS' => ['assets/image.png:cache'];
    }

    /**
     * Vérifie qu'un nom ordinaire contenant des points n'est pas refusé par erreur.
     */
    public function testOrdinaryNamesWithDotsAreAccepted(): void
    {
        self::bootKernel();
        $archive = $this->createArchive('images/..fichier..notes.txt');

        try {
            $result = static::getContainer()->get(ProjectImporter::class)->import($archive);
        } finally {
            unlink($archive);
        }

        self::assertSame('Projet importé', $result->project->getTitle());
        self::assertFalse($result->project->isPublished());
    }

    /**
     * Crée une archive valide (format de ce site) à laquelle s'ajoute un fichier au nom donné.
     *
     * @return string chemin de l'archive temporaire
     */
    private function createArchive(string $extraEntryName): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zip_test_').'.zip';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('manifest.json', (string) json_encode(['format' => 'guillaume_couget_project_package', 'version' => 2]));
        $zip->addFromString('project.json', (string) json_encode(['title' => 'Projet importé']));
        $zip->addFromString($extraEntryName, 'contenu');
        $zip->close();

        // Le nom doit être stocké tel quel dans l'archive (aucune normalisation par ZipArchive).
        self::assertTrue($zip->open($path));
        self::assertNotFalse($zip->locateName($extraEntryName));
        $zip->close();

        return $path;
    }
}
