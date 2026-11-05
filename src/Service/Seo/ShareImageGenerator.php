<?php

declare(strict_types=1);

namespace App\Service\Seo;

use App\Entity\Profile;
use App\Repository\ProfileRepository;
use App\Service\Content\ProfilePhotoProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Produit l'image de partage Open Graph et Twitter (1200 × 630, JPEG) à partir du profil : photo à droite, nom,
 * titre et sous-titre à gauche, aux couleurs du site (vert #00786f, monogramme « GC »).
 *
 * Reproductible et sans service externe : PHP GD et les polices DejaVu fournies par mPDF. Le nom du fichier contient
 * l'empreinte de son contenu (nom, titre, sous-titre, photo, version du dessin) : une modification dans l'admin
 * donne une nouvelle adresse, que LinkedIn et les autres réseaux relisent. Le fichier est écrit dans
 * public/uploads/partage/ (non versionné, inscriptible en production) ; seul le dernier est gardé.
 *
 * Liens :
 * - Appelée par : Command\ShareImageCommand (app:seo:share-image, lancée par scripts/deploy.sh),
 *   EventListener\ShareImageRegenerationListener (nom, titre, sous-titre ou photo modifiés dans l'admin)
 * - Utilise : ProfileRepository, ProfilePhotoProvider
 * - Voir aussi : SeoContext::getShareImage() (balises og:image et twitter:image), tests/Service/Seo/ShareImageGeneratorTest
 */
final class ShareImageGenerator
{
    /** Dossier des images produites, relatif à public/. */
    public const PUBLIC_DIRECTORY = 'uploads/partage';

    /** Dimensions de l'image (pixels). */
    public const WIDTH = 1200;
    public const HEIGHT = 630;

    /** Version du dessin : à changer quand la mise en page change (nouvelle empreinte, nouvelle adresse). */
    private const LAYOUT_VERSION = '1';

    /** Largeur de la colonne de texte (pixels) ; la photo occupe le reste. */
    private const TEXT_WIDTH = 696;

    /**
     * Reçoit le profil, la photo et l'emplacement des fichiers.
     *
     * @param ProfileRepository    $profileRepository profil (nom, titre, sous-titre, photo)
     * @param ProfilePhotoProvider $photoProvider     chemin de la photo (envoyée dans l'admin ou photo par défaut)
     * @param string               $publicDir         racine des fichiers servis (public/ ; var/test-public en test)
     * @param string               $projectDir        racine du projet (polices de mPDF dans vendor/)
     */
    public function __construct(
        private readonly ProfileRepository $profileRepository,
        private readonly ProfilePhotoProvider $photoProvider,
        #[Autowire('%app.share_image_public_dir%')]
        private readonly string $publicDir,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * Produit l'image si elle n'existe pas encore pour le contenu actuel du profil, et retire les anciennes.
     *
     * Liens :
     * - Appelée par : ShareImageCommand::execute(), ShareImageRegenerationListener::postFlush()
     *
     * @return array{path: string, created: bool} chemin relatif à public/ ; created faux si l'image était déjà à jour
     *
     * @throws \RuntimeException si GD ou les polices manquent, ou si l'écriture échoue
     */
    public function generate(): array
    {
        $profile = $this->profileRepository->getOrCreate();
        $photoPath = $this->photoProvider->getAbsolutePath($this->photoProvider->getPhoto($profile->getPhotoFilename())['photo']);
        $path = self::PUBLIC_DIRECTORY.'/guillaume-couget-partage-'.$this->fingerprint($profile, $photoPath).'.jpg';
        $absolute = $this->publicDir.'/'.$path;

        if (is_file($absolute)) {
            return ['path' => $path, 'created' => false];
        }

        $image = $this->draw($profile, $photoPath);
        ob_start();
        imagejpeg($image, null, 88);
        $jpeg = (string) ob_get_clean();

        $filesystem = new Filesystem();
        $filesystem->remove(glob($this->publicDir.'/'.self::PUBLIC_DIRECTORY.'/guillaume-couget-partage-*.jpg') ?: []);
        $filesystem->dumpFile($absolute, $jpeg);
        @chmod($absolute, 0o644);

        return ['path' => $path, 'created' => true];
    }

    /**
     * Renvoie le chemin (relatif à public/) de l'image produite la plus récente, ou null s'il n'y en a pas.
     *
     * Liens :
     * - Appelée par : SeoContext::getShareImage()
     */
    public function findCurrent(): ?string
    {
        $files = glob($this->publicDir.'/'.self::PUBLIC_DIRECTORY.'/guillaume-couget-partage-*.jpg') ?: [];
        if ([] === $files) {
            return null;
        }
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return self::PUBLIC_DIRECTORY.'/'.basename($files[0]);
    }

    /**
     * Empreinte du contenu de l'image (12 caractères) : nom, titre, sous-titre, photo et version du dessin.
     *
     * @param Profile     $profile   profil
     * @param string|null $photoPath chemin absolu de la photo, ou null
     */
    private function fingerprint(Profile $profile, ?string $photoPath): string
    {
        $photo = null !== $photoPath ? (string) hash_file('sha256', $photoPath) : 'sans-photo';

        return substr(hash('sha256', implode("\n", [self::LAYOUT_VERSION, $profile->getFullName(), $profile->getJobTitle(), (string) $profile->getTagline(), $photo])), 0, 12);
    }

    /**
     * Dessine l'image : fond vert dégradé, monogramme, nom, filet, titre, sous-titre, adresse du site, photo.
     *
     * @param Profile     $profile   profil
     * @param string|null $photoPath chemin absolu de la photo, ou null (fond gris uni à droite)
     *
     * @throws \RuntimeException si GD ou les polices manquent
     */
    private function draw(Profile $profile, ?string $photoPath): \GdImage
    {
        $bold = $this->projectDir.'/vendor/mpdf/mpdf/ttfonts/DejaVuSans-Bold.ttf';
        $regular = $this->projectDir.'/vendor/mpdf/mpdf/ttfonts/DejaVuSans.ttf';
        if (!\function_exists('imagettftext') || !is_file($bold) || !is_file($regular)) {
            throw new \RuntimeException('Image de partage : extension GD (FreeType) ou polices DejaVu introuvables.');
        }

        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        // Dégradé vertical du vert foncé au vert du site (colonne de texte)
        for ($y = 0; $y < self::HEIGHT; ++$y) {
            $t = $y / (self::HEIGHT - 1);
            $color = imagecolorallocate($image, (int) round(0x0E + (0x0F - 0x0E) * $t), (int) round(0x4A + (0x6B - 0x4A) * $t), (int) round(0x46 + (0x63 - 0x46) * $t));
            imageline($image, 0, $y, self::TEXT_WIDTH - 1, $y, $color);
        }
        imagefilledrectangle($image, self::TEXT_WIDTH, 0, self::WIDTH - 1, self::HEIGHT - 1, imagecolorallocate($image, 0x6B, 0x6E, 0x70));
        $this->drawPhoto($image, $photoPath);

        $white = imagecolorallocate($image, 0xFF, 0xFF, 0xFF);
        $accent = imagecolorallocate($image, 0x5E, 0xEA, 0xD4);
        $soft = imagecolorallocate($image, 0xCC, 0xFB, 0xF1);
        $green = imagecolorallocate($image, 0x00, 0x78, 0x6F);

        // Monogramme « GC » : carré blanc arrondi, lettres vertes
        $this->roundedRectangle($image, 72, 64, 64, 64, 14, $white);
        $this->centeredText($image, 'GC', $bold, 20, 72, 64, 64, 64, $green);

        // Nom (une ou deux lignes), filet, titre, sous-titre, adresse du site
        $y = 150;
        foreach ($this->wrap($profile->getFullName(), $bold, 46, self::TEXT_WIDTH - 144, 2) as $line) {
            $y += 62;
            imagettftext($image, 46, 0, 72, $y, $white, $bold, $line);
        }
        $y += 34;
        imagefilledrectangle($image, 72, $y, 160, $y + 5, $accent);
        $y += 60;
        foreach ($this->wrap($profile->getJobTitle(), $bold, 26, self::TEXT_WIDTH - 144, 2) as $line) {
            imagettftext($image, 26, 0, 72, $y, $soft, $bold, $line);
            $y += 40;
        }
        $tagline = trim((string) $profile->getTagline());
        if ('' !== $tagline) {
            $y += 6;
            foreach ($this->wrap($tagline, $regular, 15, self::TEXT_WIDTH - 144, 3) as $line) {
                imagettftext($image, 15, 0, 72, $y, $soft, $regular, $line);
                $y += 28;
            }
        }
        imagettftext($image, 16, 0, 72, self::HEIGHT - 58, $white, $bold, 'guillaume-couget.fr');

        return $image;
    }

    /**
     * Pose la photo dans la colonne de droite, recadrée pour la remplir (centrée en largeur, haut de l'image gardé).
     *
     * @param \GdImage    $image     image en cours
     * @param string|null $photoPath chemin absolu de la photo (WebP, JPEG ou PNG), ou null
     */
    private function drawPhoto(\GdImage $image, ?string $photoPath): void
    {
        $content = null !== $photoPath ? file_get_contents($photoPath) : false;
        $photo = false !== $content ? @imagecreatefromstring($content) : false;
        if (!$photo instanceof \GdImage) {
            return;
        }

        $targetWidth = self::WIDTH - self::TEXT_WIDTH;
        $sourceWidth = imagesx($photo);
        $sourceHeight = imagesy($photo);
        $scale = max($targetWidth / $sourceWidth, self::HEIGHT / $sourceHeight);
        $cropWidth = (int) round($targetWidth / $scale);
        $cropHeight = (int) round(self::HEIGHT / $scale);
        $sourceX = (int) round(($sourceWidth - $cropWidth) / 2);

        imagecopyresampled($image, $photo, self::TEXT_WIDTH, 0, $sourceX, 0, $targetWidth, self::HEIGHT, $cropWidth, $cropHeight);
    }

    /**
     * Coupe un texte en lignes qui tiennent dans une largeur donnée (dernière ligne terminée par « … » si besoin).
     *
     * @param string $text     texte
     * @param string $font     police TrueType
     * @param float  $size     taille (points)
     * @param int    $maxWidth largeur maximale (pixels)
     * @param int    $maxLines nombre maximal de lignes
     *
     * Un texte fait de rubriques séparées par « · » (sous-titre) est coupé entre les rubriques, jamais au milieu
     * d'une rubrique (« Projets industriels » reste entier)
     *
     * @return list<string>
     */
    private function wrap(string $text, string $font, float $size, int $maxWidth, int $maxLines): array
    {
        $lines = [];
        $current = '';
        $separator = str_contains($text, ' · ') ? ' · ' : ' ';
        $tokens = ' · ' === $separator ? array_map('trim', explode(' · ', trim($text))) : (preg_split('/\s+/u', trim($text)) ?: []);
        foreach ($tokens as $word) {
            $candidate = '' === $current ? $word : $current.$separator.$word;
            if ('' !== $current && $this->textWidth($candidate, $font, $size) > $maxWidth) {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }
        if ('' !== $current) {
            $lines[] = $current;
        }
        if (\count($lines) > $maxLines) {
            $lines = \array_slice($lines, 0, $maxLines);
            $lines[$maxLines - 1] .= ' …';
        }

        return $lines;
    }

    /**
     * Largeur d'un texte (pixels).
     *
     * @param string $text texte
     * @param string $font police TrueType
     * @param float  $size taille (points)
     */
    private function textWidth(string $text, string $font, float $size): int
    {
        $box = imagettfbbox($size, 0, $font, $text);

        return false === $box ? 0 : $box[2] - $box[0];
    }

    /**
     * Écrit un texte centré dans un rectangle.
     *
     * @param \GdImage $image  image en cours
     * @param string   $text   texte
     * @param string   $font   police TrueType
     * @param float    $size   taille (points)
     * @param int      $x      gauche du rectangle
     * @param int      $y      haut du rectangle
     * @param int      $width  largeur du rectangle
     * @param int      $height hauteur du rectangle
     * @param int      $color  couleur GD
     */
    private function centeredText(\GdImage $image, string $text, string $font, float $size, int $x, int $y, int $width, int $height, int $color): void
    {
        $box = imagettfbbox($size, 0, $font, $text);
        if (false === $box) {
            return;
        }
        $textX = $x + (int) round(($width - ($box[2] - $box[0])) / 2) - $box[0];
        $textY = $y + (int) round(($height + ($box[1] - $box[7])) / 2) - $box[1];
        imagettftext($image, $size, 0, $textX, $textY, $color, $font, $text);
    }

    /**
     * Dessine un rectangle plein aux coins arrondis.
     *
     * @param \GdImage $image  image en cours
     * @param int      $x      gauche
     * @param int      $y      haut
     * @param int      $width  largeur
     * @param int      $height hauteur
     * @param int      $radius rayon des coins
     * @param int      $color  couleur GD
     */
    private function roundedRectangle(\GdImage $image, int $x, int $y, int $width, int $height, int $radius, int $color): void
    {
        imagefilledrectangle($image, $x + $radius, $y, $x + $width - 1 - $radius, $y + $height - 1, $color);
        imagefilledrectangle($image, $x, $y + $radius, $x + $width - 1, $y + $height - 1 - $radius, $color);
        foreach ([[$x + $radius, $y + $radius], [$x + $width - 1 - $radius, $y + $radius], [$x + $radius, $y + $height - 1 - $radius], [$x + $width - 1 - $radius, $y + $height - 1 - $radius]] as [$cx, $cy]) {
            imagefilledellipse($image, $cx, $cy, $radius * 2, $radius * 2, $color);
        }
    }
}
