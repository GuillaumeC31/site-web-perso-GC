<?php

declare(strict_types=1);

/*
 * Convertit une image originale (PNG ou JPEG) en WebP optimisé pour public/images.
 *
 * Les originaux lourds ne sont jamais versionnés : ils sont rangés hors du dépôt
 * (../medias_originaux/). Seules les versions WebP de moins de 200 Ko vont dans public/.
 *
 * Usage :
 *   php scripts/images/optimize-image.php <original> <destination.webp> [largeur_max=1200] [qualité=82]
 *
 * Exemple :
 *   php scripts/images/optimize-image.php ../medias_originaux/photos/GuillaumeC.png public/images/photos/guillaume-couget.webp 600
 *
 * Utilise l'extension GD de PHP (transparence conservée). Échoue si le résultat dépasse 200 Ko.
 */

const MAX_BYTES = 200 * 1024;

[$script, $source, $destination] = $argv + [null, null, null];
$maxWidth = (int) ($argv[3] ?? 1200);
$quality = (int) ($argv[4] ?? 82);

if (null === $source || null === $destination || !str_ends_with($destination, '.webp')) {
    fwrite(\STDERR, "Usage : php $script <original> <destination.webp> [largeur_max] [qualité]\n");
    exit(1);
}

$info = @getimagesize($source);
if (false === $info) {
    fwrite(\STDERR, "Image illisible : $source\n");
    exit(1);
}

$image = match ($info['mime']) {
    'image/png' => imagecreatefrompng($source),
    'image/jpeg' => imagecreatefromjpeg($source),
    'image/webp' => imagecreatefromwebp($source),
    default => false,
};
if (false === $image) {
    fwrite(\STDERR, "Format non pris en charge : {$info['mime']}\n");
    exit(1);
}

// Réduction (jamais d'agrandissement), transparence conservée.
if (imagesx($image) > $maxWidth) {
    $resized = imagescale($image, $maxWidth, -1, \IMG_BICUBIC);
    if (false === $resized) {
        fwrite(\STDERR, "Redimensionnement impossible.\n");
        exit(1);
    }
    $image = $resized;
}
imagepalettetotruecolor($image);
imagealphablending($image, false);
imagesavealpha($image, true);

if (!is_dir(dirname($destination))) {
    mkdir(dirname($destination), 0775, true);
}
imagewebp($image, $destination, $quality);

$size = filesize($destination);
printf("%s : %dx%d, %.0f Ko\n", $destination, imagesx($image), imagesy($image), $size / 1024);

if ($size > MAX_BYTES) {
    fwrite(\STDERR, "Trop lourd (plus de 200 Ko) : réduire la largeur ou la qualité.\n");
    exit(2);
}
