<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Service\Image\WebpImageProcessor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;

/**
 * Enregistre la photo du profil envoyée depuis l'admin, au même format que la photo
 * par défaut du site (public/images/photos/) :
 * - photo : WebP 800 × 1000 (portrait 4:5), recadrée sur le haut de l'image ;
 * - miniature : WebP 400 × 400 (carré), recadrée sur le haut de l'image ;
 * - chacune pèse moins de 200 Ko (la qualité baisse par paliers si besoin).
 *
 * Règles (CDC § 10) : type réel vérifié (contrainte Image du formulaire), le fichier
 * envoyé n'est jamais conservé tel quel, nom aléatoire, dossier sans exécution de
 * script (public/uploads/.htaccess).
 *
 * Liens :
 * - Utilisé par : Admin\ProfileController::edit()
 * - Utilise : WebpImageProcessor (conversion et compression)
 * - Voir aussi : ProfilePhotoProvider (choix entre photo de l'admin et photo par défaut),
 *   ProfileType (contrainte Image), scripts/images/optimize-image.php (même format)
 */
final readonly class ProfilePhotoStorage
{
    /** Chemin public des photos envoyées, relatif à public/. */
    public const PUBLIC_DIRECTORY = 'uploads/profile';

    /** Dimensions de la photo (portrait 4:5) et de la miniature (carré), en pixels. */
    public const PHOTO_WIDTH = 800;
    public const PHOTO_HEIGHT = 1000;
    public const MINIATURE_SIZE = 400;

    /**
     * Reçoit le dossier public du projet.
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
     * Convertit une photo envoyée en photo et miniature WebP, et renvoie le nom de la photo.
     *
     * La miniature porte le même nom suivi de « -miniature » (voir miniatureFilename()).
     *
     * Liens :
     * - Appelée par : Admin\ProfileController::edit()
     *
     * @param File $file image déjà validée par le formulaire (JPEG, PNG ou WebP)
     *
     * @return string nom du fichier de la photo (ex. « 3f9c…e1.webp »)
     *
     * @throws \InvalidArgumentException si l'image est illisible ou ne tient pas sous 200 Ko
     */
    public function store(File $file): string
    {
        $source = $this->imageProcessor->load($file->getPathname());
        $base = bin2hex(random_bytes(16));
        $directory = $this->getDirectory();
        $this->filesystem->mkdir($directory);

        // Recadrage calé en haut de l'image, pour garder le visage.
        $photo = $this->imageProcessor->cover($source, self::PHOTO_WIDTH, self::PHOTO_HEIGHT, true);
        $miniature = $this->imageProcessor->cover($source, self::MINIATURE_SIZE, self::MINIATURE_SIZE, true);

        $this->imageProcessor->saveWebp($photo, $directory.'/'.$base.'.webp');
        try {
            $this->imageProcessor->saveWebp($miniature, $directory.'/'.$base.'-miniature.webp');
        } catch (\InvalidArgumentException $exception) {
            $this->filesystem->remove($directory.'/'.$base.'.webp');
            throw $exception;
        }

        return $base.'.webp';
    }

    /**
     * Supprime une photo et sa miniature (sans erreur si elles n'existent plus).
     *
     * Liens :
     * - Appelée par : Admin\ProfileController::edit() (remplacement ou retrait de la photo)
     *
     * @param string|null $filename nom du fichier de la photo, ou null (rien à faire)
     */
    public function remove(?string $filename): void
    {
        // basename() empêche toute sortie du dossier (ex. « ../ » dans un nom corrompu).
        if (null === $filename || '' === $filename || basename($filename) !== $filename) {
            return;
        }

        $this->filesystem->remove([
            $this->getDirectory().'/'.$filename,
            $this->getDirectory().'/'.self::miniatureFilename($filename),
        ]);
    }

    /**
     * Renvoie le nom de la miniature associée à une photo.
     *
     * Liens :
     * - Appelée par : remove(), ProfilePhotoProvider::getPhoto()
     *
     * Exemple : miniatureFilename('abc.webp') renvoie « abc-miniature.webp ».
     */
    public static function miniatureFilename(string $filename): string
    {
        return pathinfo($filename, \PATHINFO_FILENAME).'-miniature.webp';
    }

    /**
     * Renvoie le dossier absolu des photos envoyées.
     */
    private function getDirectory(): string
    {
        return $this->publicDirectory.'/'.self::PUBLIC_DIRECTORY;
    }
}
