<?php

declare(strict_types=1);

/*
 * Produit les icônes du site à partir du monogramme « GC » de public/favicon.svg (carré arrondi vert #00786f,
 * lettres blanches) : public/favicon.ico (16, 32 et 48 px, pour les navigateurs qui demandent /favicon.ico
 * d'eux-mêmes) et public/apple-touch-icon.png (180 px, écran d'accueil des téléphones).
 *
 * Reproductible, sans service externe : PHP GD et la police DejaVu Sans Bold fournie par mPDF
 * (vendor/mpdf/mpdf/ttfonts). Relancer après une modification de public/favicon.svg.
 *
 * Liens :
 * - Fichiers produits : déclarés dans templates/base.html.twig (balises link rel="icon" et "apple-touch-icon")
 * - Testé par : tests/Security/FaviconTest.php
 * - Voir aussi : scripts/security/check-staged-assets.sh (apple-touch-icon.png, seule image PNG permise)
 *
 * Usage : php scripts/images/build-favicons.php
 */

$root = dirname(__DIR__, 2);
$font = $root.'/vendor/mpdf/mpdf/ttfonts/DejaVuSans-Bold.ttf';
if (!extension_loaded('gd') || !is_file($font)) {
    fwrite(STDERR, "Erreur : extension GD ou police DejaVu Sans Bold (composer install) introuvable.\n");
    exit(1);
}

/**
 * Dessine le monogramme « GC » dans un carré de $size pixels, coins arrondis, fond transparent autour.
 *
 * Le dessin est fait 4 fois plus grand puis réduit : bords lisses même en 16 px.
 *
 * @param int    $size  côté de l'image (pixels)
 * @param string $font  chemin de la police TrueType
 * @param bool   $round vrai : coins arrondis (favicon) ; faux : carré plein (apple-touch-icon, arrondi par iOS)
 */
function drawMonogram(int $size, string $font, bool $round): GdImage
{
    $scale = 4;
    $big = $size * $scale;
    $canvas = imagecreatetruecolor($big, $big);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
    imagealphablending($canvas, true);

    $green = imagecolorallocate($canvas, 0x00, 0x78, 0x6F);
    $white = imagecolorallocate($canvas, 0xFF, 0xFF, 0xFF);

    // Carré arrondi : rayon de 14/64 du côté, comme public/favicon.svg
    $radius = $round ? (int) round($big * 14 / 64) : 0;
    imagefilledrectangle($canvas, $radius, 0, $big - 1 - $radius, $big - 1, $green);
    imagefilledrectangle($canvas, 0, $radius, $big - 1, $big - 1 - $radius, $green);
    if ($radius > 0) {
        foreach ([[$radius, $radius], [$big - 1 - $radius, $radius], [$radius, $big - 1 - $radius], [$big - 1 - $radius, $big - 1 - $radius]] as [$cx, $cy]) {
            imagefilledellipse($canvas, $cx, $cy, $radius * 2, $radius * 2, $green);
        }
    }

    // « GC » centré, hauteur proche de celle du SVG (police de 28/64 du côté)
    $fontSize = $big * 28 / 64 * 0.75;
    $box = imagettfbbox($fontSize, 0, $font, 'GC');
    $textWidth = $box[2] - $box[0];
    $textHeight = $box[1] - $box[7];
    $x = (int) round(($big - $textWidth) / 2 - $box[0]);
    $y = (int) round(($big + $textHeight) / 2 - $box[1]);
    imagettftext($canvas, $fontSize, 0, $x, $y, $white, $font, 'GC');

    $image = imagecreatetruecolor($size, $size);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagecopyresampled($image, $canvas, 0, 0, 0, 0, $size, $size, $big, $big);

    return $image;
}

/**
 * Renvoie le PNG d'une image GD.
 *
 * @param GdImage $image image à encoder
 */
function pngBytes(GdImage $image): string
{
    ob_start();
    imagepng($image, null, 9);

    return (string) ob_get_clean();
}

// favicon.ico : format ICO contenant trois PNG (16, 32, 48 px), lu par tous les navigateurs actuels
$sizes = [16, 32, 48];
$images = array_map(static fn (int $size): string => pngBytes(drawMonogram($size, $font, true)), $sizes);
$ico = pack('vvv', 0, 1, count($images));
$offset = 6 + 16 * count($images);
foreach ($images as $index => $png) {
    $ico .= pack('CCCCvvVV', $sizes[$index], $sizes[$index], 0, 0, 1, 32, strlen($png), $offset);
    $offset += strlen($png);
}
$ico .= implode('', $images);
file_put_contents($root.'/public/favicon.ico', $ico);

// apple-touch-icon.png : 180 px, carré plein (iOS arrondit lui-même les coins)
file_put_contents($root.'/public/apple-touch-icon.png', pngBytes(drawMonogram(180, $font, false)));

echo 'Icônes produites : public/favicon.ico ('.strlen($ico)." octets, 16/32/48 px), public/apple-touch-icon.png (180 px).\n";
