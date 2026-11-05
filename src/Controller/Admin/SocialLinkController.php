<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\SocialLink;
use App\Entity\SortableInterface;
use App\Form\SocialLinkType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Communication › Réseaux sociaux (décision du 2026-10-29) : ajouter, modifier, supprimer, réordonner, afficher ou
 * masquer les liens vers les réseaux sociaux, et choisir où chacun s'affiche. Module générique de l'admin
 * (AbstractCrudController) présenté dans les onglets de la page Communication.
 *
 * Liens :
 * - Routes : admin_social_link_index, _new, _edit, _delete, _move, _toggle (/{ADMIN_PATH}/communication/reseaux-sociaux)
 * - Hérite de : AbstractCrudController (ajout, modification, suppression confirmée, ordre)
 * - Rend : templates/admin/communication/social_links.html.twig, templates/admin/communication/social_link_form.html.twig
 * - Utilise : SocialLink, SocialLinkType
 * - Testé par : tests/Controller/Admin/SocialLinkControllerTest, tests/e2e/social-links.spec.js
 *
 * @extends AbstractCrudController<SocialLink>
 */
#[IsGranted('ADMIN_KEY_VERIFIED')]
#[Route('/communication/reseaux-sociaux', name: 'social_link_')]
final class SocialLinkController extends AbstractCrudController
{
    /**
     * Affiche la liste dans l'onglet « Réseaux sociaux » de Communication, avec l'encadré d'explication.
     *
     * Liens :
     * - Route : admin_social_link_index (/{ADMIN_PATH}/communication/reseaux-sociaux)
     * - Rend : templates/admin/communication/social_links.html.twig
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/communication/social_links.html.twig', [
            'tab' => 'social',
            'crud' => $this->getCrudConfig(),
            'entities' => $this->getRepository()->findAllOrdered(),
        ]);
    }

    /**
     * Affiche ou masque un lien d'un clic (POST + jeton CSRF).
     *
     * Liens :
     * - Route : admin_social_link_toggle (/{ADMIN_PATH}/communication/reseaux-sociaux/{id}/visibilite, POST)
     */
    #[Route('/{id}/visibilite', name: 'toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggle(int $id, Request $request): Response
    {
        $link = $this->findEntity($id);
        if ($this->isCsrfTokenValid('admin_social_link_toggle_'.$id, $request->request->getString('_token'))) {
            $link->setVisible(!$link->isVisible());
            $this->entityManager->flush();
            $this->addFlash('success', $link->isVisible() ? 'admin.social_link.flash.shown' : 'admin.social_link.flash.hidden');
        }

        return $this->redirectToRoute('admin_social_link_index', [], Response::HTTP_SEE_OTHER);
    }

    /** Renvoie la classe de l'entité gérée. */
    protected function getEntityClass(): string
    {
        return SocialLink::class;
    }

    /** Crée un lien vide (masqué jusqu'à son activation). */
    protected function createEntity(): SortableInterface
    {
        return new SocialLink();
    }

    /** Renvoie la classe du formulaire. */
    protected function getFormType(): string
    {
        return SocialLinkType::class;
    }

    /** Renvoie le préfixe des noms de routes. */
    protected function getRoutePrefix(): string
    {
        return 'admin_social_link';
    }

    /** Renvoie le préfixe des clés de traduction. */
    protected function getTranslationPrefix(): string
    {
        return 'admin.social_link';
    }

    /** Décrit les colonnes de la liste (gabarit propre à l'onglet : colonnes indicatives). */
    protected function getColumns(): array
    {
        return [
            ['label' => 'admin.social_link.field.network', 'field' => 'displayName'],
            ['label' => 'admin.social_link.field.url', 'field' => 'url'],
        ];
    }

    /** Formulaire dans l'onglet Communication (pleine page). */
    protected function getFormTemplate(): string
    {
        return 'admin/communication/social_link_form.html.twig';
    }

    /** Formulaire en pleine page (pas dans la liste). */
    protected function usesFullPageForm(): bool
    {
        return true;
    }
}
