<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\RecruitmentArgument;
use App\Entity\SiteSettings;
use App\Entity\SortableInterface;
use App\Form\RecruitmentArgumentType;
use App\Repository\SiteSettingsRepository;
use App\Service\Content\RecruitmentArguments;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin › « Pourquoi me recruter » : arguments du bloc de l'accueil (liste, ajout, modification, suppression,
 * ordre), interrupteur « Afficher » de chaque argument, interrupteur général du bloc et aperçu du rendu.
 *
 * Liens :
 * - Routes : admin_recruitment_argument_index, _new, _edit, _delete, _move, _toggle, _block, _preview
 *   (/{ADMIN_PATH}/pourquoi-me-recruter)
 * - Hérite de : AbstractCrudController (actions et templates communs)
 * - Rend : admin/recruitment_argument/index.html.twig (liste et interrupteur général),
 *   admin/recruitment_argument/preview.html.twig (aperçu)
 * - Utilise : RecruitmentArgument, RecruitmentArgumentType, SiteSettingsRepository, Service\Content\RecruitmentArguments
 *
 * @extends AbstractCrudController<RecruitmentArgument>
 */
#[IsGranted('ADMIN_KEY_VERIFIED')]
#[Route('/pourquoi-me-recruter', name: 'recruitment_argument_')]
final class RecruitmentArgumentController extends AbstractCrudController
{
    /**
     * Affiche ou masque un argument (interrupteur de la ligne).
     *
     * Liens :
     * - Route : admin_recruitment_argument_toggle (POST /{ADMIN_PATH}/pourquoi-me-recruter/{id}/affichage)
     * - Formulaire : admin/crud/index.html.twig (action de ligne « post »)
     *
     * @param int     $id      identifiant de l'argument
     * @param Request $request formulaire (jeton CSRF)
     *
     * @return Response redirection vers la liste
     */
    #[Route('/{id}/affichage', name: 'toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggle(int $id, Request $request): Response
    {
        $argument = $this->findEntity($id);
        if (!$this->isCsrfTokenValid('admin_recruitment_argument_toggle_'.$id, (string) $request->getPayload()->get('_token'))) {
            $this->addFlash('danger', 'admin.crud.invalid_csrf');

            return $this->redirectToRoute('admin_recruitment_argument_index', [], Response::HTTP_SEE_OTHER);
        }

        $argument->setVisible(!$argument->isVisible());
        $this->entityManager->flush();
        $this->addFlash('success', $argument->isVisible() ? 'admin.recruitment_argument.shown' : 'admin.recruitment_argument.hidden');

        return $this->redirectToRoute('admin_recruitment_argument_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Active ou coupe le bloc entier sur l'accueil (interrupteur général, dans les paramètres du site).
     *
     * Liens :
     * - Route : admin_recruitment_argument_block (POST /{ADMIN_PATH}/pourquoi-me-recruter/bloc)
     * - Formulaire : admin/recruitment_argument/index.html.twig
     * - Utilise : SiteSettings::setRecruitmentBlockEnabled()
     *
     * @param Request                $request            formulaire (jeton CSRF, champ enabled)
     * @param SiteSettingsRepository $settingsRepository paramètres du site
     *
     * @return Response redirection vers la liste
     */
    #[Route('/bloc', name: 'block', methods: ['POST'])]
    public function toggleBlock(Request $request, SiteSettingsRepository $settingsRepository): Response
    {
        if (!$this->isCsrfTokenValid('admin_recruitment_argument_block', (string) $request->getPayload()->get('_token'))) {
            $this->addFlash('danger', 'admin.crud.invalid_csrf');

            return $this->redirectToRoute('admin_recruitment_argument_index', [], Response::HTTP_SEE_OTHER);
        }

        $settings = $settingsRepository->getOrCreate();
        $settings->setRecruitmentBlockEnabled('1' === $request->getPayload()->get('enabled'));
        $this->entityManager->persist($settings);
        $this->entityManager->flush();
        $this->addFlash('success', $settings->isRecruitmentBlockEnabled() ? 'admin.recruitment_argument.block_on' : 'admin.recruitment_argument.block_off');

        return $this->redirectToRoute('admin_recruitment_argument_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Aperçu du bloc tel qu'il apparaîtra sur l'accueil (arguments marqués « Afficher »), même si le bloc est coupé ;
     * avec ?brouillons=1, tous les arguments, brouillons compris.
     *
     * Liens :
     * - Route : admin_recruitment_argument_preview (/{ADMIN_PATH}/pourquoi-me-recruter/apercu)
     * - Rend : admin/recruitment_argument/preview.html.twig (partiel _partials/_recruitment_arguments.html.twig)
     * - Utilise : RecruitmentArguments::getVisible()
     *
     * @param Request                $request            requête (paramètre brouillons)
     * @param RecruitmentArguments   $arguments          arguments affichés
     * @param SiteSettingsRepository $settingsRepository état de l'interrupteur général
     *
     * @return Response la page d'aperçu
     */
    #[Route('/apercu', name: 'preview', methods: ['GET'])]
    public function preview(Request $request, RecruitmentArguments $arguments, SiteSettingsRepository $settingsRepository): Response
    {
        $withDrafts = '1' === $request->query->get('brouillons');

        return $this->render('admin/recruitment_argument/preview.html.twig', [
            'arguments' => $withDrafts ? $this->getRepository()->findAllOrdered() : $arguments->getVisible(),
            'with_drafts' => $withDrafts,
            'block_enabled' => $settingsRepository->getOrCreate()->isRecruitmentBlockEnabled(),
        ]);
    }

    /** Renvoie la classe de l'entité gérée. */
    protected function getEntityClass(): string
    {
        return RecruitmentArgument::class;
    }

    /** Crée une entité vide pour le formulaire d'ajout (masquée par défaut). */
    protected function createEntity(): SortableInterface
    {
        return new RecruitmentArgument();
    }

    /** Renvoie la classe du formulaire. */
    protected function getFormType(): string
    {
        return RecruitmentArgumentType::class;
    }

    /** Renvoie le préfixe des noms de routes. */
    protected function getRoutePrefix(): string
    {
        return 'admin_recruitment_argument';
    }

    /** Renvoie le préfixe des clés de traduction. */
    protected function getTranslationPrefix(): string
    {
        return 'admin.recruitment_argument';
    }

    /** Décrit les colonnes de la liste. */
    protected function getColumns(): array
    {
        return [
            ['label' => 'admin.recruitment_argument.field.title', 'field' => 'title'],
            ['label' => 'admin.recruitment_argument.column.visible', 'field' => 'visible', 'type' => 'bool'],
        ];
    }

    /**
     * Interrupteur « Afficher / Ne pas afficher » sur chaque ligne (POST avec jeton CSRF).
     *
     * Liens :
     * - Appelée par : AbstractCrudController::getCrudConfig()
     */
    protected function getRowActions(): array
    {
        return [['route' => 'admin_recruitment_argument_toggle', 'label' => 'admin.recruitment_argument.toggle', 'post' => true]];
    }

    /**
     * Bouton « Aperçu » de l'en-tête.
     *
     * Liens :
     * - Appelée par : AbstractCrudController::getCrudConfig()
     */
    protected function getHeaderActions(): array
    {
        return [['route' => 'admin_recruitment_argument_preview', 'label' => 'admin.recruitment_argument.preview']];
    }

    /**
     * Template de la liste : liste générique et interrupteur général du bloc.
     *
     * Liens :
     * - Appelée par : AbstractCrudController::index()
     */
    protected function getIndexTemplate(): string
    {
        return 'admin/recruitment_argument/index.html.twig';
    }

    /**
     * Variables supplémentaires de la liste : état de l'interrupteur général et nombre d'arguments affichés.
     *
     * Liens :
     * - Appelée par : AbstractCrudController::index()
     *
     * @return array<string, mixed>
     */
    protected function getIndexParameters(): array
    {
        $settings = $this->entityManager->getRepository(SiteSettings::class)->findOneBy([], ['id' => 'ASC']);

        return [
            'block_enabled' => null !== $settings && $settings->isRecruitmentBlockEnabled(),
            'visible_count' => \count(array_filter($this->getRepository()->findAllOrdered(), static fn (RecruitmentArgument $argument): bool => $argument->isVisible())),
        ];
    }
}
