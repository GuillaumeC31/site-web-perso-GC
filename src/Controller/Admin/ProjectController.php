<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Project;
use App\Entity\ProjectImage;
use App\Entity\SortableInterface;
use App\Form\ProjectImportType;
use App\Form\ProjectType;
use App\Repository\ProjectRepository;
use App\Service\Admin\PositionManager;
use App\Service\Admin\ProjectImageStorage;
use App\Service\Content\MarkdownRenderer;
use App\Service\Content\SlugGenerator;
use App\Service\ProjectPackage\ProjectExporter;
use App\Service\ProjectPackage\ProjectImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion des projets dans l'admin : liste, ajout, modification (éditeur Markdown avec
 * aperçu, couverture, galerie), suppression, ordre, aperçu avant publication, export et
 * import d'archives ZIP.
 *
 * Liens :
 * - Routes : admin_project_index, _new, _edit, _delete, _move, _preview, _export, _import,
 *   _markdown_preview (/{ADMIN_PATH}/projets)
 * - Hérite de : AbstractCrudController (actions et liste communes)
 * - Utilise : ProjectType (formulaire), ProjectImageStorage (images WebP), SlugGenerator,
 *   MarkdownRenderer (aperçu), ProjectExporter, ProjectImporter
 * - Rend : admin/project/form.html.twig, admin/project/import.html.twig, project/show.html.twig (aperçu)
 *
 * @extends AbstractCrudController<Project>
 */
#[IsGranted('ADMIN_KEY_VERIFIED')]
#[Route('/projets', name: 'project_')]
final class ProjectController extends AbstractCrudController
{
    /** Jeton CSRF de l'aperçu Markdown (appel JavaScript de l'éditeur). */
    public const MARKDOWN_PREVIEW_TOKEN = 'admin_project_markdown_preview';

    /** @var list<string> images à effacer du disque une fois l'enregistrement réussi */
    private array $filesToRemove = [];

    /**
     * Reçoit les dépendances.
     *
     * @param EntityManagerInterface $entityManager     gestionnaire Doctrine
     * @param PositionManager        $positionManager   calcul de l'ordre d'affichage
     * @param ProjectImageStorage    $imageStorage      conversion et effacement des images
     * @param SlugGenerator          $slugGenerator     slug calculé à partir du titre
     * @param ProjectRepository      $projectRepository slugs déjà pris
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        PositionManager $positionManager,
        private readonly ProjectImageStorage $imageStorage,
        private readonly SlugGenerator $slugGenerator,
        private readonly ProjectRepository $projectRepository,
    ) {
        parent::__construct($entityManager, $positionManager);
    }

    /**
     * Affiche le projet tel qu'il apparaîtra sur le site, même en brouillon (aperçu avant publication).
     *
     * Liens :
     * - Route : admin_project_preview (/{ADMIN_PATH}/projets/{id}/apercu)
     * - Rend : project/show.html.twig (bandeau « aperçu », page non indexée)
     *
     * @param int $id identifiant du projet
     *
     * @return Response la page HTML
     */
    #[Route('/{id}/apercu', name: 'preview', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function preview(int $id): Response
    {
        $response = $this->render('project/show.html.twig', [
            'project' => $this->findEntity($id),
            'is_preview' => true,
        ]);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    /**
     * Télécharge le projet en archive ZIP (format version 2).
     *
     * Liens :
     * - Route : admin_project_export (/{ADMIN_PATH}/projets/{id}/export)
     * - Utilise : ProjectExporter::createArchive()
     *
     * @param int             $id       identifiant du projet
     * @param ProjectExporter $exporter création de l'archive
     *
     * @return BinaryFileResponse l'archive (fichier temporaire supprimé après envoi)
     */
    #[Route('/{id}/export', name: 'export', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function export(int $id, ProjectExporter $exporter): BinaryFileResponse
    {
        $project = $this->findEntity($id);
        $response = new BinaryFileResponse($exporter->createArchive($project));
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $exporter->getDownloadFilename($project));
        $response->headers->set('Content-Type', 'application/zip');
        $response->deleteFileAfterSend();

        return $response;
    }

