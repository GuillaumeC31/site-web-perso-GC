<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AiKnowledgeEntry;
use App\Entity\AiKnowledgeRevision;
use App\Entity\UnansweredQuestion;
use App\Enum\AiKnowledgeCategory;
use App\Form\AiKnowledgeEntryType;
use App\Repository\AiKnowledgeEntryRepository;
use App\Repository\AiKnowledgeRevisionRepository;
use App\Repository\ChatMessageRepository;
use App\Repository\UnansweredQuestionRepository;
use App\Service\ActionConfirmation;
use App\Service\Ai\AiKnowledgeBudget;
use App\Service\Ai\AiKnowledgeValidator;
use App\Service\Ai\AiProviderException;
use App\Service\Ai\ChatAssistant;
use App\Service\Ai\ChatContextBuilder;
use App\Service\Ai\ChatTestResult;
use App\Service\Ai\SalaryFloorDetector;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Page « Cerveau de l'IA » de l'admin : ce que l'assistant de l'espace recruteur sait, en trois niveaux
 * (socle de sécurité fixe, résumé automatique du site en lecture seule, fiches modifiables), compteurs de
 * jetons, banc d'essai, questions sans réponse, historique et export Markdown des fiches.
 *
 * Liens :
 * - Routes : admin_ai_knowledge_index, _new, _edit, _delete, _toggle, _history, _export, _test,
 *   _unanswered, _unanswered_ignore (/{ADMIN_PATH}/cerveau-ia)
 * - Rend : templates/admin/ai_knowledge/*.html.twig
 * - Utilise : ChatContextBuilder, AiKnowledgeBudget, AiKnowledgeValidator, ChatAssistant::test(), ActionConfirmation
 */
