<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ChatMessage;
use App\Repository\ChatMessageRepository;
use App\Service\Ai\AiProviderException;
use App\Service\Ai\AiStatus;
use App\Service\Ai\CvImportAnalyzer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Assistant IA dans l'admin : état (actif, modèle de secours, assistant guidé, plafond atteint),
 * consommation du jour et du mois, modèles configurés (vérification « modèle retiré »), journal des
 * conversations (filtre « suspects »), détail d'un échange, import de CV par l'IA (aperçu en lecture seule).
 *
 * Liens :
 * - Routes : admin_assistant_index, _refresh_models, _show, _cv_import (/{ADMIN_PATH}/assistant-ia)
 * - Rend : templates/admin/assistant/*.html.twig
 * - Utilise : AiStatus, ChatMessageRepository, CvImportAnalyzer
 */
#[IsGranted('ADMIN_KEY_VERIFIED')]
#[Route('/assistant-ia', name: 'assistant_')]
final class AssistantController extends AbstractController
{
    /** Taille maximale d'un CV envoyé en PDF (octets). */
    private const MAX_PDF_SIZE = 5 * 1024 * 1024;

    /**
     * Affiche l'état, la consommation, les modèles et le journal des conversations.
     *
     * Liens :
     * - Route : admin_assistant_index (/{ADMIN_PATH}/assistant-ia, ?suspects=1 : échanges suspects seulement)
     * - Rend : templates/admin/assistant/index.html.twig
     *
     * @param Request $request requête (filtre « suspects »)
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, AiStatus $aiStatus, ChatMessageRepository $chatMessageRepository): Response
    {
        $suspiciousOnly = $request->query->getBoolean('suspects');

        return $this->render('admin/assistant/index.html.twig', [
            'status' => $aiStatus->getSummary(),
            'messages' => $chatMessageRepository->findForAdmin($suspiciousOnly),
            'suspicious_only' => $suspiciousOnly,
        ]);
    }

    /**
     * Relit la liste des modèles chez Mistral AI (alerte « modèle retiré »), puis revient à la page.
     *
     * Liens :
     * - Route : admin_assistant_refresh_models (/{ADMIN_PATH}/assistant-ia/modeles, POST, jeton CSRF)
     * - Utilise : AiStatus::getSummary(true)
     */
    #[Route('/modeles', name: 'refresh_models', methods: ['POST'])]
    #[IsCsrfTokenValid('admin_assistant_refresh')]
    public function refreshModels(AiStatus $aiStatus, TranslatorInterface $translator): Response
    {
        $alerts = $aiStatus->getSummary(true)['model_alerts'];
        $this->addFlash([] === $alerts ? 'success' : 'warning', $translator->trans([] === $alerts ? 'admin.assistant.flash.models_ok' : 'admin.assistant.flash.models_alert'));

        return $this->redirectToRoute('admin_assistant_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Affiche un échange du journal (question, réponse, modèle, jetons, erreur, motif de suspicion).
     *
     * Liens :
     * - Route : admin_assistant_show (/{ADMIN_PATH}/assistant-ia/echanges/{id})
     * - Rend : templates/admin/assistant/show.html.twig
     */
    #[Route('/echanges/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(ChatMessage $message): Response
    {
        return $this->render('admin/assistant/show.html.twig', ['message' => $message]);
    }

    /**
     * Import de CV par l'IA : PDF ou texte collé, données personnelles retirées avant l'envoi ; affiche
     * l'aperçu des informations extraites. Rien n'est enregistré.
     *
     * Liens :
     * - Route : admin_assistant_cv_import (/{ADMIN_PATH}/assistant-ia/import-cv, GET et POST, jeton CSRF)
     * - Rend : templates/admin/assistant/cv_import.html.twig
     * - Utilise : CvImportAnalyzer::extractPdfText(), CvImportAnalyzer::analyze()
     *
     * @param Request $request requête (fichier « cv_file » ou texte « cv_text »)
     */
    #[Route('/import-cv', name: 'cv_import', methods: ['GET', 'POST'])]
    public function cvImport(Request $request, CvImportAnalyzer $analyzer, TranslatorInterface $translator): Response
    {
        $result = null;
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_assistant_cv_import', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }
            $text = trim($request->request->getString('cv_text'));
            $file = $request->files->get('cv_file');
            try {
                if ($file instanceof UploadedFile) {
                    if (!$file->isValid() || $file->getSize() > self::MAX_PDF_SIZE || 'application/pdf' !== $file->getMimeType()) {
                        throw new \RuntimeException($translator->trans('admin.assistant.cv_import.invalid_file'));
                    }
                    $text = $analyzer->extractPdfText($file->getPathname());
                }
                if ('' === trim($text)) {
                    throw new \RuntimeException($translator->trans('admin.assistant.cv_import.empty'));
                }
                $result = $analyzer->analyze($text);
            } catch (AiProviderException $exception) {
                $error = $translator->trans('admin.assistant.cv_import.ai_error', ['%reason%' => $exception->getMessage()]);
            } catch (\RuntimeException $exception) {
                $error = $exception->getMessage();
            }
        }

        return $this->render('admin/assistant/cv_import.html.twig', ['result' => $result, 'error' => $error], new Response(null, null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
