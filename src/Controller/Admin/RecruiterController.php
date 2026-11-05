<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Recruiter;
use App\Enum\RecruiterStatus;
use App\Form\RecruiterManualType;
use App\Repository\ChatMessageRepository;
use App\Repository\ProjectRepository;
use App\Repository\RecruiterEventRepository;
use App\Repository\RecruiterRepository;
use App\Service\ActionConfirmation;
use App\Service\Ai\AiProviderException;
use App\Service\Ai\CompanySynthesizer;
use App\Service\Content\CvContentProvider;
use App\Service\Recruiter\RecruiterAccessManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Gestion des recruteurs dans l'admin (CDC §9) : demandes à valider ou refuser (score affiché comme
 * aide), liste, création manuelle, révocation, renouvellement, suppression, aperçu « vu par un
 * recruteur » en lecture seule (sans session recruteur ni switch_user), synthèse IA de l'entreprise
 * (à la demande, lot 7).
 *
 * Liens :
 * - Routes : admin_recruiter_index, _show, _new, _decide, _delete, _preview, _synthesize (/{ADMIN_PATH}/recruteurs)
 * - Rend : templates/admin/recruiter/*.html.twig, templates/recruiter_area/home.html.twig (aperçu)
 * - Utilise : RecruiterRepository, RecruiterEventRepository, RecruiterAccessManager, CvContentProvider
 */
#[IsGranted('ADMIN_KEY_VERIFIED')]
#[Route('/recruteurs', name: 'recruiter_')]
final class RecruiterController extends AbstractController
{
    /** Décisions possibles depuis la fiche. */
    private const DECISIONS = ['approve', 'refuse', 'revoke', 'renew'];

    /** Décisions à confirmer (fenêtre, ou page de confirmation sans JavaScript). */
    private const CONFIRMED_DECISIONS = ['approve', 'refuse', 'revoke'];

