<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Service\Image\WebpImageProcessor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;

/**
 * Enregistre les images des projets (couverture et galerie) en WebP de moins de 200 Ko.
 *
 * - Couverture : 1200 × 675 (16:9, recadrage centré) + variante 600 × 338 ;
 * - Galerie : réduite pour tenir dans 1600 × 1200, sans recadrage + variante de 600 px de large.
 *
 * La variante porte le même nom suivi de « -600 » (voir variantFilename()) : elle sert
 * à l'attribut srcset (chargement d'une image plus légère sur mobile).
 *
 * Règles (CDC § 10) : type réel vérifié (contrainte Image du formulaire), fichier d'origine
 * jamais conservé, nom aléatoire, dossier sans exécution de script (public/uploads/.htaccess).
 *
 * Liens :
 * - Utilisé par : Admin\ProjectController (envois), ProjectImporter (archives ZIP)
 * - Utilise : WebpImageProcessor
 * - Voir aussi : App\Twig\ProjectExtension (chemins et srcset dans les templates)
 */
final readonly class ProjectImageStorage
{
    /** Chemin public des images des projets, relatif à public/. */
    public const PUBLIC_DIRECTORY = 'uploads/projects';

    /** Dimensions de la couverture (16:9), en pixels. */
    public const COVER_WIDTH = 1200;
    public const COVER_HEIGHT = 675;

    /** Cadre maximal des images de la galerie, en pixels. */
    public const GALLERY_MAX_WIDTH = 1600;
    public const GALLERY_MAX_HEIGHT = 1200;

    /** Largeur de la variante légère (srcset), en pixels. */
    public const VARIANT_WIDTH = 600;

    /**
     * Reçoit les dépendances.
     *
     * @param string             $publicDirectory chemin absolu de public/
     * @param WebpImageProcessor $imageProcessor  conversion en WebP
     * @param Filesystem         $filesystem      opérations sur les fichiers
     */
    public function __construct(
        #[Autowire('%kernel.project_dir%/public')]
        private string $publicDirectory,
        private WebpImageProcessor $imageProcessor,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * Convertit une image en couverture (et sa variante) et renvoie le nom du fichier.
     *
     * Liens :
     * - Appelée par : Admin\ProjectController::handleValidForm(), ProjectImporter
     *
     * @param File $file image (JPEG, PNG, WebP ou GIF)
     *
     * @return string nom du fichier (ex. « 3f9c…e1.webp »)
     *
     * @throws \InvalidArgumentException si l'image est illisible ou ne tient pas sous 200 Ko
     */
    public function storeCover(File $file): string
    {
        $source = $this->imageProcessor->load($file->getPathname());
        $large = $this->imageProcessor->cover($source, self::COVER_WIDTH, self::COVER_HEIGHT);
        $small = $this->imageProcessor->cover($source, self::VARIANT_WIDTH, (int) round(self::VARIANT_WIDTH * self::COVER_HEIGHT / self::COVER_WIDTH));

        return $this->save($large, $small);
    }

    /**
     * Convertit une image de galerie (et sa variante) et renvoie son nom et ses dimensions.
     *
     * Liens :
     * - Appelée par : Admin\ProjectController::handleValidForm(), ProjectImporter
     *
     * @param File $file image (JPEG, PNG, WebP ou GIF)
     *
     * @return array{filename: string, width: int, height: int} fichier produit et dimensions réelles
     *
     * @throws \InvalidArgumentException si l'image est illisible ou ne tient pas sous 200 Ko
     */
    public function storeGalleryImage(File $file): array
    {
        $source = $this->imageProcessor->load($file->getPathname());
        $large = $this->imageProcessor->fit($source, self::GALLERY_MAX_WIDTH, self::GALLERY_MAX_HEIGHT);
        $small = $this->imageProcessor->fit($source, self::VARIANT_WIDTH, self::GALLERY_MAX_HEIGHT);

        return [
            'filename' => $this->save($large, $small),
            'width' => imagesx($large),
            'height' => imagesy($large),
        ];
    }

    /**
     * Supprime une image et sa variante (sans erreur si elles n'existent plus).
     *
     * Liens :
     * - Appelée par : Admin\ProjectController (remplacement, retrait, suppression du projet)
     *
     * @param string|null $filename nom du fichier, ou null (rien à faire)
     */
    public function remove(?string $filename): void
    {
        // basename() empêche toute sortie du dossier (ex. « ../ » dans un nom corrompu).
        if (null === $filename || '' === $filename || basename($filename) !== $filename) {
            return;
        }

        $this->filesystem->remove([
            $this->getDirectory().'/'.$filename,
            $this->getDirectory().'/'.self::variantFilename($filename),
        ]);
    }

    /**
     * Renvoie le chemin absolu d'une image (export des projets).
     *
     * Liens :
     * - Appelée par : ProjectExporter::createArchive()
     *
     * @param string $filename nom du fichier
     *
     * @return string|null chemin absolu, ou null si le fichier n'existe pas
     */
    public function getAbsolutePath(string $filename): ?string
    {
        if ('' === $filename || basename($filename) !== $filename) {
            return null;
        }
        $path = $this->getDirectory().'/'.$filename;

        return is_file($path) ? $path : null;
    }

    /**
     * Renvoie le nom de la variante légère d'une image.
     *
     * Exemple : variantFilename('abc.webp') renvoie « abc-600.webp ».
     *
     * Liens :
     * - Appelée par : remove(), App\Twig\ProjectExtension
     */
    public static function variantFilename(string $filename): string
    {
        return pathinfo($filename, \PATHINFO_FILENAME).'-'.self::VARIANT_WIDTH.'.webp';
    }

    /**
     * Enregistre l'image et sa variante sous un nom aléatoire, et renvoie ce nom.
     *
     * @throws \InvalidArgumentException si un fichier ne tient pas sous 200 Ko (rien n'est gardé)
     */
    private function save(\GdImage $large, \GdImage $small): string
    {
        $directory = $this->getDirectory();
        $this->filesystem->mkdir($directory);
        $filename = bin2hex(random_bytes(16)).'.webp';

        $this->imageProcessor->saveWebp($large, $directory.'/'.$filename);
        try {
            $this->imageProcessor->saveWebp($small, $directory.'/'.self::variantFilename($filename));
        } catch (\InvalidArgumentException $exception) {
            $this->filesystem->remove($directory.'/'.$filename);
            throw $exception;
        }

        return $filename;
    }

    /**
     * Renvoie le dossier absolu des images des projets.
     */
    private function getDirectory(): string
    {
        return $this->publicDirectory.'/'.self::PUBLIC_DIRECTORY;
    }
}
