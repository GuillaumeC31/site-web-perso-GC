<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ClimaticProfileRepository;
use App\Repository\EnergyProviderRepository;
use App\Repository\FGasRuleRepository;
use App\Repository\FluidRepository;
use App\Repository\PanelModelRepository;
use App\Repository\Printer3dRepository;
use App\Repository\RefrigerantRepository;
use App\Service\Calculator\CalculatorRegistry;
use App\Service\Calculator\CalculatorTool;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages publiques de la calculatrice (CDC § 8) : tableau de bord, catégorie, une page par outil.
 *
 * Seuls les outils portés et activés sont visibles (CalculatorTool::isPublic()). Les adresses
 * sont celles de l'ancien site ; un outil fusionné redirige (301) vers son remplaçant.
 * Les calculs se font dans le navigateur (assets/calculators/, contrôleurs « calculator--* »).
 *
 * Liens :
 * - Routes : calculator_dashboard (/calculatrice), calculator_category (/calculatrice/{category}),
 *   calculator_tool (/calculatrice/{category}/{tool})
 * - Utilise : CalculatorRegistry, Printer3dRepository, EnergyProviderRepository et FluidRepository (données de référence)
 * - Rend : templates/calculator/dashboard.html.twig, category.html.twig, tool.html.twig
 */
final class CalculatorController extends AbstractController
{
    /**
     * Reçoit le registre de la calculatrice et les dépôts des données de référence.
     *
     * @param CalculatorRegistry        $registry         catégories et outils
     * @param Printer3dRepository       $printers         imprimantes 3D de référence
     * @param EnergyProviderRepository  $providers        offres d'électricité de référence
     * @param FluidRepository           $fluids           fluides de référence (chauffe d'un fluide)
     * @param RefrigerantRepository     $refrigerants     fluides frigorigènes (fluides frigorigènes, chambre froide)
     * @param FGasRuleRepository        $fgasRules        règles F-Gas (chambre froide)
     * @param PanelModelRepository      $panelModels      modèles de panneaux des marques (chambre froide, enceinte climatique)
     * @param ClimaticProfileRepository $climaticProfiles profils de consigne (enceinte climatique)
     */
    public function __construct(
        private readonly CalculatorRegistry $registry,
        private readonly Printer3dRepository $printers,
        private readonly EnergyProviderRepository $providers,
        private readonly FluidRepository $fluids,
        private readonly RefrigerantRepository $refrigerants,
        private readonly FGasRuleRepository $fgasRules,
        private readonly PanelModelRepository $panelModels,
        private readonly ClimaticProfileRepository $climaticProfiles,
    ) {
    }

