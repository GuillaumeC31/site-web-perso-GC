<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ProjectRepository;
use App\Service\Content\CvContentProvider;
use App\Service\Content\RecruitmentArguments;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Affiche la page d'accueil publique (maquette ../maquette_accueil/).
 *
 * Sections : présentation avec photo, chiffres clés, domaines d'expertise, parcours en
 * bref, études de cas, calculatrice, appel au contact. Les éléments « à compléter » ne
 * sont jamais affichés (bloc masqué), ni les études de cas incomplètes.
 *
 * Liens :
 * - Utilise : CvContentProvider (contenu public du CV), ProjectRepository (études de cas), RecruitmentArguments
 *   (bloc « Pourquoi me recruter »)
 * - Rend : templates/home/index.html.twig
 */
final class HomeController extends AbstractController
{
    /** Nombre d'expériences présentées dans « Parcours en bref ». */
    private const HIGHLIGHTED_EXPERIENCES = 3;

    /** Nombre d'études de cas présentées (mises en avant d'abord). */
    private const CASE_STUDIES = 3;

    /**
     * Affiche l'accueil.
     *
     * Liens :
     * - Route : home (/)
     * - Utilise : CvContentProvider::getContent(), ProjectRepository::findHomeCaseStudies(),
     *   RecruitmentArguments::getDisplayed()
     * - Rend : templates/home/index.html.twig
     *
     * @param CvContentProvider    $cvContentProvider    contenu public du CV
     * @param ProjectRepository    $projectRepository    études de cas visibles
     * @param RecruitmentArguments $recruitmentArguments arguments « Pourquoi me recruter » affichés (vide : bloc absent)
     *
     * @return Response la page HTML
     */
    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(CvContentProvider $cvContentProvider, ProjectRepository $projectRepository, RecruitmentArguments $recruitmentArguments): Response
    {
        $content = $cvContentProvider->getContent();

        return $this->render('home/index.html.twig', [
            'profile' => $content['profile'],
            'key_figures' => $content['key_figures'],
            'pillars' => $content['pillars'],
            'highlighted_experiences' => \array_slice($content['career']['professional'], 0, self::HIGHLIGHTED_EXPERIENCES),
            'case_studies' => $projectRepository->findHomeCaseStudies(self::CASE_STUDIES),
            'recruitment_arguments' => $recruitmentArguments->getDisplayed(),
        ]);
    }
}
