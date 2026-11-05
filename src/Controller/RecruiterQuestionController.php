<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ChatMessage;
use App\Entity\ContactMessage;
use App\Entity\Recruiter;
use App\Enum\ContactSource;
use App\Enum\ContactSubject;
use App\Enum\NotificationEvent;
use App\Enum\SecurityEventType;
use App\Form\RecruiterQuestionType;
use App\Repository\ChatMessageRepository;
use App\Repository\SiteSettingsRepository;
use App\Security\SecurityEventLogger;
use App\Service\Notification\Notification;
use App\Service\Notification\NotificationDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Formulaire de question privé de l'espace recruteur : lien proposé par l'assistant quand une information
 * n'est pas précisée (« vous pouvez poser la question à Guillaume via le formulaire de contact »). Le
 * recruteur est déjà identifié : seul son message est demandé ; il arrive dans les messages de contact de
 * l'admin (objet « Recrutement ») avec une notification.
 *
 * Liens :
 * - Route : recruiter_question (/espace-recruteur/question, GET et POST ; ?echange={id} : question posée à
 *   l'assistant, reprise dans le message)
 * - Rend : templates/recruiter_area/question.html.twig
 * - Utilise : ContactMessage, NotificationDispatcher, limiteur contact_form (3 par heure), SecurityEventLogger
 */
#[IsGranted('ROLE_RECRUITER')]
final class RecruiterQuestionController extends AbstractController
{
    /**
     * Affiche le formulaire, enregistre la question et prévient Guillaume.
     *
     * Liens :
     * - Route : recruiter_question (/espace-recruteur/question)
     * - Rend : templates/recruiter_area/question.html.twig (422 si le formulaire est refusé)
     *
     * @param Recruiter                   $recruiter          recruteur connecté
     * @param RateLimiterFactoryInterface $contactFormLimiter limiteur « contact_form » (3 envois par heure)
     */
    #[Route('/espace-recruteur/question', name: 'recruiter_question', methods: ['GET', 'POST'])]
    public function ask(
        #[CurrentUser] Recruiter $recruiter,
        Request $request,
        ChatMessageRepository $chatMessageRepository,
        EntityManagerInterface $entityManager,
        NotificationDispatcher $notifier,
        SiteSettingsRepository $settingsRepository,
        TranslatorInterface $translator,
        ClockInterface $clock,
        SecurityEventLogger $securityLogger,
        #[Autowire(service: 'limiter.contact_form')]
        RateLimiterFactoryInterface $contactFormLimiter,
    ): Response {
        // Question posée à l'assistant, reprise seulement si l'échange est celui de ce recruteur
        $exchange = $request->query->getInt('echange') > 0 ? $chatMessageRepository->find($request->query->getInt('echange')) : null;
        $initial = $exchange instanceof ChatMessage && $exchange->getRecruiter() === $recruiter
            ? $translator->trans('recruiter_area.question.prefill', ['%question%' => $exchange->getQuestion()])
            : '';
        $form = $this->createForm(RecruiterQuestionType::class, ['message' => $initial]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$contactFormLimiter->create('recruiter-'.$recruiter->getId())->consume()->isAccepted()) {
                $securityLogger->log(SecurityEventType::RateLimited, 'recruiter_question');
                $form->addError(new FormError($translator->trans('form.too_many_requests')));
            } else {
                $now = $clock->now();
                $message = (new ContactMessage($now))
                    ->setName($recruiter->getFullName())->setEmail($recruiter->getEmail())->setCompany($recruiter->getCompany())
                    ->setSubject(ContactSubject::Recruitment)->setSource(ContactSource::RecruiterQuestion)->setMessage((string) $form->get('message')->getData())
                    ->setConsentAt($now);
                $entityManager->persist($message);
                $entityManager->flush();
                $notifier->notify(new Notification(NotificationEvent::Contact, 'Question d\'un recruteur (espace recruteur)', [
                    'Nom' => $message->getName(), 'Entreprise' => $message->getCompany() ?? '—',
                ], $this->generateUrl('admin_contact_message_show', ['id' => $message->getId()], UrlGeneratorInterface::ABSOLUTE_URL), senderEmail: $message->getEmail(), emailContact: $message->getEmail()));
                $this->addFlash('success', $translator->trans('recruiter_area.question.sent', ['%delay%' => $settingsRepository->getOrCreate()->getResponseDelay()]));

                return $this->redirectToRoute('recruiter_home', [], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('recruiter_area/question.html.twig', [
            'form' => $form,
            'recruiter' => $recruiter,
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
