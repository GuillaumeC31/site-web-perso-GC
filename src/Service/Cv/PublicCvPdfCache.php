<?php

declare(strict_types=1);

namespace App\Service\Cv;

use App\Service\AppVersion;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Cache disque des deux PDF PUBLICS du CV (« design » et « ats ») : mPDF met près de 2 s à produire le CV mis en
 * page ; servi depuis le cache, le fichier part en quelques dizaines de millisecondes.
 *
 * - Clé : empreinte SHA-256 de la version du site, de la version du PDF et du HTML passé à mPDF (donc du contenu
 *   PUBLIC du CV, des templates et de la photo) : un contenu modifié donne une autre clé, même sans purge.
 * - Purge : à chaque modification du contenu du CV (admin ou app:content:sync-textes), par
 *   EventListener\CvPdfCacheInvalidationListener ; seul le dernier fichier de chaque version est gardé.
 * - Emplacement : var/cv-pdf/<environnement>/, JAMAIS dans public/ (le fichier n'est servi que par Symfony).
 * - Les PDF de l'espace recruteur (CV complet : coordonnées, prétentions) ne passent JAMAIS par ce cache :
 *   RecruiterAreaController::cvPdf() appelle CvPdfRenderer directement.
 *
 * Liens :
 * - Utilisé par : CvController::pdf() (route cv_pdf), EventListener\CvPdfCacheInvalidationListener
 * - Utilise : CvPdfRenderer (renderHtml(), render()), AppVersion
 * - Voir aussi : tests/Service/Cv/PublicCvPdfCacheTest.php
 */
final class PublicCvPdfCache
{
    /**
     * Reçoit le générateur de PDF, la version du site et l'emplacement du cache.
     *
     * @param CvPdfRenderer $renderer   générateur des PDF (mPDF)
     * @param AppVersion    $appVersion version du site (fichier VERSION)
     * @param string        $cacheDir   dossier du cache (var/cv-pdf/<environnement>)
     */
    public function __construct(
        private readonly CvPdfRenderer $renderer,
        private readonly AppVersion $appVersion,
        #[Autowire('%kernel.project_dir%/var/cv-pdf/%kernel.environment%')]
        private readonly string $cacheDir,
    ) {
    }

    /**
     * Renvoie le PDF public d'une version, depuis le cache s'il est à jour, sinon en le produisant puis en le gardant.
     *
     * Liens :
     * - Appelée par : CvController::pdf()
     * - Utilise : CvPdfRenderer::renderHtml() (clé), CvPdfRenderer::render() (production)
     *
     * @param string $variant « ats » ou « design »
     *
     * @return string le contenu du fichier PDF
     *
     * @throws \InvalidArgumentException si la version est inconnue
     */
    public function get(string $variant): string
    {
        $path = $this->pathFor($variant, $this->renderer->renderHtml($variant));
        if (is_file($path)) {
            $cached = file_get_contents($path);
            if (\is_string($cached) && '' !== $cached) {
                return $cached;
            }
        }

        $pdf = $this->renderer->render($variant);
        $this->store($variant, $path, $pdf);

        return $pdf;
    }

    /**
     * Vide le cache (toutes les versions du PDF).
     *
     * Liens :
     * - Appelée par : EventListener\CvPdfCacheInvalidationListener::postFlush() (contenu du CV modifié)
     */
    public function clear(): void
    {
        (new Filesystem())->remove(glob($this->cacheDir.'/*.pdf') ?: []);
    }

    /**
     * Indique combien de PDF sont en cache (contrôles et tests).
     *
     * Liens :
     * - Appelée par : tests/Service/Cv/PublicCvPdfCacheTest.php
     */
    public function count(): int
    {
        return \count(glob($this->cacheDir.'/*.pdf') ?: []);
    }

    /**
     * Calcule le chemin du fichier en cache pour une version et un HTML donnés.
     *
     * @param string $variant « ats » ou « design »
     * @param string $html    HTML public passé à mPDF
     */
    private function pathFor(string $variant, string $html): string
    {
        $key = hash('sha256', $this->appVersion->get()."\n".$variant."\n".$html);

        return $this->cacheDir.'/'.$variant.'-'.$key.'.pdf';
    }

    /**
     * Enregistre le PDF (écriture atomique) et retire les anciens fichiers de la même version.
     *
     * @param string $variant « ats » ou « design »
     * @param string $path    chemin du fichier à écrire
     * @param string $pdf     contenu du PDF
     */
    private function store(string $variant, string $path, string $pdf): void
    {
        $filesystem = new Filesystem();
        foreach (glob($this->cacheDir.'/'.$variant.'-*.pdf') ?: [] as $old) {
            if ($old !== $path) {
                $filesystem->remove($old);
            }
        }
        // dumpFile() écrit dans un fichier temporaire puis le renomme : jamais de PDF à moitié écrit servi
        $filesystem->dumpFile($path, $pdf);
    }
}
