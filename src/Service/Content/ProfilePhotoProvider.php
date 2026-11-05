<?php

declare(strict_types=1);

namespace App\Service\Content;

use App\Service\Admin\ProfilePhotoStorage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Choisit la photo du profil à afficher : celle envoyée depuis l'admin si elle existe,
 * sinon la photo par défaut versionnée dans public/images/photos/.
 *
 * La photo par défaut suit le dépôt : elle est en ligne dès le déploiement, sans
 * nouvel envoi depuis l'admin.
 *
 * Liens :
 * - Utilisé par : CvContentProvider (accueil, CV), CvPdfRenderer (PDF design),
 *   Admin\ProfileController (aperçu)
 * - Voir aussi : ProfilePhotoStorage (traitement des photos envoyées)
 */
final readonly class ProfilePhotoProvider
{
    /** Photo par défaut (800 × 1000) et sa miniature (400 × 400), relatives à public/. */
    public const DEFAULT_PHOTO = 'images/photos/guillaume-couget.webp';
    public const DEFAULT_MINIATURE = 'images/photos/guillaume-couget-miniature.webp';

    /**
     * Reçoit le dossier public du projet.
     *
     * @param string $publicDirectory chemin absolu de public/
     */
    public function __construct(
        #[Autowire('%kernel.project_dir%/public')]
        private string $publicDirectory,
    ) {
    }

    /**
     * Renvoie les chemins de la photo et de la miniature à afficher.
     *
     * Liens :
     * - Appelée par : CvContentProvider::getContent(), Admin\ProfileController::edit()
     *
     * @param string|null $uploadedFilename nom de la photo envoyée depuis l'admin, ou null
     *
     * @return array{photo: string, miniature: string, is_default: bool}
     *                                                                   chemins relatifs à public/ (pour asset()), is_default vrai si photo par défaut
     */
    public function getPhoto(?string $uploadedFilename): array
    {
        if (null !== $uploadedFilename && '' !== $uploadedFilename && basename($uploadedFilename) === $uploadedFilename
            && is_file($this->publicDirectory.'/'.ProfilePhotoStorage::PUBLIC_DIRECTORY.'/'.$uploadedFilename)) {
            $photo = ProfilePhotoStorage::PUBLIC_DIRECTORY.'/'.$uploadedFilename;
            $miniature = ProfilePhotoStorage::PUBLIC_DIRECTORY.'/'.ProfilePhotoStorage::miniatureFilename($uploadedFilename);

            return [
                'photo' => $photo,
                // Photo envoyée avant la création des miniatures : la photo sert de miniature.
                'miniature' => is_file($this->publicDirectory.'/'.$miniature) ? $miniature : $photo,
                'is_default' => false,
            ];
        }

        return ['photo' => self::DEFAULT_PHOTO, 'miniature' => self::DEFAULT_MINIATURE, 'is_default' => true];
    }

    /**
     * Renvoie le chemin absolu d'une image publique (pour le PDF, qui lit le fichier sur le disque).
     *
     * Liens :
     * - Appelée par : CvPdfRenderer::renderHtml()
     *
     * @param string $publicPath chemin relatif à public/ (ex. « images/photos/guillaume-couget.webp »)
     *
     * @return string|null le chemin absolu, ou null si le fichier n'existe pas
     */
    public function getAbsolutePath(string $publicPath): ?string
    {
        $path = $this->publicDirectory.'/'.$publicPath;

        return is_file($path) ? $path : null;
    }
}
