<?php

declare(strict_types=1);

namespace App\Service\ProjectPackage;

use App\Entity\Project;
use App\Entity\ProjectCategory;
use App\Entity\ProjectImage;
use App\Enum\ProjectType;
use App\Enum\ProjectVisibility;
use App\Repository\ProjectCategoryRepository;
use App\Repository\ProjectRepository;
use App\Service\Admin\PositionManager;
use App\Service\Admin\ProjectImageStorage;
use App\Service\Content\SlugGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\File;

/**
 * Importe un projet depuis une archive ZIP : format de ce site (version 2) ou de l'ancien
 * site guillaume_c (version 1).
 *
 * Garde-fous :
 * - archive contrôlée avant lecture : taille, nombre de fichiers, taille décompressée,
 *   chemins (aucun « .. » ni chemin absolu) ;
 * - images converties en WebP de moins de 200 Ko (ProjectImageStorage) ; SVG et autres
 *   formats écartés, avec un message ;
 * - le projet est TOUJOURS créé en brouillon, avec un slug libre : rien n'est publié ni
 *   écrasé sans relecture dans l'admin ;
 * - le contenu (Markdown ou HTML de l'ancien site) est nettoyé à l'affichage (MarkdownRenderer).
 *
 * Liens :
 * - Utilisé par : Admin\ProjectController::import()
 * - Utilise : ProjectImageStorage, SlugGenerator, PositionManager, ProjectPackageFormat
 * - Voir aussi : ProjectExporter (format version 2)
 */
