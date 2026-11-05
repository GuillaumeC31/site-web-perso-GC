<?php

declare(strict_types=1);

namespace App\Service\Image;

/**
 * Convertit les images envoyées en WebP légers, avec GD (disponible sur l’hébergeur).
 *
 * Règle du site : chaque fichier produit pèse au plus 200 Ko ; la qualité baisse
 * par paliers si besoin. Le fichier d'origine n'est jamais conservé.
 *
 * Liens :
 * - Utilisé par : Admin\ProfilePhotoStorage (photo de profil), Admin\ProjectImageStorage
 *   (couvertures et galeries des projets)
 * - Voir aussi : scripts/images/optimize-image.php (même règle pour les images versionnées)
 */
final class WebpImageProcessor
{
    /** Poids maximal de chaque fichier produit. */
    public const MAX_BYTES = 200 * 1024;

    /** Qualités WebP essayées, de la meilleure à la plus compressée. */
    private const QUALITIES = [85, 80, 75, 70, 65, 60, 55, 50];

    /**
     * Ouvre une image JPEG, PNG, WebP ou GIF (première image d'un GIF animé).
     *
     * Liens :
     * - Appelée par : ProfilePhotoStorage::store(), ProjectImageStorage
     *
     * @param string $path chemin du fichier
     *
     * @throws \InvalidArgumentException si le format n'est pas reconnu
     */
    public function load(string $path): \GdImage
    {
        $info = @getimagesize($path);
        $image = match ($info['mime'] ?? null) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            'image/gif' => @imagecreatefromgif($path),
            default => false,
        };

        if (!$image instanceof \GdImage) {
            throw new \InvalidArgumentException('Image illisible : JPEG, PNG, WebP ou GIF attendu.');
        }

        // Une image en palette (GIF, certains PNG) doit passer en couleurs vraies pour le WebP.
        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        return $image;
    }

    /**
     * Recadre et redimensionne pour remplir exactement le format voulu.
     *
     * Le recadrage est centré horizontalement ; verticalement, il est calé en haut
     * (portrait : on garde le visage) ou centré.
     *
     * Liens :
     * - Appelée par : ProfilePhotoStorage::store(), ProjectImageStorage::storeCover()
     *
     * @param \GdImage $source image d'origine
     * @param int      $width  largeur voulue, en pixels
     * @param int      $height hauteur voulue, en pixels
     * @param bool     $top    vrai : recadrage calé en haut ; faux : centré
     */
    public function cover(\GdImage $source, int $width, int $height, bool $top = false): \GdImage
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $scale = max($width / $sourceWidth, $height / $sourceHeight);
        $cropWidth = (int) round($width / $scale);
        $cropHeight = (int) round($height / $scale);
        $cropX = (int) round(($sourceWidth - $cropWidth) / 2);
        $cropY = $top ? 0 : (int) round(($sourceHeight - $cropHeight) / 2);

        $target = $this->createCanvas($width, $height);
        imagecopyresampled($target, $source, 0, 0, $cropX, $cropY, $width, $height, $cropWidth, $cropHeight);

        return $target;
    }

    /**
     * Réduit l'image pour qu'elle tienne dans le cadre donné, sans recadrage ni agrandissement.
     *
     * Liens :
     * - Appelée par : ProjectImageStorage::storeGalleryImage()
     *
     * @param \GdImage $source    image d'origine
     * @param int      $maxWidth  largeur maximale, en pixels
     * @param int      $maxHeight hauteur maximale, en pixels
     */
    public function fit(\GdImage $source, int $maxWidth, int $maxHeight): \GdImage
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $scale = min(1, $maxWidth / $sourceWidth, $maxHeight / $sourceHeight);
        $width = max(1, (int) round($sourceWidth * $scale));
        $height = max(1, (int) round($sourceHeight * $scale));

        $target = $this->createCanvas($width, $height);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);

        return $target;
    }

    /**
     * Enregistre en WebP en baissant la qualité jusqu'à passer sous 200 Ko.
     *
     * Liens :
     * - Appelée par : ProfilePhotoStorage::store(), ProjectImageStorage
     *
     * @param \GdImage $image image à enregistrer
     * @param string   $path  chemin du fichier WebP à écrire
     *
     * @throws \InvalidArgumentException si même la qualité la plus basse dépasse 200 Ko
     */
    public function saveWebp(\GdImage $image, string $path): void
    {
        foreach (self::QUALITIES as $quality) {
            imagewebp($image, $path, $quality);
            clearstatcache(true, $path);
            if (filesize($path) <= self::MAX_BYTES) {
                return;
            }
        }

        @unlink($path);
        throw new \InvalidArgumentException('L\'image ne tient pas sous 200 Ko, même compressée.');
    }

    /**
     * Crée une image vide en couleurs vraies, transparence conservée.
     *
     * @throws \InvalidArgumentException si GD ne peut pas créer l'image
     */
    private function createCanvas(int $width, int $height): \GdImage
    {
        $target = imagecreatetruecolor($width, $height);
        if (false === $target) {
            throw new \InvalidArgumentException('Impossible de créer l\'image.');
        }
        imagealphablending($target, false);
        imagesavealpha($target, true);

        return $target;
    }
}
