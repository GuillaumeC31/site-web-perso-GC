<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\SortableInterface;
use App\Repository\AbstractSortableRepository;
use App\Service\ActionConfirmation;
use App\Service\Admin\PositionManager;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Écrans de gestion communs (liste, ajout, modification, suppression, ordre) des contenus du CV.
 *
 * Chaque module (expériences, compétences…) hérite de cette classe et ne déclare que
 * son entité, son formulaire, ses colonnes et son préfixe de route. Les routes sont
 * définies ici et préfixées par l'attribut #[Route] de la classe fille.
 *
 * Ergonomie (CDC §9) : formulaires dans une Turbo Frame (affichage sur la page de liste),
 * confirmation avant suppression (fenêtre « notify », page ActionConfirmation sans JavaScript), messages flash, jetons CSRF.
 *
 * Points d'extension (redéfinis par les modules qui en ont besoin, ex. ProjectController) :
 * beforeSave() (fichiers envoyés, slug), afterSave() et afterDelete() (fichiers à effacer),
 * redirectAfterSave(), getFormTemplate(), getRowActions(), getHeaderActions(), usesFullPageForm(),
 * getIndexTemplate() et getIndexParameters() (liste enrichie, ex. RecruitmentArgumentController).
 *
 * Liens :
 * - Étendue par : ExperienceController, SkillController, SkillPillarController, EducationController,
 *   CertificationController, LanguageController, KeyFigureController, ProjectController,
 *   ProjectCategoryController, RecruitmentArgumentController
 * - Utilise : PositionManager, AbstractSortableRepository
 * - Rend : templates/admin/crud/index.html.twig, templates/admin/crud/form.html.twig (ou getFormTemplate())
 *
 * @template T of SortableInterface
 */
