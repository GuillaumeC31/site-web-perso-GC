<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\FailedEmailRepository;
use App\Service\ActionConfirmation;
use App\Service\Mail\FailedEmailResender;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Renvoi des e-mails non envoyés (serveur SMTP injoignable), depuis le tableau de bord ou depuis Communication ›
 * E-mails. Les e-mails de test par adresse sont dans Admin\CommunicationController depuis le 2026-10-29.
 *
 * Liens :
 * - Route : admin_mail_resend (/{ADMIN_PATH}/e-mails/renvoyer, POST)
 * - Utilise : FailedEmailResender, ActionConfirmation
 * - Boutons : templates/admin/dashboard/index.html.twig, templates/admin/communication/emails.html.twig
 */
#[IsGranted('ADMIN_KEY_VERIFIED')]
#[Route('/e-mails', name: 'mail_')]
final class MailController extends AbstractController
{
    /**
     * Reçoit le traducteur des messages.
     */
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /**
     * Renvoie tous les e-mails non envoyés et indique le résultat, après confirmation (sans JavaScript :
     * page de confirmation). Retour vers la page d'origine (champ « back » : communication, sinon tableau de bord).
     *
     * Liens :
     * - Route : admin_mail_resend (/{ADMIN_PATH}/e-mails/renvoyer, POST, jeton CSRF « mail_resend »)
     * - Utilise : FailedEmailResender::resendAll(), ActionConfirmation, FailedEmailRepository
     */
    #[Route('/renvoyer', name: 'resend', methods: ['POST'])]
    #[IsCsrfTokenValid('mail_resend')]
    public function resend(Request $request, FailedEmailResender $resender, ActionConfirmation $confirmation, FailedEmailRepository $failedEmails): Response
    {
        $back = 'communication' === $request->request->getString('back') ? 'admin_communication_emails' : 'admin_dashboard';
        if (!$confirmation->isConfirmed($request)) {
            return $confirmation->render(
                $request,
                $this->translator->trans('admin.mail.confirm_resend_title', ['%count%' => $failedEmails->count([])]),
                $this->translator->trans('admin.mail.confirm_resend_text'),
                $this->translator->trans('admin.mail.resend'),
                $this->generateUrl($back),
                false,
            );
        }
        $result = $resender->resendAll();
        $this->addFlash(0 === $result['failed'] ? 'success' : 'warning', $this->translator->trans('admin.mail.flash.resent', [
            '%sent%' => $result['sent'], '%failed%' => $result['failed'], '%dropped%' => $result['dropped'],
        ]));

        return $this->redirectToRoute($back, [], Response::HTTP_SEE_OTHER);
    }
}
