<?php

declare(strict_types=1);

namespace App\Service\Cv;

use App\Service\Content\CvContentProvider;
use App\Service\Content\ProfilePhotoProvider;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/**
 * Produit le CV public en PDF avec mPDF (100 % PHP, sans navigateur : compatible l’hébergeur).
 *
 * Deux versions :
 * - « ats » : une colonne, texte simple, sans image ni tableau de mise en page, pour
 *   être bien lu par les logiciels de tri des candidatures ;
 * - « design » : reproduction du CV de référence aux couleurs du site :
 *   colonne de gauche (photo, nom, profil, modalités, langues, savoir-être), bandeaux de rubrique, encadrés.
 *
 * Version publique = CV SYNTHÉTIQUE (correction de recette n° 1, 2026-10-30 ; CvContentProvider::getContent()) :
 * puces publiques seulement, résumés des rubriques, ni centres d'intérêt ni coordonnées ; « design » sur une page,
 * avec le pied « CV complet sur demande ». Version privée (espace recruteur, CV complet) : tout le détail,
 * téléphone, e-mail, prétentions si cochées, vrais noms d'entreprises (CvContentProvider::getPrivateContent()) ;
 * jamais d'adresse postale ni de date de naissance.
 *
 * Liens :
 * - Utilisé par : PublicCvPdfCache (PDF publics, route cv_pdf), RecruiterAreaController::cvPdf() (route recruiter_cv_pdf, jamais en cache)
 * - Utilise : CvContentProvider, ProfilePhotoProvider, templates/cv/pdf/ats.html.twig, templates/cv/pdf/design.html.twig
 */
final readonly class CvPdfRenderer
{
    /** Versions disponibles. */
    public const VARIANTS = ['ats', 'design'];

    /**
     * Reçoit les dépendances.
     *
     * @param CvContentProvider    $cvContentProvider    contenu public du CV
     * @param Environment          $twig                 moteur de templates
     * @param ProfilePhotoProvider $profilePhotoProvider chemin absolu de la miniature
     * @param string               $cacheDir             dossier de cache (fichiers temporaires de mPDF)
     * @param string               $projectDir           racine du projet (fond de page resources/pdf/cv-band.svg)
     * @param string               $defaultUri           adresse publique du site (DEFAULT_URI), cible du QR code
     */
    public function __construct(
        private CvContentProvider $cvContentProvider,
        private Environment $twig,
        private ProfilePhotoProvider $profilePhotoProvider,
        #[Autowire('%kernel.cache_dir%')]
        private string $cacheDir,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        #[Autowire('%env(DEFAULT_URI)%')]
        private string $defaultUri,
    ) {
    }

    /**
     * Renvoie le QR code du PDF : le CV public mène à l'espace recruteur (demande du CV complet), le CV complet de
     * l'espace recruteur mène au CV en ligne. Dessiné par mPDF (bibliothèque mpdf/qrcode, sans service externe).
     *
     * Liens :
     * - Appelée par : renderHtml() (variable qr_code des templates cv/pdf/*.html.twig)
     *
     * @param bool $private vrai : CV complet de l'espace recruteur
     *
     * @return array{url: string, caption: string} adresse codée et légende (clé de traduction)
     */
    public function qrCode(bool $private): array
    {
        $base = rtrim($this->defaultUri, '/');

        return $private
            ? ['url' => $base.'/cv', 'caption' => 'cv.pdf.qr_private']
            : ['url' => $base.'/espace-recruteur', 'caption' => 'cv.pdf.qr_public'];
    }

    /**
     * Construit le HTML du PDF (séparé de render() pour pouvoir le vérifier dans les tests).
     *
     * Liens :
     * - Appelée par : render(), PublicCvPdfCache::get() (clé du cache), tests/Controller/CvControllerTest
     * - Utilise : CvContentProvider::getContent()
     *
     * @param string $variant « ats » ou « design »
     * @param bool   $private vrai : CV complet de l'espace recruteur
     *
     * @return string le HTML passé à mPDF
     *
     * @throws \InvalidArgumentException si la version est inconnue
     */
    public function renderHtml(string $variant, bool $private = false): string
    {
        if (!\in_array($variant, self::VARIANTS, true)) {
            throw new \InvalidArgumentException(\sprintf('Version de CV inconnue : %s.', $variant));
        }

        $content = $private ? $this->cvContentProvider->getPrivateContent() : $this->cvContentProvider->getContent();
        // Miniature carrée cadrée sur le visage : lisible dans le cadre de 30 × 30 mm
        $photo = $content['profile']['photo_miniature'] ?? null;

        return $this->twig->render('cv/pdf/'.$variant.'.html.twig', $content + [
            // mPDF lit l'image sur le disque : chemin absolu (photo de l'admin ou photo par défaut)
            'photo_path' => null === $photo ? null : $this->profilePhotoProvider->getAbsolutePath($photo),
            // Colonne de gauche du CV « mis en page » (fond de page)
            'band_path' => $this->projectDir.'/resources/pdf/cv-band.svg',
            'generated_at' => new \DateTimeImmutable(),
            'private_contact' => $private ? $content['private'] : null,
            'qr_code' => $this->qrCode($private),
        ]);
    }

    /**
     * Produit le PDF et renvoie son contenu binaire.
     *
     * Liens :
     * - Appelée par : PublicCvPdfCache::get() (PDF publics), RecruiterAreaController::cvPdf() (CV complet, sans cache)
     * - Utilise : renderHtml(), mPDF
     *
     * @param string $variant « ats » ou « design »
     * @param bool   $private vrai : CV complet de l'espace recruteur
     *
     * @return string le contenu du fichier PDF
     */
    public function render(string $variant, bool $private = false): string
    {
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => $this->cacheDir.'/mpdf',
            'default_font' => 'dejavusans',
            // Justification SANS césure (décision du 2026-10-25 : pas de mots coupés, copier-coller intact) : la
            // moitié de l'espace entre les mots, l'autre entre les lettres mais plafonnée à 0,2 pt (presque
            // invisible ; 0 voudrait dire « sans plafond » dans mPDF) : pas de grands blancs sur les lignes à mots longs
            'jSWord' => 0.5,
            'jSmaxChar' => 0.2,
            // Dernière ligne d'un paragraphe justifié : alignée à gauche, sans reprendre les espaces de la ligne précédente
            'jSmaxWordLast' => 0,
            'jSmaxCharLast' => 0,
            // Marges en millimètres ; « ats » : 15 / 13 mm pour tenir en 3 pages en 10 pt ; « design » : la marge de
            // gauche laisse la place à la colonne de gauche (62 mm)
            'margin_left' => 'ats' === $variant ? 15 : 68,
            'margin_right' => 'ats' === $variant ? 15 : 9,
            'margin_top' => 'ats' === $variant ? 13 : 9,
            // CV synthétique mis en page (public) : place pour la ligne de pied « CV complet sur demande »
            'margin_bottom' => 'ats' === $variant ? 13 : ($private ? 9 : 13),
            'margin_footer' => 5,
        ]);
        $mpdf->SetTitle('CV — Guillaume Couget');
        $mpdf->SetAuthor('Guillaume Couget');
        $mpdf->SetCreator('guillaume-couget.fr');
        $mpdf->WriteHTML($this->renderHtml($variant, $private));

        return $mpdf->Output('', Destination::STRING_RETURN);
    }
}
