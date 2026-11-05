<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Enum\SecurityEventType;
use App\Repository\SiteSettingsRepository;
use App\Repository\WebauthnCredentialRepository;
use App\Security\SecurityEventLogger;
use App\Service\ActionConfirmation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Liste les clés de sécurité du compte, permet d'en ajouter une (secours) ou d'en retirer une, et de révoquer
 * tous les appareils de confiance (cookie qui fait du cœur du pied de page un lien vers l'admin).
 *
 * La dernière clé ne peut pas être retirée depuis l'admin : sans clé, plus aucune
 * connexion ne serait possible. Pour repartir de zéro : app:admin:reset-webauthn (SSH).
 *
 * Liens :
 * - Rend : templates/admin/security_key/index.html.twig
 * - Ajout d'une clé : route admin_second_factor_enroll (SecondFactorController)
 * - Appareils de confiance : Security\TrustedDeviceCookie, SiteSettings::revokeTrustedDevices()
 */
#[IsGranted('ADMIN_KEY_VERIFIED')]
#[Route('/securite/mes-cles', name: 'security_key_')]
final class SecurityKeyController extends AbstractController
{
    /**
     * Affiche les clés enregistrées du compte.
     *
     * Liens :
     * - Route : admin_security_key_index (/{ADMIN_PATH}/securite/mes-cles)
     * - Rend : admin/security_key/index.html.twig
     *
     * @param WebauthnCredentialRepository $credentialRepository clés enregistrées
     *
     * @return Response la page HTML
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(WebauthnCredentialRepository $credentialRepository): Response
    {
        return $this->render('admin/security_key/index.html.twig', [
            'credentials' => $credentialRepository->findByUser($this->getAdminUser()),
        ]);
    }

    /**
     * Retire une clé du compte (jamais la dernière).
     *
     * Liens :
     * - Route : admin_security_key_delete (POST /{ADMIN_PATH}/securite/mes-cles/{id}/suppression)
     * - Utilise : WebauthnCredentialRepository, SecurityEventLogger
     *
     * @param string                       $id                   identifiant de la clé (ULID)
     * @param Request                      $request              formulaire de suppression (jeton CSRF)
     * @param WebauthnCredentialRepository $credentialRepository clés enregistrées
     * @param EntityManagerInterface       $entityManager        gestionnaire Doctrine
     * @param SecurityEventLogger          $securityEventLogger  journal des connexions
     * @param ActionConfirmation           $confirmation         confirmation (page du serveur sans JavaScript)
     * @param TranslatorInterface          $translator           textes de la confirmation
     *
     * @return Response redirection vers la liste des clés, ou page de confirmation
     */
    #[Route('/{id}/suppression', name: 'delete', requirements: ['id' => '[0-9A-HJKMNP-TV-Z]{26}'], methods: ['POST'])]
    public function delete(
        string $id,
        Request $request,
        WebauthnCredentialRepository $credentialRepository,
        EntityManagerInterface $entityManager,
        SecurityEventLogger $securityEventLogger,
        ActionConfirmation $confirmation,
        TranslatorInterface $translator,
    ): Response {
        $user = $this->getAdminUser();

        if (!$this->isCsrfTokenValid('delete_security_key_'.$id, (string) $request->getPayload()->get('_token'))) {
            $this->addFlash('danger', 'admin.crud.invalid_csrf');

            return $this->redirectToRoute('admin_security_key_index');
        }

        $credential = $credentialRepository->find($id);
        if (null === $credential || $credential->userHandle !== $user->getWebauthnUserHandle()) {
            throw $this->createNotFoundException();
        }

        if ($credentialRepository->countByUser($user) <= 1) {
            $this->addFlash('warning', 'admin.security_key.cannot_remove_last');

            return $this->redirectToRoute('admin_security_key_index');
        }
        if (!$confirmation->isConfirmed($request)) {
            return $confirmation->render(
                $request,
                $translator->trans('admin.security_key.delete_confirm', ['%name%' => $credential->getLabel()]),
                '',
                $translator->trans('admin.crud.delete'),
                $this->generateUrl('admin_security_key_index'),
            );
        }

        $entityManager->remove($credential);
        $entityManager->flush();
        $securityEventLogger->log(SecurityEventType::KeyRemoved, $user->getUsername());
        $this->addFlash('success', 'admin.security_key.removed');

        return $this->redirectToRoute('admin_security_key_index');
    }

    /**
     * Révoque tous les appareils de confiance : la génération des cookies passe à la suivante, si bien que plus
     * aucun cookie déjà posé n'affiche le lien vers l'admin (y compris sur cet appareil, jusqu'à la prochaine
     * connexion complète).
     *
     * Liens :
     * - Route : admin_security_key_revoke_trusted_devices (POST /{ADMIN_PATH}/securite/mes-cles/appareils-de-confiance/revocation)
     * - Formulaire : templates/admin/security_key/index.html.twig (encadré « Appareils de confiance »)
     * - Utilise : SiteSettingsRepository, SiteSettings::revokeTrustedDevices(), SecurityEventLogger, ActionConfirmation
     *
     * @param Request                $request             formulaire (jeton CSRF, confirmation)
     * @param SiteSettingsRepository $settingsRepository  réglages du site (génération des cookies)
     * @param EntityManagerInterface $entityManager       gestionnaire Doctrine
     * @param SecurityEventLogger    $securityEventLogger journal des connexions
     * @param ActionConfirmation     $confirmation        confirmation (page du serveur sans JavaScript)
     * @param TranslatorInterface    $translator          textes de la confirmation
     *
     * @return Response redirection vers la liste des clés, ou page de confirmation
     */
    #[Route('/appareils-de-confiance/revocation', name: 'revoke_trusted_devices', methods: ['POST'])]
    public function revokeTrustedDevices(
        Request $request,
        SiteSettingsRepository $settingsRepository,
        EntityManagerInterface $entityManager,
        SecurityEventLogger $securityEventLogger,
        ActionConfirmation $confirmation,
        TranslatorInterface $translator,
    ): Response {
        if (!$this->isCsrfTokenValid('revoke_trusted_devices', (string) $request->getPayload()->get('_token'))) {
            $this->addFlash('danger', 'admin.crud.invalid_csrf');

            return $this->redirectToRoute('admin_security_key_index');
        }
        if (!$confirmation->isConfirmed($request)) {
            return $confirmation->render(
                $request,
                $translator->trans('admin.security_key.trusted_devices.revoke_confirm'),
                '',
                $translator->trans('admin.security_key.trusted_devices.revoke'),
                $this->generateUrl('admin_security_key_index'),
            );
        }

        // Ligne des réglages créée si elle n'existe pas encore : la nouvelle génération doit être enregistrée.
        $settings = $settingsRepository->getOrCreate();
        $settings->revokeTrustedDevices();
        $entityManager->persist($settings);
        $entityManager->flush();

        $securityEventLogger->log(SecurityEventType::TrustedDevicesRevoked, $this->getAdminUser()->getUsername());
        $this->addFlash('success', 'admin.security_key.trusted_devices.revoked');

        return $this->redirectToRoute('admin_security_key_index');
    }

    /**
     * Renvoie le compte admin connecté.
     *
     * @throws \LogicException si l'utilisateur n'est pas un User
     */
    private function getAdminUser(): User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : throw new \LogicException('Compte admin attendu.');
    }
}