abstract class AbstractCrudController extends AbstractController
{
    /**
     * Reçoit les dépendances communes.
     *
     * @param EntityManagerInterface $entityManager   gestionnaire Doctrine
     * @param PositionManager        $positionManager calcul de l'ordre d'affichage
     */
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly PositionManager $positionManager,
    ) {
    }

    /**
     * Renvoie la classe de l'entité gérée.
     *
     * @return class-string<T>
     */
    abstract protected function getEntityClass(): string;

    /**
     * Crée une entité vide pour le formulaire d'ajout.
     *
     * @return T
     */
    abstract protected function createEntity(): SortableInterface;

    /**
     * Renvoie la classe du formulaire.
     *
     * @return class-string<FormTypeInterface<T>>
     */
    abstract protected function getFormType(): string;

    /** Renvoie le préfixe des noms de routes, ex. « admin_experience ». */
    abstract protected function getRoutePrefix(): string;

    /** Renvoie le préfixe des clés de traduction, ex. « admin.experience ». */
    abstract protected function getTranslationPrefix(): string;

    /**
     * Décrit les colonnes de la liste.
     *
     * Chaque colonne : label (clé de traduction), field (chemin de propriété, ex. « pillar.name »),
     * type (text, date_month, date, bool, count, enum, warning).
     *
     * @return list<array{label: string, field: string, type?: string}>
     */
    abstract protected function getColumns(): array;

    /**
     * Affiche la liste, dans l'ordre d'affichage.
     *
     * Liens :
     * - Route : {prefixe}_index (ex. admin_experience_index)
     * - Rend : admin/crud/index.html.twig (ou getIndexTemplate(), variables de getIndexParameters())
     *
     * @return Response la page HTML
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render($this->getIndexTemplate(), [
            'crud' => $this->getCrudConfig(),
            'entities' => $this->getRepository()->findAllOrdered(),
        ] + $this->getIndexParameters());
    }

    /**
     * Renvoie le template de la liste (par défaut : la liste générique).
     *
     * Liens :
     * - Appelée par : index()
     * - Redéfinie par : RecruitmentArgumentController (interrupteur général du bloc)
     */
    protected function getIndexTemplate(): string
    {
        return 'admin/crud/index.html.twig';
    }

    /**
     * Renvoie les variables supplémentaires de la liste (par défaut : aucune).
     *
     * Liens :
     * - Appelée par : index()
     * - Redéfinie par : RecruitmentArgumentController
     *
     * @return array<string, mixed>
     */
    protected function getIndexParameters(): array
    {
        return [];
    }

    /**
     * Affiche et traite le formulaire d'ajout ; la nouvelle ligne va en dernière position.
     *
     * Liens :
     * - Route : {prefixe}_new (ex. admin_experience_new)
     * - Utilise : PositionManager::placeLast()
     * - Rend : admin/crud/form.html.twig (code 422 si le formulaire est invalide)
     *
     * @param Request $request requête (formulaire soumis)
     *
     * @return Response formulaire, ou redirection vers la liste après enregistrement
     */
    #[Route('/nouveau', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $entity = $this->createEntity();
        // Action explicite : le formulaire s'ouvre dans la Turbo Frame de la liste, et un <form> sans action
        // s'enverrait à l'adresse de la page (la liste, GET seulement : 405, « Content missing », rien d'enregistré)
        $form = $this->createForm($this->getFormType(), $entity, ['action' => $this->generateUrl($this->getRoutePrefix().'_new')]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->beforeSave($entity, $form)) {
            $this->positionManager->placeLast($entity, $this->getRepository());
            $this->entityManager->persist($entity);
            $this->entityManager->flush();
            $this->afterSave($entity);
            $this->addFlash('success', 'admin.crud.created');

            return $this->redirectAfterSave($entity);
        }

        return $this->render($this->getFormTemplate(), [
            'crud' => $this->getCrudConfig(),
            'form' => $form,
            'entity' => $entity,
        ]);
    }

    /**
     * Affiche et traite le formulaire de modification.
     *
     * Liens :
     * - Route : {prefixe}_edit (ex. admin_experience_edit)
     * - Rend : admin/crud/form.html.twig (code 422 si le formulaire est invalide)
     *
     * @param int     $id      identifiant de la ligne
     * @param Request $request requête (formulaire soumis)
     *
     * @return Response formulaire, ou redirection vers la liste après enregistrement
     *
     * @throws NotFoundHttpException si la ligne n'existe pas
     */
    #[Route('/{id}/modifier', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $entity = $this->findEntity($id);
        // Action explicite (voir new()) : bug du 05/11/2026, icône de pilier jamais enregistrée
        $form = $this->createForm($this->getFormType(), $entity, ['action' => $this->generateUrl($this->getRoutePrefix().'_edit', ['id' => $id])]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->beforeSave($entity, $form)) {
            $this->entityManager->flush();
            $this->afterSave($entity);
            $this->addFlash('success', 'admin.crud.updated');

            return $this->redirectAfterSave($entity);
        }

        return $this->render($this->getFormTemplate(), [
            'crud' => $this->getCrudConfig(),
            'form' => $form,
            'entity' => $entity,
        ]);
    }

    /**
     * Supprime une ligne (POST + jeton CSRF ; confirmation demandée côté navigateur).
     *
     * Une ligne encore utilisée ailleurs (ex. pilier qui contient des compétences) n'est pas
     * supprimée : un message l'explique.
     *
     * Liens :
     * - Route : {prefixe}_delete (ex. admin_experience_delete)
     *
     * @param int                 $id           identifiant de la ligne
     * @param Request             $request      requête (jeton CSRF)
     * @param ActionConfirmation  $confirmation confirmation (page du serveur sans JavaScript)
     * @param TranslatorInterface $translator   textes de la confirmation
     *
     * @return Response redirection vers la liste, ou page de confirmation
     *
     * @throws NotFoundHttpException si la ligne n'existe pas
     */
    #[Route('/{id}/suppression', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request, ActionConfirmation $confirmation, TranslatorInterface $translator): Response
    {
        $entity = $this->findEntity($id);

        if (!$this->isCsrfTokenValid($this->getRoutePrefix().'_delete_'.$id, (string) $request->getPayload()->get('_token'))) {
            $this->addFlash('danger', 'admin.crud.invalid_csrf');

            return $this->redirectToRoute($this->getRoutePrefix().'_index', [], Response::HTTP_SEE_OTHER);
        }

        if (!$this->canDelete($entity)) {
            $this->addFlash('warning', $this->getTranslationPrefix().'.delete_in_use');

            return $this->redirectToRoute($this->getRoutePrefix().'_index', [], Response::HTTP_SEE_OTHER);
        }
        if (!$confirmation->isConfirmed($request)) {
            return $confirmation->render(
                $request,
                $translator->trans('admin.crud.delete_confirm', ['%name%' => $entity instanceof \Stringable ? (string) $entity : '']),
                '',
                $translator->trans('admin.crud.delete'),
                $this->generateUrl($this->getRoutePrefix().'_index'),
            );
        }

        try {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
            $this->afterDelete($entity);
            $this->addFlash('success', 'admin.crud.deleted');
        } catch (ForeignKeyConstraintViolationException) {
            // Filet de sécurité si canDelete() a laissé passer une ligne encore référencée
            // (contrainte RESTRICT en base) : rien n'est supprimé.
            $this->addFlash('warning', $this->getTranslationPrefix().'.delete_in_use');
        }

        return $this->redirectToRoute($this->getRoutePrefix().'_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Monte ou descend une ligne d'un rang (POST + jeton CSRF).
     *
     * Liens :
     * - Route : {prefixe}_move (ex. admin_experience_move)
     * - Utilise : PositionManager::move()
     *
     * @param int     $id        identifiant de la ligne
     * @param string  $direction « haut » ou « bas »
     * @param Request $request   requête (jeton CSRF)
     *
     * @return Response redirection vers la liste
     *
     * @throws NotFoundHttpException si la ligne n'existe pas
     */
    #[Route('/{id}/deplacer/{direction}', name: 'move', requirements: ['id' => '\d+', 'direction' => 'haut|bas'], methods: ['POST'])]
    public function move(int $id, string $direction, Request $request): Response
    {
        $entity = $this->findEntity($id);

        if ($this->isCsrfTokenValid($this->getRoutePrefix().'_move_'.$id, (string) $request->getPayload()->get('_token'))
            && $this->positionManager->move($entity, $this->getRepository(), 'haut' === $direction)) {
            $this->entityManager->flush();
        }

        return $this->redirectToRoute($this->getRoutePrefix().'_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Indique si une ligne peut être supprimée (par défaut : oui).
     *
     * Liens :
     * - Appelée par : delete()
     * - Redéfinie par : SkillPillarController (pilier qui contient des compétences)
     *
     * @param T $entity ligne à supprimer
     */
    protected function canDelete(SortableInterface $entity): bool
    {
        return true;
    }

    /**
     * Traitement complémentaire d'un formulaire valide, avant l'enregistrement (par défaut : rien).
     *
     * Peut ajouter une erreur au formulaire et renvoyer faux : rien n'est alors enregistré
     * et le formulaire est réaffiché avec l'erreur (code 422).
     *
     * Liens :
     * - Appelée par : new(), edit()
     * - Redéfinie par : ProjectController (images envoyées, slug), ProjectCategoryController (slug)
     *
     * @param T                    $entity ligne en cours d'enregistrement
     * @param FormInterface<mixed> $form   formulaire soumis et valide
     *
     * @return bool vrai pour enregistrer
     */
    protected function beforeSave(SortableInterface $entity, FormInterface $form): bool
    {
        return true;
    }

    /**
     * Traitement après l'enregistrement en base (par défaut : rien).
     *
     * Liens :
     * - Appelée par : new(), edit()
     * - Redéfinie par : ProjectController (effacement des images remplacées)
     *
     * @param T $entity ligne enregistrée
     */
    protected function afterSave(SortableInterface $entity): void
    {
    }

    /**
     * Traitement après la suppression en base (par défaut : rien).
     *
     * Liens :
     * - Appelée par : delete()
     * - Redéfinie par : ProjectController (effacement des images du projet)
     *
     * @param T $entity ligne supprimée
     */
    protected function afterDelete(SortableInterface $entity): void
    {
    }

    /**
     * Renvoie la redirection après un enregistrement réussi (par défaut : la liste).
     *
     * Liens :
     * - Appelée par : new(), edit()
     * - Redéfinie par : ProjectController (retour au formulaire, pour continuer la saisie)
     *
     * @param T $entity ligne enregistrée
     */
    protected function redirectAfterSave(SortableInterface $entity): Response
    {
        return $this->redirectToRoute($this->getRoutePrefix().'_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Renvoie le template du formulaire (par défaut : le formulaire générique).
     *
     * Liens :
     * - Appelée par : new(), edit()
     * - Redéfinie par : ProjectController (éditeur Markdown, galerie)
     */
    protected function getFormTemplate(): string
    {
        return 'admin/crud/form.html.twig';
    }

    /**
     * Décrit les actions supplémentaires de chaque ligne de la liste (par défaut : aucune).
     *
     * Chaque action : route (paramètre id ajouté), label (clé de traduction), external (vrai :
     * lien hors Turbo, ex. téléchargement ou aperçu dans un nouvel onglet), post (vrai : petit formulaire POST avec
     * le jeton CSRF « {route}_{id} », ex. interrupteur « Afficher »).
     *
     * Liens :
     * - Appelée par : getCrudConfig()
     * - Redéfinie par : ProjectController (aperçu, export)
     *
     * @return list<array{route: string, label: string, external?: bool, post?: bool}>
     */
    protected function getRowActions(): array
    {
        return [];
    }

    /**
     * Décrit les actions supplémentaires de l'en-tête de la liste (par défaut : aucune).
     *
     * Liens :
     * - Appelée par : getCrudConfig()
     * - Redéfinie par : ProjectController (import d'une archive)
     *
     * @return list<array{route: string, label: string}>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * Indique si le formulaire s'ouvre en pleine page plutôt que dans la liste (par défaut : non).
     *
     * Liens :
     * - Appelée par : getCrudConfig()
     * - Redéfinie par : ProjectController (formulaire long)
     */
    protected function usesFullPageForm(): bool
    {
        return false;
    }

    /**
     * Renvoie la configuration transmise aux templates génériques.
     *
     * @return array{route_prefix: string, translation_prefix: string, columns: list<array{label: string, field: string, type?: string}>, row_actions: list<array{route: string, label: string, external?: bool, post?: bool}>, header_actions: list<array{route: string, label: string}>, full_page_form: bool}
     */
    protected function getCrudConfig(): array
    {
        return [
            'route_prefix' => $this->getRoutePrefix(),
            'translation_prefix' => $this->getTranslationPrefix(),
            'columns' => $this->getColumns(),
            'row_actions' => $this->getRowActions(),
            'header_actions' => $this->getHeaderActions(),
            'full_page_form' => $this->usesFullPageForm(),
        ];
    }

    /**
     * Renvoie le dépôt de l'entité gérée.
     *
     * @return AbstractSortableRepository<T>
     *
     * @throws \LogicException si le dépôt n'est pas triable
     */
    protected function getRepository(): AbstractSortableRepository
    {
        $repository = $this->entityManager->getRepository($this->getEntityClass());
        if (!$repository instanceof AbstractSortableRepository) {
            throw new \LogicException(\sprintf('Le dépôt de %s doit étendre AbstractSortableRepository.', $this->getEntityClass()));
        }

        /* @var AbstractSortableRepository<T> $repository */
        return $repository;
    }

    /**
     * Charge une ligne par son identifiant.
     *
     * @return T
     *
     * @throws NotFoundHttpException si la ligne n'existe pas
     */
    protected function findEntity(int $id): SortableInterface
    {
        $entity = $this->getRepository()->find($id);

        return $entity ?? throw $this->createNotFoundException();
    }
}
