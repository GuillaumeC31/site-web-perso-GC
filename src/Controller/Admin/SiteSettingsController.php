<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Enum\NotificationEvent;
use App\Form\SiteSettingsType;
use App\Repository\SiteSettingsRepository;
use App\Service\ActionConfirmation;
use App\Service\MaintenancePage;
use App\Service\Notification\Notification;
use App\Service\Notification\NotificationDispatcher;
use App\Service\Seo\SeoContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Paramètres généraux du site (CDC §9) : délai de réponse affiché, validation automatique des demandes
 * recruteurs, assistant IA, référencement de l'accueil, LinkedIn, hébergeur, mode maintenance ; indique aussi si
 * l'image de partage est installée.
 *
 * Les notifications (canaux par événement, notifications de test), les adresses e-mail et la validation à
 * distance sont dans la page Communication depuis le 2026-10-29 : l'ancienne adresse
 * /{ADMIN_PATH}/parametres/notifications y redirige.
 *
 * Liens :
 * - Routes : admin_site_settings_edit (/{ADMIN_PATH}/parametres), admin_site_settings_maintenance_preview (aperçu de la
 *   page de maintenance), admin_site_settings_notifications (ancienne adresse, redirection)
 * - Rend : templates/admin/site_settings/edit.html.twig
 * - Utilise : SiteSettingsRepository, SiteSettingsType, SeoContext, ActionConfirmation (activation du mode maintenance),
 *   NotificationDispatcher (maintenance activée ou désactivée)
 * - Voir aussi : Admin\CommunicationController
 */
#[IsGranted('ADMIN_KEY_VERIFIED')]
final class SiteSettingsController extends AbstractController
{
    /**
     * Affiche et enregistre les paramètres. Activer le mode maintenance demande une confirmation (fenêtre ;
     * sans JavaScript, page de confirmation qui renvoie le formulaire) ; activer ou désactiver la maintenance
     * envoie la notification « Maintenance » (catégorie Général du site).
     *
     * Liens :
     * - Route : admin_site_settings_edit (/{ADMIN_PATH}/parametres)
     * - Rend : templates/admin/site_settings/edit.html.twig, templates/confirmation/confirm.html.twig
     */
    #[Route('/parametres', name: 'site_settings_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, SiteSettingsRepository $repository, EntityManagerInterface $entityManager, NotificationDispatcher $notifier, SeoContext $seoContext, ActionConfirmation $confirmation, TranslatorInterface $translator): Response
    {
        $settings = $repository->getOrCreate();
        $wasInMaintenance = $settings->isMaintenanceEnabled();
        $form = $this->createForm(SiteSettingsType::class, $settings);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            // Rien n'est enregistré tant que l'activation n'est pas confirmée
            if (!$wasInMaintenance && $settings->isMaintenanceEnabled() && !$confirmation->isConfirmed($request)) {
                return $confirmation->render(
                    $request,
                    $translator->trans('admin.site_settings.confirm_maintenance_title'),
                    $translator->trans('admin.site_settings.confirm_maintenance_text'),
                    $translator->trans('admin.site_settings.confirm_maintenance_submit'),
                    $this->generateUrl('admin_site_settings_edit'),
                );
            }
            $entityManager->persist($settings);
            $entityManager->flush();
            if ($wasInMaintenance !== $settings->isMaintenanceEnabled()) {
                $notifier->notify(new Notification(
                    NotificationEvent::Maintenance,
                    $settings->isMaintenanceEnabled() ? 'Mode maintenance activé' : 'Mode maintenance désactivé',
                    ['Réactivation prévue' => $settings->getMaintenanceUntil()?->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y H:i') ?? '—'],
                    $this->generateUrl('admin_site_settings_edit', [], UrlGeneratorInterface::ABSOLUTE_URL),
                ));
            }
            $this->addFlash('success', 'admin.site_settings.flash.saved');

            return $this->redirectToRoute('admin_site_settings_edit', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/site_settings/edit.html.twig', [
            'form' => $form,
            'share_image' => $seoContext->getShareImage(),
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * Ancienne adresse des notifications (Paramètres › Notifications) : redirige vers Communication › Notifications.
     *
     * Liens :
     * - Route : admin_site_settings_notifications (/{ADMIN_PATH}/parametres/notifications)
     * - Voir aussi : Admin\CommunicationController::notifications()
     */
    #[Route('/parametres/notifications', name: 'site_settings_notifications', methods: ['GET'])]
    public function notifications(): Response
    {
        return $this->redirectToRoute('admin_communication_notifications', [], Response::HTTP_MOVED_PERMANENTLY);
    }

    /**
     * Affiche la page de maintenance telle que les visiteurs la verraient (message, date de réactivation), sans
     * activer la maintenance (réponse 200, bandeau « Aperçu »).
     *
     * Liens :
     * - Route : admin_site_settings_maintenance_preview (/{ADMIN_PATH}/parametres/maintenance/apercu)
     * - Utilise : MaintenancePage::render() (templates/maintenance.html.twig)
     */
    #[Route('/parametres/maintenance/apercu', name: 'site_settings_maintenance_preview', methods: ['GET'])]
    public function maintenancePreview(SiteSettingsRepository $repository, MaintenancePage $page): Response
    {
        return $page->render($repository->getOrCreate(), true);
    }
}
