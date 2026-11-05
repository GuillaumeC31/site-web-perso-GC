<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\SiteSettingsRepository;
use App\Service\Content\CvContentProvider;
use App\Service\Cv\PublicCvPdfCache;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * CV interactif public et téléchargement du CV en PDF (versions ATS et design).
 *
 * Depuis la correction de recette n° 1 (2026-10-30), le CV public est SYNTHÉTIQUE : puces publiques seulement,
 * résumés des rubriques, ni centres d'intérêt ni détail du parcours terrain ; le détail est dans l'espace
 * recruteur. Aucune coordonnée (téléphone, e-mail, adresse) n'apparaît dans ces pages ni dans ces PDF : le
 * contenu vient de CvContentProvider::getContent(), qui ne transmet que les champs publics.
 *
 * Liens :
 * - Utilise : CvContentProvider, PublicCvPdfCache (PDF publics en cache), SiteSettingsRepository (délai de réponse)
 * - Rend : templates/cv/show.html.twig
 * - Testé par : tests/Controller/CvPagesTest, tests/Controller/CvPublicLeakTest
 */
final class CvController extends AbstractController
{
    /** Clé de session du pare-feu « recruiter » (jeton du recruteur connecté). */
    private const RECRUITER_SESSION_KEY = '_security_recruiter';

    /**
     * Affiche le CV interactif synthétique.
     *
     * Liens :
     * - Route : cv_show (/cv)
     * - Utilise : CvContentProvider::getContent(), SiteSettings::getResponseDelay()
     * - Rend : templates/cv/show.html.twig
     *
     * @param CvContentProvider      $cvContentProvider  contenu public du CV
     * @param SiteSettingsRepository $settingsRepository délai de validation affiché dans la note
     * @param Request                $request            requête (session existante : recruteur connecté ?)
     *
     * @return Response la page HTML
     */
    #[Route('/cv', name: 'cv_show', methods: ['GET'])]
    public function show(CvContentProvider $cvContentProvider, SiteSettingsRepository $settingsRepository, Request $request): Response
    {
        return $this->render('cv/show.html.twig', $cvContentProvider->getContent() + [
            'response_delay' => $settingsRepository->getOrCreate()->getResponseDelay(),
            'recruiter_connected' => $this->isRecruiterConnected($request),
        ]);
    }

    /**
     * Télécharge le CV public synthétique en PDF, version « ats » (texte simple, lisible par les logiciels de
     * recrutement) ou « design » (mise en page soignée, une page).
     *
     * Le PDF vient du cache disque (PublicCvPdfCache, var/cv-pdf/) tant que le contenu du CV n'a pas changé.
     *
     * Liens :
     * - Route : cv_pdf (/cv/pdf/{variant})
     * - Utilise : PublicCvPdfCache::get() (CvPdfRenderer::render() si le cache n'est pas à jour)
     *
     * @param string           $variant  « ats » ou « design »
     * @param PublicCvPdfCache $pdfCache PDF publics en cache
     *
     * @return Response le fichier PDF (téléchargement)
     */
    #[Route('/cv/pdf/{variant}', name: 'cv_pdf', requirements: ['variant' => 'ats|design'], methods: ['GET'])]
    public function pdf(string $variant, PublicCvPdfCache $pdfCache): Response
    {
        $filename = 'ats' === $variant ? 'CV-Guillaume-Couget-synthetique-ATS.pdf' : 'CV-Guillaume-Couget-synthetique.pdf';

        return new Response($pdfCache->get($variant), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    /**
     * Dit si le visiteur a une session de l'espace recruteur, sans ouvrir de session pour les autres visiteurs
     * (/cv est hors du pare-feu « recruiter » : on lit seulement une session déjà existante). Une session
     * échue mène au formulaire d'accès, comme tout accès refusé à l'espace.
     *
     * Liens :
     * - Appelée par : show()
     */
    private function isRecruiterConnected(Request $request): bool
    {
        return $request->hasPreviousSession() && $request->getSession()->has(self::RECRUITER_SESSION_KEY);
    }
}
