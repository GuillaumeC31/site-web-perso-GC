<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ChatMessage;
use App\Entity\Recruiter;
use App\Enum\ChatOutcome;
use App\Enum\RecruiterEventType;
use App\Enum\SecurityEventType;
use App\Form\LoginLinkRequestType;
use App\Form\RecruiterRequestType;
use App\Repository\ChatMessageRepository;
use App\Repository\ProjectRepository;
use App\Repository\RecruiterRepository;
use App\Repository\SiteSettingsRepository;
use App\Security\SecurityEventLogger;
use App\Security\TurnstileVerifier;
use App\Service\ActionConfirmation;
use App\Service\Ai\AiQuota;
use App\Service\Ai\ChatAssistant;
use App\Service\Ai\GuidedAssistant;
use App\Service\Content\CvContentProvider;
use App\Service\Cv\CvPdfRenderer;
use App\Service\Recruiter\InterestSignalNotifier;
use App\Service\Recruiter\RecruiterAccessManager;
use App\Service\Recruiter\RecruiterDataExporter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Espace recruteur (CDC §7, TEXTES_SITE § 11).
 *
 * Public : demande d'accès, demande d'un lien de connexion (toujours la même réponse, que l'adresse
 * soit connue ou non), confirmation du lien (bouton POST : les scanners de liens des messageries ne
 * le consomment pas), lien invalide, demande de prolongation et refus des rappels (liens signés de l'e-mail de rappel).
 * Privé (ROLE_RECRUITER, accès validé et non échu) : coordonnées directes, CV complet (PDF), vrais
 * noms d'entreprises, projets réservés aux recruteurs, prétentions si cochées, « Je suis intéressé »,
 * assistant (questions guidées sans IA, questions libres à l'IA Mistral après consentement), suppression
 * de ses données par le recruteur (page de confirmation, puis déconnexion), téléchargement de ses données (JSON).
 *
 * Liens :
 * - Routes : recruiter_access (/espace-recruteur), recruiter_login_check (/espace-recruteur/connexion/verification),
 *   recruiter_link_invalid, recruiter_extension, recruiter_home (/espace-recruteur/accueil), recruiter_cv_pdf,
 *   recruiter_project, recruiter_interest, recruiter_assistant_guided, recruiter_assistant_ask, recruiter_data, recruiter_data_export, recruiter_data_delete,
 *   recruiter_reminders_stop, recruiter_logout (pare-feu)
 * - Rend : templates/recruiter_area/*.html.twig
 * - Utilise : RecruiterAccessManager, InterestSignalNotifier, CvContentProvider, CvPdfRenderer, TurnstileVerifier,
 *   GuidedAssistant, ChatAssistant, AiQuota
 */
final class RecruiterAreaController extends AbstractController
{
    /**
     * Reçoit les dépendances communes.
     *
     * @param RecruiterAccessManager $accessManager      cycle de vie des accès
     * @param SiteSettingsRepository $settingsRepository délai de réponse affiché
     * @param TranslatorInterface    $translator         messages
     * @param SecurityEventLogger    $securityLogger     limites atteintes (tableau de bord, tuile Sécurité)
     */
    public function __construct(
        private readonly RecruiterAccessManager $accessManager,
        private readonly SiteSettingsRepository $settingsRepository,
        private readonly TranslatorInterface $translator,
        private readonly SecurityEventLogger $securityLogger,
    ) {
    }

    /**
     * Page publique : demande d'accès et demande d'un lien de connexion (deux formulaires).
     *
     * Liens :
     * - Route : recruiter_access (/espace-recruteur, GET et POST)
     * - Rend : templates/recruiter_area/index.html.twig (statut 422 si un formulaire est refusé)
     *
     * @param Request                     $request                   requête
     * @param TurnstileVerifier           $turnstile                 vérification anti-robot
     * @param RateLimiterFactoryInterface $recruiterRequestLimiter   limiteur des demandes (3 par heure et par IP)
     * @param RateLimiterFactoryInterface $recruiterLoginLinkLimiter limiteur des liens (3 par heure, par IP et par adresse)
     *
     * @return Response la page, ou la redirection vers la confirmation
     */
    #[Route('/espace-recruteur', name: 'recruiter_access', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        TurnstileVerifier $turnstile,
        #[Autowire(service: 'limiter.recruiter_request')]
        RateLimiterFactoryInterface $recruiterRequestLimiter,
        #[Autowire(service: 'limiter.recruiter_login_link')]
        RateLimiterFactoryInterface $recruiterLoginLinkLimiter,
        ClockInterface $clock,
    ): Response {
        if ($this->isGranted('ROLE_RECRUITER')) {
            return $this->redirectToRoute('recruiter_home');
        }
        $requestForm = $this->createForm(RecruiterRequestType::class, new Recruiter($clock->now()));
        $linkForm = $this->container->get('form.factory')->createNamed('login_link', LoginLinkRequestType::class);
        $requestForm->handleRequest($request);
        $linkForm->handleRequest($request);
        $ip = (string) $request->getClientIp();

        if ($requestForm->isSubmitted() && $requestForm->isValid() && $this->passesChecks($requestForm, $request, $turnstile, [$recruiterRequestLimiter->create($ip)], 'recruiter_request')) {
            if ('' === (string) $requestForm->get('website')->getData()) {
                /** @var Recruiter $data */
                $data = $requestForm->getData();
                $this->accessManager->requestAccess($data, $request->getClientIp());
            }
            $this->addFlash('success', $this->translator->trans('recruiter_area.request_confirmation', ['%delay%' => $this->settingsRepository->getOrCreate()->getResponseDelay()]));

            return $this->redirectToRoute('recruiter_access', [], Response::HTTP_SEE_OTHER);
        }

        if ($linkForm->isSubmitted() && $linkForm->isValid()) {
            $email = mb_strtolower(trim((string) $linkForm->get('email')->getData()));
            if ($this->passesChecks($linkForm, $request, $turnstile, [$recruiterLoginLinkLimiter->create($ip), $recruiterLoginLinkLimiter->create('email-'.hash('sha256', $email))], 'recruiter_login_link')) {
                if ('' === (string) $linkForm->get('website')->getData()) {
                    $this->accessManager->sendLoginLink($email);
                }
                $this->addFlash('success', $this->translator->trans('recruiter_area.link_confirmation'));

                return $this->redirectToRoute('recruiter_access', [], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('recruiter_area/index.html.twig', [
            'request_form' => $requestForm,
            'link_form' => $linkForm,
            'response_delay' => $this->settingsRepository->getOrCreate()->getResponseDelay(),
        ], new Response(null, $requestForm->isSubmitted() || $linkForm->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * Page de confirmation du lien de connexion (GET) : bouton « Se connecter » qui envoie le lien en
     * POST au pare-feu (check_post_only). Le POST est traité par le composant Security.
     *
     * Liens :
     * - Route : recruiter_login_check (/espace-recruteur/connexion/verification)
     * - Rend : templates/recruiter_area/login_check.html.twig
     *
     * @param Request $request requête (paramètres signés du lien : user, expires, hash)
     */
    #[Route('/espace-recruteur/connexion/verification', name: 'recruiter_login_check', methods: ['GET', 'POST'])]
    public function loginCheck(Request $request): Response
    {
        return $this->render('recruiter_area/login_check.html.twig', [
            'query' => $request->query->all(),
        ]);
    }

    /**
     * Lien de connexion invalide, expiré ou déjà utilisé, ou accès fermé : explication et nouveau lien.
     *
     * Liens :
     * - Route : recruiter_link_invalid (/espace-recruteur/lien-invalide) ; failure_path du pare-feu
     * - Rend : templates/recruiter_area/link_invalid.html.twig
     */
    #[Route('/espace-recruteur/lien-invalide', name: 'recruiter_link_invalid', methods: ['GET'])]
    public function linkInvalid(): Response
    {
        $linkForm = $this->container->get('form.factory')->createNamed('login_link', LoginLinkRequestType::class, null, [
            'action' => $this->generateUrl('recruiter_access'),
        ]);

        return $this->render('recruiter_area/link_invalid.html.twig', ['link_form' => $linkForm]);
    }

    /**
     * Demande de prolongation depuis l'e-mail de rappel (lien signé, valable 14 jours) : page de
     * confirmation (GET), puis enregistrement et notification (POST).
     *
     * Liens :
     * - Route : recruiter_extension (/espace-recruteur/prolongation/{id})
     * - Rend : templates/recruiter_area/extension.html.twig
     * - Utilise : RecruiterAccessManager::requestExtension()
     *
     * @param Request $request requête (signature _hash et expiration _expiration)
     *
     * @throws NotFoundHttpException si le lien est faux ou expiré, ou le recruteur inconnu
     */
    #[Route('/espace-recruteur/prolongation/{id}', name: 'recruiter_extension', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function extension(int $id, Request $request, UriSigner $uriSigner, RecruiterRepository $recruiterRepository): Response
    {
        $recruiter = $recruiterRepository->find($id);
        $signedUrl = $request->isMethod('POST') ? $request->request->getString('signed_url') : $request->getUri();
        if (null === $recruiter || !$uriSigner->check($signedUrl) || !str_contains($signedUrl, '/espace-recruteur/prolongation/'.$id.'?')) {
            throw $this->createNotFoundException();
        }
        $done = false;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('recruiter_extension_'.$id, $request->request->getString('_token'))) {
                throw $this->createNotFoundException();
            }
            $this->accessManager->requestExtension($recruiter);
            $done = true;
        }

        return $this->render('recruiter_area/extension.html.twig', [
            'recruiter_id' => $id, 'signed_url' => $signedUrl, 'done' => $done,
            'expires_at' => $recruiter->getAccessExpiresAt(),
            'response_delay' => $this->settingsRepository->getOrCreate()->getResponseDelay(),
        ]);
    }

    /**
     * Refus des rappels d'échéance depuis l'e-mail de rappel (lien signé, sans date limite ; RGPD) : page de confirmation
     * (GET, pour qu'un scanner de liens ne déclenche pas le refus), puis refus enregistré avec effet immédiat (POST, aussi
     * envoyé directement par les messageries grâce à l'en-tête List-Unsubscribe-Post, RFC 8058). Le formulaire est envoyé
     * à l'adresse signée elle-même : la signature tient lieu de jeton, sans session.
     *
     * Liens :
     * - Route : recruiter_reminders_stop (/espace-recruteur/rappels/{id})
     * - Rend : templates/recruiter_area/reminders.html.twig
     * - Utilise : Recruiter::setRemindersStoppedAt()
     * - Voir aussi : RecruiterDailyCommand (n'envoie plus de rappel), RecruiterMailer::sendExpiryReminder()
     *
     * @param Request $request requête (signature _hash)
     *
     * @throws NotFoundHttpException si le lien est faux ou le recruteur inconnu
     */
    #[Route('/espace-recruteur/rappels/{id}', name: 'recruiter_reminders_stop', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function stopReminders(int $id, Request $request, UriSigner $uriSigner, RecruiterRepository $recruiterRepository, EntityManagerInterface $entityManager, ClockInterface $clock): Response
    {
        $recruiter = $recruiterRepository->find($id);
        if (null === $recruiter || !$uriSigner->checkRequest($request)) {
            throw $this->createNotFoundException();
        }
        $done = false;
        if ($request->isMethod('POST')) {
            if ($recruiter->acceptsReminders()) {
                $recruiter->setRemindersStoppedAt($clock->now());
                $entityManager->flush();
            }
            $done = true;
        }

        return $this->render('recruiter_area/reminders.html.twig', ['signed_url' => $request->getUri(), 'done' => $done]);
    }

    /**
     * Accueil de l'espace privé : coordonnées, CV complet, projets réservés, « Je suis intéressé », assistant.
     *
     * Liens :
     * - Route : recruiter_home (/espace-recruteur/accueil)
     * - Rend : templates/recruiter_area/home.html.twig (assistant : templates/recruiter_area/_assistant.html.twig)
     * - Utilise : CvContentProvider::getPrivateContent(), ProjectRepository::findRecruiterOnly(),
     *   ChatMessageRepository::findLatestForRecruiter(), AiQuota::getRemainingToday()
     *
     * @param Recruiter $recruiter recruteur connecté
     */
    #[Route('/espace-recruteur/accueil', name: 'recruiter_home', methods: ['GET'])]
    #[IsGranted('ROLE_RECRUITER')]
    public function home(#[CurrentUser] Recruiter $recruiter, CvContentProvider $cvContentProvider, ProjectRepository $projectRepository, ChatMessageRepository $chatMessageRepository, AiQuota $quota): Response
    {
        return $this->render('recruiter_area/home.html.twig', $cvContentProvider->getPrivateContent() + [
            'recruiter' => $recruiter,
            'projects' => $projectRepository->findRecruiterOnly(),
            'preview' => false,
            'assistant' => [
                'history' => $chatMessageRepository->findLatestForRecruiter($recruiter),
                'topics' => GuidedAssistant::TOPICS,
                'ai_enabled' => $this->settingsRepository->getOrCreate()->isAiAssistantEnabled(),
                'consent_given' => null !== $recruiter->getAiConsentAt(),
                'remaining' => $quota->getRemainingToday($recruiter),
                'max_length' => ChatAssistant::MAX_QUESTION_LENGTH,
            ],
        ]);
    }

    /**
     * Assistant guidé : réponse à une question prédéfinie, tirée du CV, sans IA ni consentement.
     * L'échange est noté dans le journal, puis retour à l'assistant.
     *
     * Liens :
     * - Route : recruiter_assistant_guided (/espace-recruteur/assistant/guide, POST, jeton CSRF)
     * - Utilise : GuidedAssistant::answer()
     *
     * @param Request $request requête (champ « topic » : sujet de GuidedAssistant::TOPICS)
     *
     * @throws NotFoundHttpException si le sujet est inconnu
     */
    #[Route('/espace-recruteur/assistant/guide', name: 'recruiter_assistant_guided', methods: ['POST'])]
    #[IsGranted('ROLE_RECRUITER')]
    #[IsCsrfTokenValid('recruiter_assistant')]
    public function assistantGuided(#[CurrentUser] Recruiter $recruiter, Request $request, GuidedAssistant $guidedAssistant, EntityManagerInterface $entityManager, ClockInterface $clock): Response
    {
        $topic = $request->request->getString('topic');
        if (!$guidedAssistant->supports($topic)) {
            throw $this->createNotFoundException();
        }
        $question = $this->translator->trans('assistant.guided.'.$topic);
        $entityManager->persist(new ChatMessage($recruiter, $clock->now(), $question, $guidedAssistant->answer($topic), ChatOutcome::Guided));
        $entityManager->flush();

        return $this->redirectToAssistant();
    }

    /**
     * Question libre à l'IA (Mistral AI) : consentement à la première question, 500 caractères au plus,
     * 5 questions par minute ; plafonds, filtrage et repli sur l'assistant guidé dans ChatAssistant.
     *
     * Liens :
     * - Route : recruiter_assistant_ask (/espace-recruteur/assistant/question, POST, jeton CSRF)
     * - Utilise : ChatAssistant::ask(), limiteur ai_chat_recruiter (config/packages/rate_limiter.yaml)
     *
     * @param Request                     $request                requête (champs « question » et « consent »)
     * @param RateLimiterFactoryInterface $aiChatRecruiterLimiter limiteur de 5 questions par minute et par recruteur
     */
    #[Route('/espace-recruteur/assistant/question', name: 'recruiter_assistant_ask', methods: ['POST'])]
    #[IsGranted('ROLE_RECRUITER')]
    #[IsCsrfTokenValid('recruiter_assistant')]
    public function assistantAsk(
        #[CurrentUser] Recruiter $recruiter,
        Request $request,
        ChatAssistant $chatAssistant,
        EntityManagerInterface $entityManager,
        ClockInterface $clock,
        #[Autowire(service: 'limiter.ai_chat_recruiter')]
        RateLimiterFactoryInterface $aiChatRecruiterLimiter,
    ): Response {
        $question = trim($request->request->getString('question'));
        if (null === $recruiter->getAiConsentAt()) {
            if (!$request->request->getBoolean('consent')) {
                $this->addFlash('assistant_error', $this->translator->trans('assistant.error.consent'));

                return $this->redirectToAssistant();
            }
            $recruiter->setAiConsentAt($clock->now());
            $entityManager->flush();
        }
        if ('' === $question || mb_strlen($question) > ChatAssistant::MAX_QUESTION_LENGTH) {
            $this->addFlash('assistant_error', $this->translator->trans('assistant.error.length', ['%max%' => ChatAssistant::MAX_QUESTION_LENGTH]));

            return $this->redirectToAssistant();
        }
        if (!$aiChatRecruiterLimiter->create('recruiter-'.$recruiter->getId())->consume()->isAccepted()) {
            $this->securityLogger->log(SecurityEventType::RateLimited, 'ai_chat_recruiter');
            $this->addFlash('assistant_error', $this->translator->trans('assistant.error.too_fast'));

            return $this->redirectToAssistant();
        }

        $reply = $chatAssistant->ask($recruiter, $question);
        if ([] !== $reply->removed) {
            $this->addFlash('assistant_notice', $this->translator->trans('assistant.notice.removed', ['%types%' => implode(', ', $reply->removed)]));
        }

        return $this->redirectToAssistant();
    }

    /**
     * Télécharge le CV complet en PDF (coordonnées et vrais noms d'entreprises), et note l'ACTIVITÉ « CV
     * téléchargé » (jamais un signal d'intérêt, v2.0.3) ; une requête de préchargement n'est pas notée.
     *
     * Liens :
     * - Route : recruiter_cv_pdf (/espace-recruteur/cv/{variant})
     * - Utilise : CvPdfRenderer::render($variant, true), InterestSignalNotifier::recordActivity()
     *
     * @param string $variant « ats » ou « design »
     */
    #[Route('/espace-recruteur/cv/{variant}', name: 'recruiter_cv_pdf', requirements: ['variant' => 'ats|design'], methods: ['GET'])]
    #[IsGranted('ROLE_RECRUITER')]
    public function cvPdf(string $variant, Request $request, #[CurrentUser] Recruiter $recruiter, CvPdfRenderer $pdfRenderer, InterestSignalNotifier $interestNotifier): Response
    {
        if (!self::isPrefetch($request)) {
            $interestNotifier->recordActivity($recruiter, RecruiterEventType::CvDownload);
        }

        return new Response($pdfRenderer->render($variant, true), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => \sprintf('attachment; filename="CV-Guillaume-Couget-complet-%s.pdf"', $variant),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Projet réservé aux recruteurs (ou public), affiché dans l'espace privé.
     *
     * Liens :
     * - Route : recruiter_project (/espace-recruteur/projets/{slug})
     * - Rend : templates/project/show.html.twig (recruiter_view : non indexé, retour à l'espace)
     *
     * @throws NotFoundHttpException si le projet n'existe pas, n'est pas publié ou est incomplet
     */
    #[Route('/espace-recruteur/projets/{slug}', name: 'recruiter_project', methods: ['GET'])]
    #[IsGranted('ROLE_RECRUITER')]
    public function project(string $slug, ProjectRepository $projectRepository): Response
    {
        $project = $projectRepository->findRecruiterVisibleBySlug($slug) ?? throw $this->createNotFoundException();

        return $this->render('project/show.html.twig', ['project' => $project, 'is_preview' => false, 'recruiter_view' => true]);
    }

    /**
     * Bouton « Je suis intéressé » : SEUL déclencheur du signal d'intérêt (v2.0.3, point 7). POST avec jeton CSRF
     * (un GET est refusé : ni affichage, ni survol, ni préchargement, ni robot de messagerie) ; une notification
     * « Signal d'intérêt » tout de suite ; un second clic dans la période (InterestSignalNotifier::COOLDOWN) ne crée
     * aucun doublon et affiche la date du signal déjà transmis.
     *
     * Liens :
     * - Route : recruiter_interest (/espace-recruteur/interet, POST, jeton CSRF)
     * - Utilise : InterestSignalNotifier::signalInterest()
     */
    #[Route('/espace-recruteur/interet', name: 'recruiter_interest', methods: ['POST'])]
    #[IsGranted('ROLE_RECRUITER')]
    #[IsCsrfTokenValid('recruiter_interest')]
    public function interest(#[CurrentUser] Recruiter $recruiter, InterestSignalNotifier $interestNotifier): Response
    {
        $previous = $interestNotifier->signalInterest($recruiter);
        if (null === $previous) {
            $this->addFlash('success', $this->translator->trans('recruiter_area.interest_confirmation'));
        } else {
            $date = $previous->getOccurredAt()->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y à H:i');
            $this->addFlash('info', $this->translator->trans('recruiter_area.interest_already', ['%date%' => $date]));
        }

        return $this->redirectToRoute('recruiter_home', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Indique si la requête est un préchargement (navigateur ou Turbo : en-têtes Sec-Purpose, Purpose ou
     * X-Sec-Purpose « prefetch ») : aucune activité n'est notée dans ce cas.
     *
     * Liens :
     * - Appelée par : cvPdf()
     */
    private static function isPrefetch(Request $request): bool
    {
        foreach (['Sec-Purpose', 'Purpose', 'X-Sec-Purpose', 'X-Purpose'] as $header) {
            if (str_contains(mb_strtolower((string) $request->headers->get($header)), 'prefetch')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Page « Mes données » : ce que le site garde sur le recruteur, et le bouton de suppression (confirmation).
     *
     * Liens :
     * - Route : recruiter_data (/espace-recruteur/mes-donnees)
     * - Rend : templates/recruiter_area/data.html.twig
     * - Voir aussi : deleteData() (envoi du formulaire)
     *
     * @param Recruiter $recruiter recruteur connecté
     */
    #[Route('/espace-recruteur/mes-donnees', name: 'recruiter_data', methods: ['GET'])]
    #[IsGranted('ROLE_RECRUITER')]
    public function data(#[CurrentUser] Recruiter $recruiter): Response
    {
        return $this->render('recruiter_area/data.html.twig', ['recruiter' => $recruiter]);
    }

    /**
     * Télécharge les données du recruteur connecté dans un fichier JSON lisible (droits d'accès et à la portabilité).
     *
     * Liens :
     * - Route : recruiter_data_export (/espace-recruteur/mes-donnees/telechargement), bouton de la page « Mes données »
     * - Utilise : RecruiterDataExporter::export()
     *
     * @param Recruiter $recruiter recruteur connecté
     *
     * @return Response fichier mes-donnees-guillaume-couget.json (téléchargement, jamais mis en cache)
     */
    #[Route('/espace-recruteur/mes-donnees/telechargement', name: 'recruiter_data_export', methods: ['GET'])]
    #[IsGranted('ROLE_RECRUITER')]
    public function exportData(#[CurrentUser] Recruiter $recruiter, RecruiterDataExporter $exporter, ClockInterface $clock): Response
    {
        $response = new Response($exporter->export($recruiter, $clock->now()), Response::HTTP_OK, ['Content-Type' => 'application/json; charset=UTF-8']);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition('attachment', 'mes-donnees-guillaume-couget.json'));
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * Supprime les données du recruteur connecté (fiche, évènements, échanges avec l'assistant), le
     * déconnecte (session invalidée) et revient à la page publique de l'espace recruteur avec un message.
     *
     * Liens :
     * - Route : recruiter_data_delete (/espace-recruteur/mes-donnees/supprimer, POST, jeton CSRF)
     * - Utilise : RecruiterAccessManager::deleteBySelf(), stockage du jeton de sécurité, ActionConfirmation
     *   (fenêtre de confirmation ; sans JavaScript, page de confirmation)
     *
     * @param Recruiter          $recruiter    recruteur connecté
     * @param Request            $request      requête (session à invalider)
     * @param ActionConfirmation $confirmation confirmation de la suppression
     */
    #[Route('/espace-recruteur/mes-donnees/supprimer', name: 'recruiter_data_delete', methods: ['POST'])]
    #[IsGranted('ROLE_RECRUITER')]
    #[IsCsrfTokenValid('recruiter_data_delete')]
    public function deleteData(#[CurrentUser] Recruiter $recruiter, Request $request, TokenStorageInterface $tokenStorage, ActionConfirmation $confirmation): Response
    {
        if (!$confirmation->isConfirmed($request)) {
            return $confirmation->render(
                $request,
                $this->translator->trans('recruiter_area.data.confirm_title'),
                $this->translator->trans('recruiter_area.data.delete_text'),
                $this->translator->trans('recruiter_area.data.delete_submit'),
                $this->generateUrl('recruiter_data'),
                true,
                'base.html.twig',
            );
        }
        $this->accessManager->deleteBySelf($recruiter);
        // Déconnexion : plus de jeton, nouvelle session (le message ci-dessous y est rangé)
        $tokenStorage->setToken(null);
        $request->getSession()->invalidate();
        $this->addFlash('success', $this->translator->trans('recruiter_area.data.deleted'));

        return $this->redirectToRoute('recruiter_access', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Déconnexion (traitée par le pare-feu « recruiter »).
     *
     * Liens :
     * - Route : recruiter_logout (/espace-recruteur/deconnexion)
     *
     * @throws \LogicException jamais atteinte : le pare-feu intercepte la route
     */
    #[Route('/espace-recruteur/deconnexion', name: 'recruiter_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('Déconnexion gérée par le pare-feu « recruiter ».');
    }

    /**
     * Redirige vers l'assistant de l'accueil (303, suivi par Turbo dans le cadre « assistant »).
     */
    private function redirectToAssistant(): Response
    {
        return $this->redirect($this->generateUrl('recruiter_home').'#assistant', Response::HTTP_SEE_OTHER);
    }

    /**
     * Contrôles d'un formulaire public valide : Turnstile, puis limites d'envoi (toutes doivent accepter).
     * Ajoute l'erreur au formulaire si un contrôle échoue.
     *
     * @param FormInterface<mixed>                                  $form        formulaire soumis
     * @param list<\Symfony\Component\RateLimiter\LimiterInterface> $limiters    limiteurs à consommer
     * @param string                                                $limiterName nom de la limite, noté au journal si elle est atteinte
     *
     * @return bool vrai si le formulaire peut être traité
     */
    private function passesChecks(FormInterface $form, Request $request, TurnstileVerifier $turnstile, array $limiters, string $limiterName): bool
    {
        if (null !== ($turnstileError = $turnstile->check($request->request->getString(TurnstileVerifier::RESPONSE_FIELD), $request->getClientIp()))) {
            $form->addError(new FormError($this->translator->trans($turnstileError)));

            return false;
        }
        foreach ($limiters as $limiter) {
            if (!$limiter->consume()->isAccepted()) {
                $this->securityLogger->log(SecurityEventType::RateLimited, $limiterName);
                $form->addError(new FormError($this->translator->trans('form.too_many_requests')));

                return false;
            }
        }

        return true;
    }
}