final readonly class ProjectImporter
{
    /**
     * Reçoit les dépendances.
     *
     * @param EntityManagerInterface    $entityManager      gestionnaire Doctrine
     * @param ProjectRepository         $projectRepository  slugs déjà pris, position
     * @param ProjectCategoryRepository $categoryRepository catégories existantes
     * @param ProjectImageStorage       $imageStorage       conversion des images
     * @param SlugGenerator             $slugGenerator      slugs libres
     * @param PositionManager           $positionManager    place le projet en fin de liste
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ProjectRepository $projectRepository,
        private ProjectCategoryRepository $categoryRepository,
        private ProjectImageStorage $imageStorage,
        private SlugGenerator $slugGenerator,
        private PositionManager $positionManager,
    ) {
    }

    /**
     * Importe l'archive et renvoie le projet créé (en brouillon).
     *
     * Liens :
     * - Appelée par : Admin\ProjectController::import()
     *
     * @param string $zipPath chemin de l'archive envoyée
     *
     * @throws \InvalidArgumentException si l'archive est invalide (message lisible pour l'admin)
     */
    public function import(string $zipPath): ProjectImportResult
    {
        if (!is_file($zipPath) || filesize($zipPath) > ProjectPackageFormat::MAX_ARCHIVE_BYTES) {
            throw new \InvalidArgumentException('Archive absente ou trop volumineuse (50 Mo au maximum).');
        }

        $zip = new \ZipArchive();
        if (true !== $zip->open($zipPath, \ZipArchive::RDONLY)) {
            throw new \InvalidArgumentException('Archive ZIP invalide ou illisible.');
        }

        $storedFiles = [];
        try {
            $this->assertSafeArchive($zip);
            $manifest = $this->readJson($zip, ProjectPackageFormat::MANIFEST_FILE);
            $data = $this->readJson($zip, ProjectPackageFormat::PROJECT_FILE);

            $result = match ($manifest['format'] ?? null) {
                ProjectPackageFormat::FORMAT_ID => $this->importCurrent($zip, $data, $storedFiles),
                ProjectPackageFormat::LEGACY_FORMAT_ID => $this->importLegacy($zip, $manifest, $data, $storedFiles),
                default => throw new \InvalidArgumentException('Format d\'archive non reconnu : exportez le projet depuis l\'admin.'),
            };

            $this->positionManager->placeLast($result->project, $this->projectRepository);
            $this->entityManager->persist($result->project);
            $this->entityManager->flush();

            return $result;
        } catch (\Throwable $exception) {
            // Rien ne doit rester sur le disque si l'import échoue.
            array_walk($storedFiles, $this->imageStorage->remove(...));
            throw $exception instanceof \InvalidArgumentException || $exception instanceof \JsonException ? new \InvalidArgumentException($exception->getMessage(), 0, $exception) : $exception;
        } finally {
            $zip->close();
        }
    }

    /**
     * Crée le projet à partir d'une archive de ce site (version 2).
     *
     * @param array<string, mixed> $data        contenu de project.json
     * @param list<string>         $storedFiles fichiers produits (pour nettoyage en cas d'erreur)
     */
    private function importCurrent(\ZipArchive $zip, array $data, array &$storedFiles): ProjectImportResult
    {
        $warnings = [];
        $project = (new Project())
            ->setType(ProjectType::tryFrom($this->string($data['type'] ?? null) ?? '') ?? ProjectType::CaseStudy)
            ->setSummary($this->string($data['summary'] ?? null))
            ->setClientLabel($this->string($data['client_label'] ?? null))
            ->setContext($this->string($data['context'] ?? null))
            ->setProblem($this->string($data['problem'] ?? null))
            ->setSolution($this->string($data['solution'] ?? null))
            ->setResult($this->string($data['result'] ?? null))
            ->setCompetences($this->string($data['competences'] ?? null))
            ->setTechnologies($this->string($data['technologies'] ?? null))
            ->setRepositoryUrl($this->httpsUrl($data['repository_url'] ?? null))
            ->setDemoUrl($this->httpsUrl($data['demo_url'] ?? null))
            ->setVisibility(ProjectVisibility::tryFrom($this->string($data['visibility'] ?? null) ?? '') ?? ProjectVisibility::Public)
            ->setIsFeatured(true === ($data['is_featured'] ?? false));
        $this->setIdentity($project, $data, $warnings);

        $date = $this->string($data['project_date'] ?? null);
        if (null !== $date && false !== ($parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date))) {
            $project->setProjectDate($parsed);
        }

        $this->attachCategories($project, \is_array($data['categories'] ?? null) ? $data['categories'] : []);

        $cover = \is_array($data['cover'] ?? null) ? $data['cover'] : null;
        if (null !== $cover && null !== ($archivePath = $this->string($cover['archive'] ?? null))) {
            $project->setCoverImage($this->storeImage($zip, $archivePath, true, $storedFiles, $warnings)['filename'] ?? null);
            $project->setCoverAlt($this->string($cover['alt'] ?? null));
        }

        $content = (string) $this->string($data['content'] ?? null);
        $images = 0;
        foreach (\is_array($data['images'] ?? null) ? $data['images'] : [] as $row) {
            if (!\is_array($row) || null === ($archivePath = $this->string($row['archive'] ?? null))) {
                continue;
            }
            $stored = $this->storeImage($zip, $archivePath, false, $storedFiles, $warnings);
            if (null === $stored) {
                continue;
            }
            $project->addImage((new ProjectImage())
                ->setFilename($stored['filename'])
                ->setDimensions($stored['width'], $stored['height'])
                ->setAlt($this->string($row['alt'] ?? null)));
            ++$images;

            // Les images insérées dans le contenu pointent vers l'ancien nom de fichier.
            $original = basename((string) $this->string($row['filename'] ?? null));
            if ('' !== $original) {
                $content = str_replace(
                    [ProjectImageStorage::variantFilename($original), $original],
                    [ProjectImageStorage::variantFilename($stored['filename']), $stored['filename']],
                    $content,
                );
            }
        }
        $project->setContent('' === $content ? null : $content);

        return new ProjectImportResult($project, false, $images, $warnings);
    }

    /**
     * Crée le projet à partir d'une archive de l'ancien site (version 1) : projet du Labo,
     * contenu HTML conservé (nettoyé à l'affichage), images converties en WebP.
     *
     * @param array<string, mixed> $manifest    contenu de manifest.json
     * @param array<string, mixed> $data        contenu de project.json
     * @param list<string>         $storedFiles fichiers produits (pour nettoyage en cas d'erreur)
     */
    private function importLegacy(\ZipArchive $zip, array $manifest, array $data, array &$storedFiles): ProjectImportResult
    {
        $warnings = ['Archive de l\'ancien site : vérifiez le type, le résumé et la mise en forme du contenu avant de publier.'];
        $project = (new Project())
            ->setType(ProjectType::Lab)
            ->setSummary($this->string($data['excerpt'] ?? null))
            ->setRepositoryUrl($this->httpsUrl($data['repository_url'] ?? null))
            ->setDemoUrl($this->httpsUrl($data['demo_url'] ?? null));
        $this->setIdentity($project, $data, $warnings);

        if (false !== $zip->locateName(ProjectPackageFormat::LEGACY_CATEGORIES_FILE)) {
            $this->attachCategories($project, $this->readJson($zip, ProjectPackageFormat::LEGACY_CATEGORIES_FILE));
        }

        $coverArchive = $this->string($data['cover_archive'] ?? null);
        if (null !== $coverArchive) {
            $project->setCoverImage($this->storeImage($zip, $coverArchive, true, $storedFiles, $warnings)['filename'] ?? null);
        }

        // Images du contenu : converties, ajoutées à la galerie (fichiers suivis) et liens réécrits.
        $content = (string) $this->string($data['content'] ?? null);
        $images = 0;
        foreach (\is_array($manifest['assets'] ?? null) ? $manifest['assets'] : [] as $asset) {
            $archivePath = \is_array($asset) ? $this->string($asset['archive'] ?? null) : null;
            $original = \is_array($asset) ? $this->string($asset['original'] ?? null) : null;
            if (null === $archivePath || null === $original) {
                continue;
            }
            $stored = $this->storeImage($zip, $archivePath, false, $storedFiles, $warnings);
            if (null === $stored) {
                continue;
            }
            $project->addImage((new ProjectImage())->setFilename($stored['filename'])->setDimensions($stored['width'], $stored['height']));
            $content = str_replace($original, '/'.ProjectImageStorage::PUBLIC_DIRECTORY.'/'.$stored['filename'], $content);
            ++$images;
        }
        $project->setContent('' === $content ? null : $content);

        return new ProjectImportResult($project, true, $images, $warnings);
    }

    /**
     * Définit le titre et un slug libre ; le projet est toujours importé en brouillon.
     *
     * @param array<string, mixed> $data     contenu de project.json
     * @param list<string>         $warnings messages pour l'admin
     *
     * @throws \InvalidArgumentException si le titre manque
     */
    private function setIdentity(Project $project, array $data, array &$warnings): void
    {
        $title = $this->string($data['title'] ?? null);
        if (null === $title) {
            throw new \InvalidArgumentException('Le projet de l\'archive n\'a pas de titre.');
        }

        $wanted = $this->string($data['slug'] ?? null) ?? $title;
        $slug = $this->slugGenerator->unique($wanted, fn (string $slug): bool => $this->projectRepository->isSlugTaken($slug));
        if ($slug !== $this->slugGenerator->slugify($wanted)) {
            $warnings[] = \sprintf('Un projet utilise déjà l\'adresse « %s » : le projet importé reçoit « %s ».', $this->slugGenerator->slugify($wanted), $slug);
        }

        $project->setTitle(mb_substr($title, 0, 160))->setSlug($slug)->setIsPublished(false);
    }

    /**
     * Rattache les catégories de l'archive (créées si elles n'existent pas).
     *
     * @param array<mixed> $rows lignes { name, slug }
     */
    private function attachCategories(Project $project, array $rows): void
    {
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $name = $this->string($row['name'] ?? null);
            $slug = $this->slugGenerator->slugify($this->string($row['slug'] ?? null) ?? (string) $name);
            if (null === $name && 'projet' === $slug) {
                continue;
            }

            $category = $this->categoryRepository->findOneBySlug($slug);
            if (null === $category) {
                $category = (new ProjectCategory())->setName(mb_substr($name ?? $slug, 0, 80))->setSlug($slug);
                $this->positionManager->placeLast($category, $this->categoryRepository);
                $this->entityManager->persist($category);
                // Visible des recherches suivantes (deux projets de la même archive, par exemple).
                $this->entityManager->flush();
            }
            $project->addCategory($category);
        }
    }

    /**
     * Extrait une image de l'archive et la convertit (couverture ou galerie).
     *
     * @param list<string> $storedFiles fichiers produits (pour nettoyage en cas d'erreur)
     * @param list<string> $warnings    messages pour l'admin (image écartée)
     *
     * @return array{filename: string, width: int, height: int}|null image produite, ou null si écartée
     */
    private function storeImage(\ZipArchive $zip, string $archivePath, bool $cover, array &$storedFiles, array &$warnings): ?array
    {
        $extension = strtolower(pathinfo($archivePath, \PATHINFO_EXTENSION));
        if (!\in_array($extension, ProjectPackageFormat::IMAGE_EXTENSIONS, true)) {
            $warnings[] = \sprintf('Image « %s » écartée (format %s non accepté) : envoyez-la en JPEG, PNG ou WebP.', basename($archivePath), strtoupper($extension));

            return null;
        }

        $stat = $zip->statName($archivePath);
        if (false === $stat || $stat['size'] > ProjectPackageFormat::MAX_IMAGE_BYTES) {
            $warnings[] = \sprintf('Image « %s » absente de l\'archive ou trop lourde.', basename($archivePath));

            return null;
        }

        $temporary = tempnam(sys_get_temp_dir(), 'img_');
        if (false === $temporary) {
            throw new \RuntimeException('Impossible de créer un fichier temporaire.');
        }
        try {
            file_put_contents($temporary, (string) $zip->getFromName($archivePath));
            $file = new File($temporary);
            if ($cover) {
                $filename = $this->imageStorage->storeCover($file);
                $storedFiles[] = $filename;

                return ['filename' => $filename, 'width' => ProjectImageStorage::COVER_WIDTH, 'height' => ProjectImageStorage::COVER_HEIGHT];
            }

            $stored = $this->imageStorage->storeGalleryImage($file);
            $storedFiles[] = $stored['filename'];

            return $stored;
        } catch (\InvalidArgumentException) {
            $warnings[] = \sprintf('Image « %s » illisible ou trop lourde : écartée.', basename($archivePath));

            return null;
        } finally {
            @unlink($temporary);
        }
    }

    /**
     * Vérifie l'archive avant toute lecture : nombre de fichiers, taille décompressée, chemins.
     *
     * @throws \InvalidArgumentException si l'archive est refusée
     */
    private function assertSafeArchive(\ZipArchive $zip): void
    {
        if ($zip->numFiles > ProjectPackageFormat::MAX_ENTRIES) {
            throw new \InvalidArgumentException('Archive refusée : trop de fichiers.');
        }

        $total = 0;
        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $stat = $zip->statIndex($index);
            if (false === $stat) {
                throw new \InvalidArgumentException('Archive refusée : fichier illisible.');
            }
            if (!self::isSafeEntryName($stat['name'])) {
                throw new \InvalidArgumentException('Archive refusée : chemin de fichier invalide.');
            }
            $total += $stat['size'];
        }

        if ($total > ProjectPackageFormat::MAX_UNCOMPRESSED_BYTES) {
            throw new \InvalidArgumentException('Archive refusée : contenu décompressé trop volumineux.');
        }
    }

    /**
     * Indique si le nom d'un fichier de l'archive est un chemin relatif sûr.
     *
     * Refusés : nom vide ou avec caractère nul, chemin absolu (« / » ou « \ » en tête,
     * chemin réseau « \\serveur »), lettre de lecteur (« C: »), deux-points ailleurs, et
     * tout segment « .. », qu'il soit séparé par « / » ou par « \ » (« ..\ », « assets/.. »).
     *
     * Liens :
     * - Appelée par : assertSafeArchive()
     *
     * @param string $name nom du fichier tel qu'il est stocké dans l'archive
     */
    private static function isSafeEntryName(string $name): bool
    {
        if ('' === $name || str_contains($name, "\0")) {
            return false;
        }
        // Lettre de lecteur Windows (« C:\… », « c:x ») et tout autre deux-points (flux NTFS…)
        if (1 === preg_match('/^[A-Za-z]:/', $name) || str_contains($name, ':')) {
            return false;
        }
        // Chemin absolu ou réseau, avec l'un ou l'autre séparateur
        if (str_starts_with($name, '/') || str_starts_with($name, '\\')) {
            return false;
        }

        // Aucun segment « .. », quel que soit le séparateur
        foreach (preg_split('#[/\\\\]+#', $name) ?: [] as $segment) {
            if ('..' === trim($segment)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Lit un fichier JSON de l'archive.
     *
     * @return array<mixed>
     *
     * @throws \InvalidArgumentException si le fichier manque, est trop gros ou n'est pas un objet JSON
     */
    private function readJson(\ZipArchive $zip, string $name): array
    {
        $stat = $zip->statName($name);
        if (false === $stat) {
            throw new \InvalidArgumentException(\sprintf('Archive incomplète : %s manquant.', $name));
        }
        if ($stat['size'] > ProjectPackageFormat::MAX_JSON_BYTES) {
            throw new \InvalidArgumentException(\sprintf('Archive refusée : %s trop volumineux.', $name));
        }

        $data = json_decode((string) $zip->getFromName($name), true, 32, \JSON_THROW_ON_ERROR);
        if (!\is_array($data)) {
            throw new \InvalidArgumentException(\sprintf('Archive invalide : %s mal formé.', $name));
        }

        return $data;
    }

    /**
     * Renvoie un texte nettoyé, ou null s'il est vide ou n'est pas un texte.
     */
    private function string(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $value = trim($value);

        return '' === $value ? null : $value;
    }

    /**
     * Renvoie un lien https valide, ou null (les autres liens sont ignorés).
     */
    private function httpsUrl(mixed $value): ?string
    {
        $url = $this->string($value);

        return null !== $url && str_starts_with($url, 'https://') && false !== filter_var($url, \FILTER_VALIDATE_URL) && \strlen($url) <= 255 ? $url : null;
    }
}
