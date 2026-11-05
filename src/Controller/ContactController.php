<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ContactMessage;
use App\Enum\NotificationEvent;
use App\Enum\SecurityEventType;
use App\Form\ContactType;
use App\Repository\SiteSettingsRepository;
use App\Security\SecurityEventLogger;
use App\Security\TurnstileVerifier;
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
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Formulaire de contact public (TEXTES_SITE § 10) : aucun e-mail ni téléphone affiché.
 *
 * Protections (CDC §10) : jeton CSRF, Cloudflare Turnstile, champ piège invisible, 3 envois par heure
 * et par IP, consentement. Le message est enregistré (admin « Messages ») et notifié sans son contenu.
 *
 * Liens :
 * - Route : contact_show (/contact, GET et POST)
 * - Rend : templates/contact/show.html.twig
 * - Utilise : ContactType, TurnstileVerifier, limiteur contact_form, NotificationDispatcher
 */
final class ContactController extends AbstractController
{
    /**
     * Affiche le formulaire, enregistre le message envoyé puis redirige vers la confirmation.
     *
     * Liens :
     * - Route : contact_show (/contact)
     * - Rend : templates/contact/show.html.twig (statut 422 si le formulaire est refusé)
     *
     * @param Request                     $request            requête
     * @param TurnstileVerifier           $turnstile          vérification anti-robot
     * @param RateLimiterFactoryInterface $contactFormLimiter limiteur « contact_form » (3 par heure et par IP)
     * @param EntityManagerInterface      $entityManager      enregistrement du message
     * @param NotificationDispatcher      $notifier           notification à Guillaume
     * @param SiteSettingsRepository      $settingsRepository délai de réponse affiché
     * @param TranslatorInterface         $translator         messages
     * @param ClockInterface              $clock              date courante
     * @param SecurityEventLogger         $securityLogger     limite atteinte (tableau de bord, tuile Sécurité)
     *
     * @return Response la page, ou la redirection vers la confirmation
     */
    #[Route('/contact', name: 'contact_show', methods: ['GET', 'POST'])]
    public function show(
        Request $request,
        TurnstileVerifier $turnstile,
        #[Autowire(service: 'limiter.contact_form')]
        RateLimiterFactoryInterface $contactFormLimiter,
        EntityManagerInterface $entityManager,
        NotificationDispatcher $notifier,
        SiteSettingsRepository $settingsRepository,
        TranslatorInterface $translator,
        ClockInterface $clock,
        SecurityEventLogger $securityLogger,
    ): Response {
        $message = new ContactMessage($clock->now());
        $form = $this->createForm(ContactType::class, $message);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (null !== ($turnstileError = $turnstile->check($request->request->getString(TurnstileVerifier::RESPONSE_FIELD), $request->getClientIp()))) {
                $form->addError(new FormError($translator->trans($turnstileError)));
            } elseif (!$contactFormLimiter->create((string) $request->getClientIp())->consume()->isAccepted()) {
                $securityLogger->log(SecurityEventType::RateLimited, 'contact_form');
                $form->addError(new FormError($translator->trans('form.too_many_requests')));
            } else {
                // Champ piège rempli : réponse normale, rien n'est enregistré ni notifié
                if ('' === (string) $form->get('website')->getData()) {
                    $message->setIpAddress($request->getClientIp())->setConsentAt($clock->now());
                    $entityManager->persist($message);
                    $entityManager->flush();
                    $notifier->notify(new Notification(NotificationEvent::Contact, 'Nouveau message de contact', [
                        'Nom' => $message->getName(), 'Entreprise' => $message->getCompany() ?? '—',
                        'Objet' => $translator->trans((string) $message->getSubject()?->translationKey()),
                    ], $this->generateUrl('admin_contact_message_show', ['id' => $message->getId()], UrlGeneratorInterface::ABSOLUTE_URL), senderEmail: $message->getEmail()));
                }
                $this->addFlash('success', $translator->trans('contact.confirmation', ['%delay%' => $settingsRepository->getOrCreate()->getResponseDelay()]));

                return $this->redirectToRoute('contact_show', [], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('contact/show.html.twig', [
            'form' => $form,
            'response_delay' => $settingsRepository->getOrCreate()->getResponseDelay(),
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
