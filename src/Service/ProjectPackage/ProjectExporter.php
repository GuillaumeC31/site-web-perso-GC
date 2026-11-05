<?php

declare(strict_types=1);

namespace App\Service\ProjectPackage;

use App\Entity\Project;
use App\Service\Admin\ProjectImageStorage;

/**
 * Exporte un projet dans une archive ZIP (format version 2), pour le sauvegarder ou
 * le recopier sur un autre environnement (local → production).
 *
 * Liens :
 * - Utilisé par : Admin\ProjectController::export()
 * - Utilise : ProjectImageStorage (fichiers WebP), ProjectPackageFormat
 * - Voir aussi : ProjectImporter (lecture de l'archive)
 */
final readonly class ProjectExporter
{
    /**
     * Reçoit le stockage des images.
     *
     * @param ProjectImageStorage $imageStorage chemins des images du projet
     */
    public function __construct(private ProjectImageStorage $imageStorage)
    {
    }

    /**
     * Crée l'archive dans un fichier temporaire et renvoie son chemin.
     *
     * Liens :
     * - Appelée par : Admin\ProjectController::export()
     *
     * @param Project $project projet à exporter
     *
     * @return string chemin du fichier ZIP temporaire (à supprimer après envoi)
     *
     * @throws \RuntimeException si l'archive ne peut pas être créée
     */
    public function createArchive(Project $project): string
    {
        $path = tempnam(sys_get_temp_dir(), 'projet_');
        if (false === $path) {
            throw new \RuntimeException('Impossible de créer un fichier temporaire pour l\'export.');
        }

        $zip = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException('Impossible de créer l\'archive ZIP.');
        }

        $cover = null;
        if (null !== $project->getCoverImage() && null !== ($coverPath = $this->imageStorage->getAbsolutePath($project->getCoverImage()))) {
            $zip->addFile($coverPath, 'images/couverture.webp');
            $cover = ['archive' => 'images/couverture.webp', 'alt' => $project->getCoverAlt()];
        }

        $images = [];
        foreach ($project->getImages() as $index => $image) {
            $imagePath = $this->imageStorage->getAbsolutePath($image->getFilename());
            if (null === $imagePath) {
                continue;
            }
            $archivePath = \sprintf('images/galerie-%02d.webp', $index + 1);
            $zip->addFile($imagePath, $archivePath);
            $images[] = ['archive' => $archivePath, 'alt' => $image->getAlt(), 'filename' => $image->getFilename()];
        }

        $payload = [
            'type' => $project->getType()->value,
            'title' => $project->getTitle(),
            'slug' => $project->getSlug(),
            'summary' => $project->getSummary(),
            'client_label' => $project->getClientLabel(),
            'context' => $project->getContext(),
            'problem' => $project->getProblem(),
            'solution' => $project->getSolution(),
            'result' => $project->getResult(),
            'competences' => $project->getCompetences(),
            'content' => $project->getContent(),
            'technologies' => $project->getTechnologies(),
            'project_date' => $project->getProjectDate()?->format('Y-m-d'),
            'repository_url' => $project->getRepositoryUrl(),
            'demo_url' => $project->getDemoUrl(),
            'visibility' => $project->getVisibility()->value,
            'is_featured' => $project->isFeatured(),
            'categories' => array_values(array_map(
                static fn ($category): array => ['name' => $category->getName(), 'slug' => $category->getSlug()],
                $project->getCategories()->toArray(),
            )),
            'cover' => $cover,
            'images' => $images,
        ];
        $manifest = [
            'format' => ProjectPackageFormat::FORMAT_ID,
            'version' => ProjectPackageFormat::VERSION,
            'exported_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ];

        $json = \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR;
        $zip->addFromString(ProjectPackageFormat::MANIFEST_FILE, json_encode($manifest, $json));
        $zip->addFromString(ProjectPackageFormat::PROJECT_FILE, json_encode($payload, $json));
        $zip->close();

        return $path;
    }

    /**
     * Renvoie le nom du fichier proposé au téléchargement (ex. « projet-enceinte-ich-20261022.zip »).
     *
     * Liens :
     * - Appelée par : Admin\ProjectController::export()
     */
    public function getDownloadFilename(Project $project): string
    {
        return \sprintf('projet-%s-%s.zip', $project->getSlug() ?: 'sans-titre', (new \DateTimeImmutable())->format('Ymd'));
    }
}