    /**
     * Importe un projet depuis une archive ZIP (ce site ou l'ancien site) ; il est créé en brouillon.
     *
     * Liens :
     * - Route : admin_project_import (/{ADMIN_PATH}/projets/import)
     * - Utilise : ProjectImportType, ProjectImporter::import()
     * - Rend : admin/project/import.html.twig ; redirige vers le projet importé
     *
     * @param Request         $request  requête (formulaire d'envoi)
     * @param ProjectImporter $importer lecture de l'archive
     *
     * @return Response formulaire, ou redirection vers le projet importé
     */
    #[Route('/import', name: 'import', methods: ['GET', 'POST'])]
    public function import(Request $request, ProjectImporter $importer): Response
    {
        $form = $this->createForm(ProjectImportType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $archive = $form->get('archive')->getData();
            try {
                if (!$archive instanceof UploadedFile) {
                    throw new \InvalidArgumentException('Aucune archive reçue.');
                }
                $result = $importer->import($archive->getPathname());
                $this->addFlash('success', 'admin.project.import.done');
                foreach ($result->warnings as $warning) {
                    $this->addFlash('warning', $warning);
                }

                return $this->redirectToRoute('admin_project_edit', ['id' => $result->project->getId()], Response::HTTP_SEE_OTHER);
            } catch (\InvalidArgumentException $exception) {
                $form->get('archive')->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('admin/project/import.html.twig', ['form' => $form]);
    }

    /**
     * Renvoie l'aperçu HTML d'un texte Markdown (éditeur de l'admin), nettoyé comme sur le site.
     *
     * Liens :
     * - Route : admin_project_markdown_preview (/{ADMIN_PATH}/projets/apercu-markdown), POST + jeton CSRF
     * - Appelée par : assets/controllers/markdown_editor_controller.js
     * - Utilise : MarkdownRenderer::render()
     *
     * @param Request          $request  requête (champs « markdown » et « _token »)
     * @param MarkdownRenderer $renderer conversion Markdown → HTML nettoyé
     *
     * @return Response fragment HTML (403 si le jeton est invalide)
     */
    #[Route('/apercu-markdown', name: 'markdown_preview', methods: ['POST'])]
    public function previewMarkdown(Request $request, MarkdownRenderer $renderer): Response
    {
        if (!$this->isCsrfTokenValid(self::MARKDOWN_PREVIEW_TOKEN, (string) $request->getPayload()->get('_token'))) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $markdown = mb_substr((string) $request->getPayload()->get('markdown'), 0, 50000);

        return new Response($renderer->render($markdown), Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /** Renvoie la classe de l'entité gérée. */
    protected function getEntityClass(): string
    {
        return Project::class;
    }

    /** Crée une entité vide pour le formulaire d'ajout. */
    protected function createEntity(): SortableInterface
    {
        return new Project();
    }

    /** Renvoie la classe du formulaire. */
    protected function getFormType(): string
    {
        return ProjectType::class;
    }

    /** Renvoie le préfixe des noms de routes. */
    protected function getRoutePrefix(): string
    {
        return 'admin_project';
    }

    /** Renvoie le préfixe des clés de traduction. */
    protected function getTranslationPrefix(): string
    {
        return 'admin.project';
    }

    /** Décrit les colonnes de la liste. */
    protected function getColumns(): array
    {
        return [
            ['label' => 'admin.project.field.title', 'field' => 'title'],
            ['label' => 'admin.project.field.type', 'field' => 'type', 'type' => 'enum'],
            ['label' => 'admin.project.field.is_published', 'field' => 'isPublished', 'type' => 'bool'],
            ['label' => 'admin.project.field.visibility', 'field' => 'visibility', 'type' => 'enum'],
            ['label' => 'admin.project.field.is_featured', 'field' => 'isFeatured', 'type' => 'bool'],
            ['label' => 'admin.project.column.sections', 'field' => 'incomplete', 'type' => 'warning'],
        ];
    }

    /** Ajoute l'aperçu et l'export à chaque ligne. */
    protected function getRowActions(): array
    {
        return [
            ['route' => 'admin_project_preview', 'label' => 'admin.project.preview'],
            ['route' => 'admin_project_export', 'label' => 'admin.project.export', 'external' => true],
        ];
    }

    /** Ajoute l'import d'archive en tête de liste. */
    protected function getHeaderActions(): array
    {
        return [['route' => 'admin_project_import', 'label' => 'admin.project.import.title']];
    }

    /** Le formulaire d'un projet est long : il s'ouvre en pleine page. */
    protected function usesFullPageForm(): bool
    {
        return true;
    }

    /** Formulaire dédié (éditeur Markdown, galerie). */
    protected function getFormTemplate(): string
    {
        return 'admin/project/form.html.twig';
    }

    /**
     * Retour au formulaire après enregistrement, pour continuer la saisie.
     *
     * @param Project $entity projet enregistré
     */
    protected function redirectAfterSave(SortableInterface $entity): Response
    {
        return $this->redirectToRoute('admin_project_edit', ['id' => $entity->getId()], Response::HTTP_SEE_OTHER);
    }

    /**
     * Calcule le slug laissé vide et traite les images (couverture, retraits, nouvelles images).
     *
     * Les nouveaux fichiers sont produits ici ; les anciens ne sont effacés qu'après
     * l'enregistrement réussi (afterSave()). En cas d'erreur, les fichiers produits sont
     * effacés, une erreur est ajoutée au formulaire et rien n'est enregistré.
     *
     * Liens :
     * - Appelée par : AbstractCrudController::new(), ::edit()
     * - Utilise : SlugGenerator::unique(), ProjectImageStorage
     *
     * @param Project              $entity projet en cours d'enregistrement
     * @param FormInterface<mixed> $form   formulaire soumis et valide
     */
    protected function beforeSave(SortableInterface $entity, FormInterface $form): bool
    {
        $this->filesToRemove = [];

        if ('' === $entity->getSlug()) {
            $entity->setSlug($this->slugGenerator->unique(
                $entity->getTitle(),
                fn (string $slug): bool => $this->projectRepository->isSlugTaken($slug, $entity->getId()),
            ));
        }

        // Images retirées de la galerie (case « retirer »)
        foreach ($form->get('images') as $imageForm) {
            $image = $imageForm->getData();
            if ($image instanceof ProjectImage && true === $imageForm->get('remove')->getData()) {
                $this->filesToRemove[] = $image->getFilename();
                $entity->removeImage($image);
            }
        }

        $created = [];
        try {
            $cover = $form->get('coverFile')->getData();
            if ($cover instanceof UploadedFile) {
                $filename = $this->imageStorage->storeCover($cover);
                $created[] = $filename;
                $this->scheduleRemoval($entity->getCoverImage());
                $entity->setCoverImage($filename);
            } elseif (true === $form->get('removeCover')->getData()) {
                $this->scheduleRemoval($entity->getCoverImage());
                $entity->setCoverImage(null);
            }

            foreach ((array) $form->get('newImages')->getData() as $upload) {
                if (!$upload instanceof UploadedFile) {
                    continue;
                }
                $stored = $this->imageStorage->storeGalleryImage($upload);
                $created[] = $stored['filename'];
                $entity->addImage((new ProjectImage())->setFilename($stored['filename'])->setDimensions($stored['width'], $stored['height']));
            }
        } catch (\InvalidArgumentException $exception) {
            array_walk($created, $this->imageStorage->remove(...));
            $this->filesToRemove = [];
            $form->addError(new FormError($exception->getMessage()));

            return false;
        }

        return true;
    }

    /**
     * Efface du disque les images remplacées ou retirées, une fois l'enregistrement réussi.
     *
     * @param Project $entity projet enregistré
     */
    protected function afterSave(SortableInterface $entity): void
    {
        array_walk($this->filesToRemove, $this->imageStorage->remove(...));
        $this->filesToRemove = [];
    }

    /**
     * Efface du disque la couverture et la galerie d'un projet supprimé.
     *
     * @param Project $entity projet supprimé
     */
    protected function afterDelete(SortableInterface $entity): void
    {
        $this->imageStorage->remove($entity->getCoverImage());
        foreach ($entity->getImages() as $image) {
            $this->imageStorage->remove($image->getFilename());
        }
    }

    /**
     * Ajoute un fichier à effacer après l'enregistrement (rien si null).
     */
    private function scheduleRemoval(?string $filename): void
    {
        if (null !== $filename) {
            $this->filesToRemove[] = $filename;
        }
    }
}