    /**
     * Affiche le tableau de bord : recherche, favoris, outils récents, catégories.
     *
     * Liens :
     * - Route : calculator_dashboard (/calculatrice)
     * - Rend : templates/calculator/dashboard.html.twig
     *
     * @return Response la page HTML
     */
    #[Route('/calculatrice', name: 'calculator_dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        return $this->render('calculator/dashboard.html.twig', [
            'categories' => $this->registry->getPublicCategories(),
            'tool_count' => $this->registry->countPublicTools(),
        ]);
    }

    /**
     * Affiche les outils d'une catégorie.
     *
     * Liens :
     * - Route : calculator_category (/calculatrice/{category})
     * - Rend : templates/calculator/category.html.twig
     *
     * @param string $category slug de la catégorie
     *
     * @return Response la page HTML, ou une redirection temporaire (302) vers la calculatrice si la catégorie
     *                  existe (adresse de l'ancien site) mais n'a encore aucun outil public
     *
     * @throws NotFoundHttpException si la catégorie n'existe pas
     */
    #[Route('/calculatrice/{category}', name: 'calculator_category', requirements: ['category' => '[a-z0-9-]+'], methods: ['GET'])]
    public function category(string $category): Response
    {
        $current = $this->registry->getCategory($category) ?? throw $this->createNotFoundException();
        if ([] === $current->getPublicTools()) {
            // 302 et non 301 : la catégorie sera publiée plus tard, à la même adresse
            return $this->redirectToRoute('calculator_dashboard', [], Response::HTTP_FOUND);
        }

        return $this->render('calculator/category.html.twig', ['category' => $current]);
    }

    /**
     * Affiche un outil, ou redirige si l'outil a été fusionné dans un autre.
     *
     * Liens :
     * - Route : calculator_tool (/calculatrice/{category}/{tool})
     * - Rend : templates/calculator/tool.html.twig (interface : CalculatorTool::getTemplate())
     *
     * @param string $category slug de la catégorie
     * @param string $tool     slug de l'outil
     *
     * @return Response la page HTML, une redirection permanente (301) si l'outil a été fusionné, ou temporaire
     *                  (302) vers sa catégorie s'il n'est pas public (à créer, désactivé) : ancienne adresse gardée
     *
     * @throws NotFoundHttpException si l'outil n'existe pas
     */
    #[Route('/calculatrice/{category}/{tool}', name: 'calculator_tool', requirements: ['category' => '[a-z0-9-]+', 'tool' => '[a-z0-9-]+'], methods: ['GET'])]
    public function tool(string $category, string $tool): Response
    {
        $current = $this->registry->getTool($category, $tool) ?? throw $this->createNotFoundException();

        if (CalculatorTool::STATUS_MERGED === $current->status && null !== $current->mergedInto) {
            [$targetCategory, $targetTool] = explode('/', $current->mergedInto, 2);

            return new RedirectResponse($this->generateUrl('calculator_tool', ['category' => $targetCategory, 'tool' => $targetTool]), Response::HTTP_MOVED_PERMANENTLY);
        }
        if (!$current->isPublic()) {
            // 302 et non 301 : l'outil sera publié plus tard, à la même adresse (une 301 resterait en cache)
            return $this->redirectToRoute('calculator_category', ['category' => $category], Response::HTTP_FOUND);
        }

        return $this->render('calculator/tool.html.twig', [
            'tool' => $current,
            'category' => $this->registry->getCategory($category),
        ] + $this->toolData($current));
    }

    /**
     * Fournit les données de référence (gérées dans l'admin) dont l'interface d'un outil a besoin.
     *
     * Liens :
     * - Appelée par : tool()
     * - Lue par : templates/calculator/tools/impression-3d/cout-impression.html.twig,
     *   templates/calculator/tools/thermodynamique/chauffe-fluide.html.twig,
     *   templates/calculator/tools/thermodynamique/fluides-frigorifiques.html.twig,
     *   templates/calculator/tools/thermodynamique/chambre-froide.html.twig,
     *   templates/calculator/tools/thermodynamique/enceinte-climatique.html.twig
     *
     * @param CalculatorTool $tool outil affiché
     *
     * @return array<string, mixed> variables supplémentaires du gabarit (vide pour la plupart des outils)
     */
    private function toolData(CalculatorTool $tool): array
    {
        return match ($tool->getKey()) {
            'impression-3d/cout-impression' => [
                'printers' => $this->printers->findAllOrdered(),
                'providers' => $this->providers->findAllOrdered(),
            ],
            'thermodynamique/chauffe-fluide' => ['fluids' => $this->fluids->findAllOrdered()],
            'thermodynamique/fluides-frigorifiques' => ['refrigerants' => $this->refrigerants->findAllOrdered()],
            'thermodynamique/chambre-froide' => [
                'refrigerants' => $this->refrigerants->findAllOrdered(),
                'fgas_rules' => $this->fgasRules->findVerifiedOrdered(),
                'panel_models' => $this->panelModels->findAllOrdered(),
            ],
            'thermodynamique/enceinte-climatique' => [
                'refrigerants' => $this->refrigerants->findAllOrdered(),
                'fgas_rules' => $this->fgasRules->findVerifiedOrdered(),
                'panel_models' => $this->panelModels->findAllOrdered(),
                'climatic_profiles' => $this->climaticProfiles->findAllOrdered(),
            ],
            default => [],
        };
    }
}
