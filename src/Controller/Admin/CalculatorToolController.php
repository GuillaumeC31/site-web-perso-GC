<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Form\CalculatorToolSettingType;
use App\Repository\CalculatorToolSettingRepository;
use App\Service\Calculator\CalculatorRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion des outils de la calculatrice dans l'admin (CDC § 8) : état de chaque outil,
 * activation, texte d'aide, norme citée (à vérifier avant affichage), ordre.
 *
 * Les outils eux-mêmes sont définis dans config/calculator/tools.yaml : pas d'ajout ni de
 * suppression ici, seulement des réglages (CalculatorToolSetting).
 *
 * Liens :
 * - Routes : admin_calculator_tool_index, admin_calculator_tool_edit (/{ADMIN_PATH}/calculatrice/outils)
 * - Utilise : CalculatorRegistry, CalculatorToolSettingRepository, CalculatorToolSettingType
 * - Rend : admin/calculator_tool/index.html.twig, admin/calculator_tool/edit.html.twig
 */
#[IsGranted('ADMIN_KEY_VERIFIED')]
#[Route('/calculatrice/outils', name: 'calculator_tool_')]
final class CalculatorToolController extends AbstractController
{
    /**
     * Reçoit le registre de la calculatrice.
     *
     * @param CalculatorRegistry $registry outils et réglages
     */
    public function __construct(private readonly CalculatorRegistry $registry)
    {
    }

    /**
     * Affiche tous les outils par catégorie, avec leur état.
     *
     * Liens :
     * - Route : admin_calculator_tool_index
     * - Rend : admin/calculator_tool/index.html.twig
     *
     * @return Response la page HTML
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/calculator_tool/index.html.twig', [
            'categories' => $this->registry->getCategories(),
            'public_count' => $this->registry->countPublicTools(),
        ]);
    }

    /**
     * Affiche et enregistre les réglages d'un outil.
     *
     * Liens :
     * - Route : admin_calculator_tool_edit (/{ADMIN_PATH}/calculatrice/outils/{category}/{slug})
     * - Utilise : CalculatorToolSettingRepository::findOrCreate(), CalculatorToolSettingType
     * - Rend : admin/calculator_tool/edit.html.twig (code 422 si le formulaire est invalide)
     *
     * @param string                          $category          slug de la catégorie
     * @param string                          $slug              slug de l'outil
     * @param Request                         $request           requête (formulaire soumis)
     * @param CalculatorToolSettingRepository $settingRepository réglages existants
     * @param EntityManagerInterface          $entityManager     gestionnaire Doctrine
     *
     * @return Response formulaire, ou redirection vers la liste après enregistrement
     *
     * @throws NotFoundHttpException si l'outil n'existe pas dans le registre
     */
    #[Route('/{category}/{slug}', name: 'edit', requirements: ['category' => '[a-z0-9-]+', 'slug' => '[a-z0-9-]+'], methods: ['GET', 'POST'])]
    public function edit(string $category, string $slug, Request $request, CalculatorToolSettingRepository $settingRepository, EntityManagerInterface $entityManager): Response
    {
        $tool = $this->registry->getTool($category, $slug) ?? throw $this->createNotFoundException();
        $setting = $settingRepository->findOrCreate($tool->getKey());

        $form = $this->createForm(CalculatorToolSettingType::class, $setting, [
            'can_be_enabled' => $tool->canBeEnabled(),
            'default_reference' => $tool->defaultReference,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($setting);
            $entityManager->flush();
            $this->registry->reset();
            $this->addFlash('success', 'admin.crud.updated');

            return $this->redirectToRoute('admin_calculator_tool_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/calculator_tool/edit.html.twig', [
            'tool' => $tool,
            'form' => $form,
        ]);
    }
}