    /**
     * Liste les recruteurs (demandes en attente d'abord), filtrée par statut.
     *
     * Liens :
     * - Route : admin_recruiter_index (/{ADMIN_PATH}/recruteurs)
     * - Rend : templates/admin/recruiter/index.html.twig
     *
     * @param Request             $request    requête (paramètre statut facultatif)
     * @param RecruiterRepository $repository recruteurs
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, RecruiterRepository $repository): Response
    {
        $status = RecruiterStatus::tryFrom($request->query->getString('statut'));

        return $this->render('admin/recruiter/index.html.twig', [
            'recruiters' => $repository->findForAdmin($status),
            'status' => $status,
            'statuses' => RecruiterStatus::cases(),
        ]);
    }

    /**
     * Affiche la fiche d'un recruteur : demande, score et son détail, accès, signaux d'intérêt, actions.
     *
     * Liens :
     * - Route : admin_recruiter_show (/{ADMIN_PATH}/recruteurs/{id}) ; lien des notifications
     * - Rend : templates/admin/recruiter/show.html.twig
     */
    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Recruiter $recruiter, RecruiterEventRepository $eventRepository, ChatMessageRepository $chatMessageRepository, ClockInterface $clock): Response
    {
        return $this->render('admin/recruiter/show.html.twig', [
            'recruiter' => $recruiter,
            'events' => $eventRepository->findLatestFor($recruiter),
            'has_active_access' => $recruiter->hasActiveAccess($clock->now()),
            'chat_count' => $chatMessageRepository->countForRecruiter($recruiter),
        ]);
    }

    /**
     * Génère (ou régénère) la synthèse IA de l'entreprise du recruteur, puis revient à la fiche.
     *
     * Liens :
     * - Route : admin_recruiter_synthesize (/{ADMIN_PATH}/recruteurs/{id}/synthese, POST, jeton CSRF)
     * - Utilise : CompanySynthesizer::synthesize()
     *
     * @throws BadRequestHttpException si le jeton est invalide
     */
    #[Route('/{id}/synthese', name: 'synthesize', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function synthesize(Recruiter $recruiter, Request $request, CompanySynthesizer $synthesizer, TranslatorInterface $translator): Response
    {
        if (!$this->isCsrfTokenValid('recruiter_synthesize_'.$recruiter->getId(), $request->request->getString('_token'))) {
            throw new BadRequestHttpException('Jeton invalide.');
        }
        try {
            $synthesizer->synthesize($recruiter);
            $this->addFlash('success', 'admin.recruiter.flash.synthesized');
        } catch (AiProviderException $exception) {
            $this->addFlash('warning', $translator->trans('admin.recruiter.flash.synthesis_failed', ['%reason%' => $exception->getMessage()]));
        }

        return $this->redirectToRoute('admin_recruiter_show', ['id' => $recruiter->getId()], Response::HTTP_SEE_OTHER);
    }

    /**
     * Crée un accès à la main (validé tout de suite, e-mail avec un lien de connexion).
     *
     * Liens :
     * - Route : admin_recruiter_new (/{ADMIN_PATH}/recruteurs/nouveau)
     * - Rend : templates/admin/recruiter/new.html.twig
     * - Utilise : RecruiterAccessManager::createManually()
     */
    #[Route('/nouveau', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, RecruiterAccessManager $accessManager, ClockInterface $clock): Response
    {
        $recruiter = new Recruiter($clock->now());
        $form = $this->createForm(RecruiterManualType::class, $recruiter);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $accessManager->createManually($recruiter);
            $this->addFlash('success', 'admin.recruiter.flash.created');

            return $this->redirectToRoute('admin_recruiter_show', ['id' => $recruiter->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/recruiter/new.html.twig', ['form' => $form], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * Applique une décision : valider, refuser, révoquer ou renouveler (jeton CSRF). Valider, refuser et
     * révoquer demandent une confirmation (sans JavaScript : page de confirmation).
     *
     * Liens :
     * - Route : admin_recruiter_decide (/{ADMIN_PATH}/recruteurs/{id}/decision, POST)
     * - Utilise : RecruiterAccessManager::approve(), refuse(), revoke(), renew(), ActionConfirmation
     *
     * @throws BadRequestHttpException si la décision ou le jeton est invalide
     */
    #[Route('/{id}/decision', name: 'decide', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function decide(Recruiter $recruiter, Request $request, RecruiterAccessManager $accessManager, ActionConfirmation $confirmation, TranslatorInterface $translator): Response
    {
        $decision = $request->request->getString('decision');
        if (!\in_array($decision, self::DECISIONS, true) || !$this->isCsrfTokenValid('recruiter_decide_'.$recruiter->getId(), $request->request->getString('_token'))) {
            throw new BadRequestHttpException('Décision invalide.');
        }
        if (\in_array($decision, self::CONFIRMED_DECISIONS, true) && !$confirmation->isConfirmed($request)) {
            return $confirmation->render(
                $request,
                $translator->trans('admin.recruiter.confirm_title.'.$decision, ['%name%' => $recruiter->getFullName()]),
                $translator->trans('admin.recruiter.confirm.'.$decision),
                $translator->trans('admin.recruiter.action.'.$decision),
                $this->generateUrl('admin_recruiter_show', ['id' => $recruiter->getId()]),
                'approve' !== $decision,
            );
        }
        match ($decision) {
            'approve' => $accessManager->approve($recruiter),
            'refuse' => $accessManager->refuse($recruiter),
            'revoke' => $accessManager->revoke($recruiter),
            'renew' => $accessManager->renew($recruiter),
        };
        $this->addFlash('success', 'admin.recruiter.flash.'.$decision);

        return $this->redirectToRoute('admin_recruiter_show', ['id' => $recruiter->getId()], Response::HTTP_SEE_OTHER);
    }

    /**
     * Supprime un recruteur et ses signaux (droit à l'effacement, jeton CSRF), après confirmation (sans
     * JavaScript : page de confirmation).
     *
     * Liens :
     * - Route : admin_recruiter_delete (/{ADMIN_PATH}/recruteurs/{id}/supprimer, POST)
     * - Utilise : ActionConfirmation
     */
    #[Route('/{id}/supprimer', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Recruiter $recruiter, Request $request, EntityManagerInterface $entityManager, ActionConfirmation $confirmation, TranslatorInterface $translator): Response
    {
        $tokenValid = $this->isCsrfTokenValid('recruiter_delete_'.$recruiter->getId(), $request->request->getString('_token'));
        if ($tokenValid && !$confirmation->isConfirmed($request)) {
            return $confirmation->render(
                $request,
                $translator->trans('admin.recruiter.confirm.delete', ['%name%' => $recruiter->getFullName()]),
                $translator->trans('admin.recruiter.confirm.delete_text'),
                $translator->trans('admin.recruiter.action.delete'),
                $this->generateUrl('admin_recruiter_show', ['id' => $recruiter->getId()]),
            );
        }
        if ($tokenValid) {
            $entityManager->remove($recruiter);
            $entityManager->flush();
            $this->addFlash('success', 'admin.recruiter.flash.deleted');
        }

        return $this->redirectToRoute('admin_recruiter_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Aperçu « vu par un recruteur » : la page privée, en lecture seule, sans session recruteur.
     *
     * Liens :
     * - Route : admin_recruiter_preview (/{ADMIN_PATH}/recruteurs/apercu)
     * - Rend : templates/recruiter_area/home.html.twig (preview : bandeau, boutons inactifs)
     * - Utilise : CvContentProvider::getPrivateContent(), ProjectRepository::findRecruiterOnly()
     */
    #[Route('/apercu', name: 'preview', methods: ['GET'])]
    public function preview(CvContentProvider $cvContentProvider, ProjectRepository $projectRepository): Response
    {
        return $this->render('recruiter_area/home.html.twig', $cvContentProvider->getPrivateContent() + [
            'recruiter' => null,
            'projects' => $projectRepository->findRecruiterOnly(),
            'preview' => true,
        ]);
    }
}