#[IsGranted('ADMIN_KEY_VERIFIED')]
#[Route('/cerveau-ia', name: 'ai_knowledge_')]
final class AiKnowledgeController extends AbstractController
{
    /**
     * Reçoit les dépendances communes.
     */
    public function __construct(
        private readonly AiKnowledgeEntryRepository $entryRepository,
        private readonly AiKnowledgeBudget $budget,
        private readonly AiKnowledgeValidator $validator,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Affiche la page : socle, résumé du site, fiches par catégorie avec leurs jetons, budget, banc d'essai.
     *
     * Liens :
     * - Route : admin_ai_knowledge_index (/{ADMIN_PATH}/cerveau-ia)
     * - Rend : templates/admin/ai_knowledge/index.html.twig
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(ChatContextBuilder $contextBuilder, UnansweredQuestionRepository $unansweredRepository, ChatMessageRepository $chatMessageRepository, SalaryFloorDetector $floorDetector): Response
    {
        return $this->renderIndex($contextBuilder, $unansweredRepository, $chatMessageRepository, $floorDetector, null, null);
    }

    /**
     * Banc d'essai : pose une question à l'assistant (jetons comptés, hors plafonds des recruteurs, journalisé
     * comme essai admin) et affiche la réponse, les fiches utilisées et l'élément bloquant éventuel.
     *
     * Liens :
     * - Route : admin_ai_knowledge_test (/{ADMIN_PATH}/cerveau-ia/essai, POST, jeton CSRF « ai_knowledge_test »)
     * - Utilise : ChatAssistant::test()
     * - Rend : templates/admin/ai_knowledge/index.html.twig (résultat de l'essai)
     */
    #[Route('/essai', name: 'test', methods: ['POST'])]
    #[IsCsrfTokenValid('ai_knowledge_test')]
    public function test(Request $request, ChatAssistant $assistant, ChatContextBuilder $contextBuilder, UnansweredQuestionRepository $unansweredRepository, ChatMessageRepository $chatMessageRepository, SalaryFloorDetector $floorDetector): Response
    {
        $question = trim($request->request->getString('question'));
        $result = null;
        $error = null;
        if ('' === $question) {
            $error = $this->translator->trans('admin.ai_knowledge.test.empty');
        } else {
            try {
                $result = $assistant->test($question);
            } catch (AiProviderException $exception) {
                $error = $this->translator->trans('admin.ai_knowledge.test.error', ['%reason%' => $exception->getMessage()]);
            }
        }

        return $this->renderIndex($contextBuilder, $unansweredRepository, $chatMessageRepository, $floorDetector, $result, $error, $question);
    }

    /**
     * Crée une fiche (pré-remplie depuis une question sans réponse avec ?question={id}).
     *
     * Liens :
     * - Route : admin_ai_knowledge_new (/{ADMIN_PATH}/cerveau-ia/nouvelle)
     * - Rend : templates/admin/ai_knowledge/form.html.twig
     */
    #[Route('/nouvelle', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, UnansweredQuestionRepository $unansweredRepository): Response
    {
        $entry = (new AiKnowledgeEntry())->setCategory(AiKnowledgeCategory::Faq)->setActive(true);
        $question = $unansweredRepository->find($request->query->getInt('question'));
        if ($question instanceof UnansweredQuestion) {
            $entry->setTitle(mb_strimwidth($question->getQuestion(), 0, 120, '…'));
        }
        $form = $this->createForm(AiKnowledgeEntryType::class, $entry);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid() && $this->passesChecks($form, $entry)) {
            $this->entityManager->persist($entry);
            if ($question instanceof UnansweredQuestion) {
                $question->setStatus(UnansweredQuestion::STATUS_HANDLED);
            }
            $this->entityManager->flush();
            $this->addFlash('success', 'admin.ai_knowledge.flash.created');

            return $this->redirectToRoute('admin_ai_knowledge_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/ai_knowledge/form.html.twig', ['form' => $form, 'entry' => $entry, 'question' => $question], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * Modifie une fiche ; l'ancienne version est gardée dans l'historique si quelque chose change.
     *
     * Liens :
     * - Route : admin_ai_knowledge_edit (/{ADMIN_PATH}/cerveau-ia/{id})
     * - Rend : templates/admin/ai_knowledge/form.html.twig
     */
    #[Route('/{id}', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(AiKnowledgeEntry $entry, Request $request): Response
    {
        $before = $entry->snapshot();
        $form = $this->createForm(AiKnowledgeEntryType::class, $entry);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid() && $this->passesChecks($form, $entry)) {
            if ($entry->snapshot() !== $before) {
                $this->entityManager->persist(new AiKnowledgeRevision($entry, $this->clock->now(), $before));
            }
            $this->entityManager->flush();
            $this->addFlash('success', 'admin.ai_knowledge.flash.saved');

            return $this->redirectToRoute('admin_ai_knowledge_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/ai_knowledge/form.html.twig', ['form' => $form, 'entry' => $entry, 'question' => null], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * Active ou désactive une fiche (bloqué si l'activation dépasse le plafond de taille).
     *
     * Liens :
     * - Route : admin_ai_knowledge_toggle (/{ADMIN_PATH}/cerveau-ia/{id}/activation, POST, jeton CSRF)
     */
    #[Route('/{id}/activation', name: 'toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggle(AiKnowledgeEntry $entry, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('ai_knowledge_toggle_'.$entry->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $before = $entry->snapshot();
        $entry->setActive(!$entry->isActive());
        $exceeded = $this->budget->exceededTotal($entry);
        if (null !== $exceeded) {
            $entry->setActive(false);
            $this->addFlash('warning', $this->translator->trans('admin.ai_knowledge.blocked.limit', ['%total%' => $exceeded, '%limit%' => $this->budget->getSummary()['limit']]));
        } else {
            $this->entityManager->persist(new AiKnowledgeRevision($entry, $this->clock->now(), $before));
            $this->entityManager->flush();
            $this->addFlash('success', $entry->isActive() ? 'admin.ai_knowledge.flash.activated' : 'admin.ai_knowledge.flash.deactivated');
        }

        return $this->redirectToRoute('admin_ai_knowledge_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Supprime une fiche et son historique, après confirmation.
     *
     * Liens :
     * - Route : admin_ai_knowledge_delete (/{ADMIN_PATH}/cerveau-ia/{id}/suppression, POST, jeton CSRF)
     * - Utilise : ActionConfirmation (page de confirmation sans JavaScript)
     */
    #[Route('/{id}/suppression', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(AiKnowledgeEntry $entry, Request $request, ActionConfirmation $confirmation): Response
    {
        if (!$this->isCsrfTokenValid('ai_knowledge_delete_'.$entry->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if (!$confirmation->isConfirmed($request)) {
            return $confirmation->render(
                $request,
                $this->translator->trans('admin.ai_knowledge.confirm_delete', ['%title%' => $entry->getTitle()]),
                $this->translator->trans('admin.ai_knowledge.confirm_delete_text'),
                $this->translator->trans('admin.crud.delete'),
                $this->generateUrl('admin_ai_knowledge_index'),
            );
        }
        $this->entityManager->remove($entry);
        $this->entityManager->flush();
        $this->addFlash('success', 'admin.ai_knowledge.flash.deleted');

        return $this->redirectToRoute('admin_ai_knowledge_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Affiche l'historique d'une fiche (quand, ancien contenu).
     *
     * Liens :
     * - Route : admin_ai_knowledge_history (/{ADMIN_PATH}/cerveau-ia/{id}/historique)
     * - Rend : templates/admin/ai_knowledge/history.html.twig
     */
    #[Route('/{id}/historique', name: 'history', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function history(AiKnowledgeEntry $entry, AiKnowledgeRevisionRepository $revisionRepository): Response
    {
        return $this->render('admin/ai_knowledge/history.html.twig', ['entry' => $entry, 'revisions' => $revisionRepository->findForEntry($entry)]);
    }

    /**
     * Exporte toutes les fiches en Markdown (sauvegarde personnelle), en téléchargement.
     *
     * Liens :
     * - Route : admin_ai_knowledge_export (/{ADMIN_PATH}/cerveau-ia/export.md)
     * - Rend : templates/admin/ai_knowledge/export.md.twig
     */
    #[Route('/export.md', name: 'export', methods: ['GET'])]
    public function export(): Response
    {
        $markdown = $this->renderView('admin/ai_knowledge/export.md.twig', [
            'entries' => $this->entryRepository->findOrdered(),
            'exported_at' => $this->clock->now(),
        ]);
        $response = new Response($markdown, Response::HTTP_OK, ['Content-Type' => 'text/markdown; charset=UTF-8']);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'cerveau-ia-'.$this->clock->now()->format('Y-m-d').'.md'));

        return $response;
    }

    /**
     * Liste les questions sans réponse (à traiter) : « Créer une fiche » ou « Ignorer ».
     *
     * Liens :
     * - Route : admin_ai_knowledge_unanswered (/{ADMIN_PATH}/cerveau-ia/questions-sans-reponse)
     * - Rend : templates/admin/ai_knowledge/unanswered.html.twig
     */
    #[Route('/questions-sans-reponse', name: 'unanswered', methods: ['GET'])]
    public function unanswered(UnansweredQuestionRepository $unansweredRepository): Response
    {
        return $this->render('admin/ai_knowledge/unanswered.html.twig', ['questions' => $unansweredRepository->findPending()]);
    }

    /**
     * Ignore une question sans réponse.
     *
     * Liens :
     * - Route : admin_ai_knowledge_unanswered_ignore (/{ADMIN_PATH}/cerveau-ia/questions-sans-reponse/{id}/ignorer, POST)
     */
    #[Route('/questions-sans-reponse/{id}/ignorer', name: 'unanswered_ignore', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function ignore(UnansweredQuestion $question, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('unanswered_ignore_'.$question->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $question->setStatus(UnansweredQuestion::STATUS_IGNORED);
        $this->entityManager->flush();
        $this->addFlash('info', 'admin.ai_knowledge.flash.ignored');

        return $this->redirectToRoute('admin_ai_knowledge_unanswered', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Contrôles avant l'enregistrement d'une fiche : données interdites (motifs expliqués) et plafond de taille.
     *
     * @param FormInterface<AiKnowledgeEntry> $form formulaire soumis (erreurs ajoutées)
     *
     * @return bool vrai si la fiche peut être enregistrée
     */
    private function passesChecks(FormInterface $form, AiKnowledgeEntry $entry): bool
    {
        $reasons = $this->validator->validate($entry->getTitle()."\n".$entry->getContent());
        foreach ($reasons as $reason) {
            $form->get('content')->addError(new FormError($this->translator->trans($reason)));
        }
        $exceeded = [] === $reasons ? $this->budget->exceededTotal($entry) : null;
        if (null !== $exceeded) {
            $form->get('active')->addError(new FormError($this->translator->trans('admin.ai_knowledge.blocked.limit', ['%total%' => $exceeded, '%limit%' => $this->budget->getSummary()['limit']])));
        }
        if ([] !== $reasons || null !== $exceeded) {
            // Rien n'est enregistré : les changements en mémoire sont abandonnés
            if (null !== $entry->getId()) {
                $this->entityManager->refresh($entry);
            }

            return false;
        }

        return true;
    }

    /**
     * Rend la page principale (avec le résultat d'un essai éventuel).
     */
    private function renderIndex(ChatContextBuilder $contextBuilder, UnansweredQuestionRepository $unansweredRepository, ChatMessageRepository $chatMessageRepository, SalaryFloorDetector $floorDetector, ?ChatTestResult $test, ?string $testError, string $testQuestion = ''): Response
    {
        $entries = $this->entryRepository->findOrdered();
        $grouped = [];
        foreach (AiKnowledgeCategory::cases() as $category) {
            $grouped[$category->value] = ['category' => $category, 'entries' => []];
        }
        foreach ($entries as $entry) {
            $grouped[$entry->getCategory()->value]['entries'][] = $entry;
        }

        return $this->render('admin/ai_knowledge/index.html.twig', [
            'groups' => $grouped,
            'budget' => $this->budget->getSummary(),
            'site_summary' => $contextBuilder->buildSiteSummary(),
            'instructions' => $contextBuilder->buildInstructions('Mistral AI', '{modèle}'),
            'unanswered_count' => $unansweredRepository->countPending(),
            'blocked_count' => $chatMessageRepository->countBlockedSince($this->clock->now()->modify('-7 days')),
            'floor_check' => $floorDetector->isAvailable(),
            'test' => $test,
            'test_error' => $testError,
            'test_question' => $testQuestion,
        ], new Response(null, null === $testError ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
