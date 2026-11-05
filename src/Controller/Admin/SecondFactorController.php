<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Entity\WebauthnCredential;
use App\Enum\SecurityEventType;
use App\Repository\WebauthnCredentialRepository;
use App\Security\EnrollmentTokenManager;
use App\Security\SecondFactorSession;
use App\Security\SecurityEventLogger;
use App\Security\TrustedDeviceCookie;
use App\Security\WebauthnUserEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\Bundle\Service\PublicKeyCredentialCreationOptionsFactory;
use Webauthn\Bundle\Service\PublicKeyCredentialRequestOptionsFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Deuxième étape de connexion : vérification de la YubiKey, et enregistrement d'une clé.
 *
 * Accessible avec le seul mot de passe (ROLE_ADMIN), car c'est l'étape qui pose le
 * marqueur « clé vérifiée ». Les règles :
 * - vérification : seulement avec une clé déjà enregistrée pour ce compte ;
 * - enregistrement : seulement si la clé est déjà vérifiée (ajout d'une deuxième clé),
 *   ou pendant une fenêtre d'enrôlement ouverte par un lien valide (première clé ou
 *   après réinitialisation par SSH). Le mot de passe seul ne permet jamais
 *   d'enregistrer une clé.
 *
 * Les appels JSON exigent l'en-tête X-CSRF-Token et sont limités à 10 par 15 minutes.
 *
 * Liens :
 * - Utilise : SecondFactorSession, EnrollmentTokenManager, WebauthnCredentialRepository,
 *   validateurs de web-auth/webauthn-lib, SecurityEventLogger
 * - Rend : templates/admin/second_factor/*.html.twig
 * - JS : assets/controllers/webauthn_controller.js
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/securite', name: 'second_factor')]
final class SecondFactorController extends AbstractController
{
    /** Identifiant du jeton CSRF des appels JSON (en-tête X-CSRF-Token). */
    public const CSRF_TOKEN_ID = 'webauthn';

    private const CEREMONY_ASSERTION = 'assertion';
    private const CEREMONY_REGISTRATION = 'registration';

    /** Contexte de sérialisation des options WebAuthn envoyées au navigateur. */
    private const OPTIONS_CONTEXT = [AbstractObjectNormalizer::SKIP_NULL_VALUES => true];

    /**
     * Reçoit les dépendances.
     *
     * @param SecondFactorSession          $secondFactorSession  marqueurs de double authentification
     * @param WebauthnCredentialRepository $credentialRepository clés enregistrées
     * @param SecurityEventLogger          $securityEventLogger  journal des connexions
     * @param SerializerInterface          $serializer           sérialiseur (normaliseurs WebAuthn du bundle)
     * @param EntityManagerInterface       $entityManager        gestionnaire Doctrine
     * @param TranslatorInterface          $translator           textes des réponses JSON
     * @param LoggerInterface              $logger               journal applicatif (causes d'échec)
     * @param RateLimiterFactoryInterface  $limiterFactory       limite admin_second_factor
     * @param TrustedDeviceCookie          $trustedDeviceCookie  cookie « appareil de confiance » (lien du pied de page)
     */
    public function __construct(
        private readonly SecondFactorSession $secondFactorSession,
        private readonly WebauthnCredentialRepository $credentialRepository,
        private readonly SecurityEventLogger $securityEventLogger,
        private readonly SerializerInterface $serializer,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        #[Target('admin_second_factor')]
        private readonly RateLimiterFactoryInterface $limiterFactory,
        private readonly TrustedDeviceCookie $trustedDeviceCookie,
    ) {
    }

    /**
     * Affiche l'étape YubiKey : vérification si une clé existe, sinon explication de l'enrôlement.
     *
     * Liens :
     * - Route : admin_second_factor (/{ADMIN_PATH}/securite/cle)
     * - Rend : admin/second_factor/verify.html.twig ou admin/second_factor/no_key.html.twig
     * - Appelée après : AdminLoginSubscriber::onLoginSuccess(), AdminAccessDeniedHandler::handle()
     *
     * @return Response la page, ou une redirection vers le tableau de bord si déjà vérifié
     */
    #[Route('/cle', name: '', methods: ['GET'])]
    public function verify(): Response
    {
        $user = $this->getAdminUser();

        if ($this->secondFactorSession->isVerified($user)) {
            return $this->redirectToRoute('admin_dashboard');
        }

        if (0 === $this->credentialRepository->countByUser($user)) {
            return $this->render('admin/second_factor/no_key.html.twig');
        }

        return $this->render('admin/second_factor/verify.html.twig');
    }

    /**
     * Renvoie au navigateur le défi à signer avec la YubiKey (clés autorisées : celles du compte).
     *
     * Liens :
     * - Route : admin_second_factor_assertion_options (POST /{ADMIN_PATH}/securite/cle/options)
     * - Appelée par : webauthn_controller.js (action authenticate)
     * - Utilise : PublicKeyCredentialRequestOptionsFactory, SecondFactorSession::storeCeremonyOptions()
     *
     * @param Request                                  $request        requête JSON
     * @param PublicKeyCredentialRequestOptionsFactory $optionsFactory fabrique des options (profil default)
     *
     * @return JsonResponse les options WebAuthn, ou une erreur
     */
    #[Route('/cle/options', name: '_assertion_options', methods: ['POST'])]
    public function assertionOptions(Request $request, PublicKeyCredentialRequestOptionsFactory $optionsFactory): JsonResponse
    {
        $user = $this->getAdminUser();
        if (null !== ($error = $this->guardJsonRequest($request, $user))) {
            return $error;
        }

        $allowedCredentials = $this->credentialRepository->findDescriptorsByUser($user);
        if ([] === $allowedCredentials) {
            return $this->jsonError('admin.second_factor.error.no_key', Response::HTTP_BAD_REQUEST);
        }

        $options = $optionsFactory->create('default', $allowedCredentials);
        $this->secondFactorSession->storeCeremonyOptions(self::CEREMONY_ASSERTION, $options);

        return $this->jsonOptions($options);
    }

    /**
     * Vérifie la signature de la YubiKey et, si elle est valable, pose le marqueur « clé vérifiée ».
     *
     * Liens :
     * - Route : admin_second_factor_assertion_verify (POST /{ADMIN_PATH}/securite/cle/verification)
     * - Appelée par : webauthn_controller.js (action authenticate)
     * - Utilise : AuthenticatorAssertionResponseValidator, SecondFactorSession::markVerified(),
     *   TrustedDeviceCookie::create() (connexion complète : l'appareil devient de confiance)
     *
     * @param Request                                 $request   requête JSON (réponse de la clé)
     * @param AuthenticatorAssertionResponseValidator $validator validateur WebAuthn
     *
     * @return JsonResponse { status: ok, redirect } ou une erreur 401
     */
    #[Route('/cle/verification', name: '_assertion_verify', methods: ['POST'])]
    public function verifyAssertion(Request $request, AuthenticatorAssertionResponseValidator $validator): JsonResponse
    {
        $user = $this->getAdminUser();
        if (null !== ($error = $this->guardJsonRequest($request, $user))) {
            return $error;
        }

        $options = $this->secondFactorSession->takeCeremonyOptions(self::CEREMONY_ASSERTION);

        try {
            if (!$options instanceof PublicKeyCredentialRequestOptions) {
                throw new \RuntimeException('Défi absent ou expiré.');
            }

            $publicKeyCredential = $this->serializer->deserialize($request->getContent(), PublicKeyCredential::class, 'json');
            $response = $publicKeyCredential->response;
            if (!$response instanceof AuthenticatorAssertionResponse) {
                throw new \RuntimeException('Réponse de clé inattendue.');
            }

            // La clé doit appartenir au compte connecté (et pas à un autre compte).
            $credential = $this->credentialRepository->findOneByCredentialId($publicKeyCredential->rawId);
            if (!$credential instanceof WebauthnCredential || $credential->userHandle !== $user->getWebauthnUserHandle()) {
                throw new \RuntimeException('Clé inconnue pour ce compte.');
            }

            $checked = $validator->check($credential, $response, $options, $request->getHost(), $user->getWebauthnUserHandle());
            $credential->markUsed($checked->counter);
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            return $this->failCeremony(SecurityEventType::KeyFailure, $user, $exception);
        }

        $this->secondFactorSession->markVerified($user);
        $this->securityEventLogger->log(SecurityEventType::KeySuccess, $user->getUsername());

        $jsonResponse = new JsonResponse(['status' => 'ok', 'redirect' => $this->generateUrl('admin_dashboard')]);
        $jsonResponse->headers->setCookie($this->trustedDeviceCookie->create());

        return $jsonResponse;
    }

    /**
     * Valide un lien d'enrôlement (jeton à usage unique) et ouvre la fenêtre d'enregistrement d'une clé.
     *
     * Exige d'être connecté avec le mot de passe du compte visé (règle ROLE_ADMIN).
     *
     * Liens :
     * - Route : admin_second_factor_enroll_start (/{ADMIN_PATH}/securite/enrolement/{token})
     * - Lien créé par : CreateAdminCommand, ResetAdminWebauthnCommand
     * - Utilise : EnrollmentTokenManager::consume(), SecondFactorSession::startEnrollment()
     * - Rend : admin/second_factor/enrollment_invalid.html.twig (lien refusé)
     *
     * @param string                 $token        jeton lu dans le lien
     * @param EnrollmentTokenManager $tokenManager vérification du jeton
     *
     * @return Response redirection vers l'enregistrement, ou page d'erreur (403)
     */
    #[Route('/enrolement/{token}', name: '_enroll_start', requirements: ['token' => '[A-Za-z0-9_-]{20,100}'], methods: ['GET'])]
    public function startEnrollment(string $token, EnrollmentTokenManager $tokenManager): Response
    {
        $user = $this->getAdminUser();
        $expiresAt = $tokenManager->consume($user, $token);
        // L'effacement du jeton (accepté ou expiré) est enregistré dans tous les cas.
        $this->entityManager->flush();

        if (null === $expiresAt) {
            $this->securityEventLogger->log(SecurityEventType::EnrollmentLinkRejected, $user->getUsername());

            return $this->render('admin/second_factor/enrollment_invalid.html.twig', [], new Response(status: Response::HTTP_FORBIDDEN));
        }

        $this->secondFactorSession->startEnrollment($user, $expiresAt);
        $this->securityEventLogger->log(SecurityEventType::EnrollmentLinkUsed, $user->getUsername());

        return $this->redirectToRoute('admin_second_factor_enroll');
    }

    /**
     * Affiche la page d'enregistrement d'une clé (fenêtre d'enrôlement ouverte, ou clé déjà vérifiée).
     *
     * Liens :
     * - Route : admin_second_factor_enroll (/{ADMIN_PATH}/securite/enrolement)
     * - Rend : admin/second_factor/enroll.html.twig
     *
     * @return Response la page, ou une redirection vers l'étape YubiKey si l'enregistrement n'est pas permis
     */
    #[Route('/enrolement', name: '_enroll', methods: ['GET'])]
    public function enroll(): Response
    {
        $user = $this->getAdminUser();

        if (!$this->canRegisterKey($user)) {
            $this->addFlash('warning', 'admin.second_factor.enrollment_required');

            return $this->redirectToRoute('admin_second_factor');
        }

        return $this->render('admin/second_factor/enroll.html.twig', [
            'is_first_key' => !$this->secondFactorSession->isVerified($user),
        ]);
    }

    /**
     * Renvoie au navigateur les options d'enregistrement d'une nouvelle clé.
     *
     * Liens :
     * - Route : admin_second_factor_registration_options (POST /{ADMIN_PATH}/securite/enrolement/options)
     * - Appelée par : webauthn_controller.js (action register)
     * - Utilise : PublicKeyCredentialCreationOptionsFactory, SecondFactorSession::storeCeremonyOptions()
     *
     * @param Request                                   $request        requête JSON
     * @param PublicKeyCredentialCreationOptionsFactory $optionsFactory fabrique des options (profil default)
     *
     * @return JsonResponse les options WebAuthn, ou une erreur 403
     */
    #[Route('/enrolement/options', name: '_registration_options', methods: ['POST'], priority: 10)]
    public function registrationOptions(Request $request, PublicKeyCredentialCreationOptionsFactory $optionsFactory): JsonResponse
    {
        $user = $this->getAdminUser();
        if (null !== ($error = $this->guardJsonRequest($request, $user))) {
            return $error;
        }
        if (!$this->canRegisterKey($user)) {
            return $this->jsonError('admin.second_factor.enrollment_required', Response::HTTP_FORBIDDEN);
        }

        $options = $optionsFactory->create(
            'default',
            WebauthnUserEntityRepository::toUserEntity($user),
            // Les clés déjà enregistrées sont exclues : une même clé ne peut pas être ajoutée deux fois.
            $this->credentialRepository->findDescriptorsByUser($user),
        );
        $this->secondFactorSession->storeCeremonyOptions(self::CEREMONY_REGISTRATION, $options);

        return $this->jsonOptions($options);
    }

    /**
     * Vérifie la réponse d'enregistrement et enregistre la nouvelle clé.
     *
     * Corps attendu : { "credential": <réponse de la clé>, "label": "YubiKey principale" }.
     * Après la première clé, le marqueur « clé vérifiée » est posé : la possession de la
     * clé vient d'être prouvée, en plus du mot de passe et du lien d'enrôlement.
     *
     * Liens :
     * - Route : admin_second_factor_registration_verify (POST /{ADMIN_PATH}/securite/enrolement/verification)
     * - Appelée par : webauthn_controller.js (action register)
     * - Utilise : AuthenticatorAttestationResponseValidator, WebauthnCredential::fromCredentialRecord(),
     *   TrustedDeviceCookie::create() (première clé : connexion complète)
     *
     * @param Request                                   $request   requête JSON
     * @param AuthenticatorAttestationResponseValidator $validator validateur WebAuthn
     *
     * @return JsonResponse { status: ok, redirect } ou une erreur
     */
    #[Route('/enrolement/verification', name: '_registration_verify', methods: ['POST'], priority: 10)]
    public function verifyRegistration(Request $request, AuthenticatorAttestationResponseValidator $validator): JsonResponse
    {
        $user = $this->getAdminUser();
        if (null !== ($error = $this->guardJsonRequest($request, $user))) {
            return $error;
        }
        if (!$this->canRegisterKey($user)) {
            return $this->jsonError('admin.second_factor.enrollment_required', Response::HTTP_FORBIDDEN);
        }

        $options = $this->secondFactorSession->takeCeremonyOptions(self::CEREMONY_REGISTRATION);

        try {
            if (!$options instanceof PublicKeyCredentialCreationOptions || $options->user->id !== $user->getWebauthnUserHandle()) {
                throw new \RuntimeException('Défi absent, expiré ou destiné à un autre compte.');
            }

            $payload = json_decode($request->getContent(), true, 32, \JSON_THROW_ON_ERROR);
            if (!\is_array($payload)) {
                throw new \RuntimeException('Corps de requête invalide.');
            }
            $label = mb_substr(trim(\is_string($payload['label'] ?? null) ? $payload['label'] : ''), 0, 80);
            $label = '' === $label ? 'YubiKey' : $label;

            $publicKeyCredential = $this->serializer->deserialize(
                json_encode($payload['credential'] ?? null, \JSON_THROW_ON_ERROR),
                PublicKeyCredential::class,
                'json',
            );
            $response = $publicKeyCredential->response;
            if (!$response instanceof AuthenticatorAttestationResponse) {
                throw new \RuntimeException('Réponse de clé inattendue.');
            }

            $record = $validator->check($response, $options, $request->getHost());
            if (null !== $this->credentialRepository->findOneByCredentialId($record->publicKeyCredentialId)) {
                throw new \RuntimeException('Cette clé est déjà enregistrée.');
            }

            $credential = WebauthnCredential::fromCredentialRecord($record, $label);
            $this->entityManager->persist($credential);
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            return $this->failCeremony(SecurityEventType::KeyFailure, $user, $exception);
        }

        $wasFirstKey = !$this->secondFactorSession->isVerified($user);
        $this->secondFactorSession->endEnrollment();
        if ($wasFirstKey) {
            $this->secondFactorSession->markVerified($user);
        }
        $this->securityEventLogger->log(SecurityEventType::KeyEnrolled, $user->getUsername());
        $this->addFlash('success', 'admin.security_key.added');

        $jsonResponse = new JsonResponse([
            'status' => 'ok',
            'redirect' => $this->generateUrl($wasFirstKey ? 'admin_dashboard' : 'admin_security_key_index'),
        ]);
        if ($wasFirstKey) {
            $jsonResponse->headers->setCookie($this->trustedDeviceCookie->create());
        }

        return $jsonResponse;
    }

    /**
     * Indique si le compte peut enregistrer une clé maintenant.
     *
     * Liens :
     * - Appelée par : enroll(), registrationOptions(), verifyRegistration()
     *
     * @param User $user compte connecté
     *
     * @return bool vrai si la clé est déjà vérifiée ou si une fenêtre d'enrôlement est ouverte
     */
    private function canRegisterKey(User $user): bool
    {
        return $this->secondFactorSession->isVerified($user) || $this->secondFactorSession->hasValidEnrollment($user);
    }

    /**
     * Contrôles communs des appels JSON : jeton CSRF (en-tête) et limite de débit.
     *
     * Liens :
     * - Appelée par : assertionOptions(), verifyAssertion(), registrationOptions(), verifyRegistration()
     *
     * @param Request $request requête JSON
     * @param User    $user    compte connecté (clé de la limite de débit, avec l'IP)
     *
     * @return JsonResponse|null une réponse d'erreur, ou null si la requête peut continuer
     */
    private function guardJsonRequest(Request $request, User $user): ?JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->jsonError('admin.second_factor.error.csrf', Response::HTTP_FORBIDDEN);
        }

        $limiter = $this->limiterFactory->create($user->getUserIdentifier().'|'.$request->getClientIp());
        if (!$limiter->consume()->isAccepted()) {
            $this->securityEventLogger->log(SecurityEventType::RateLimited, $user->getUsername());

            return $this->jsonError('admin.second_factor.error.too_many_attempts', Response::HTTP_TOO_MANY_REQUESTS);
        }

        return null;
    }

    /**
     * Journalise l'échec d'une cérémonie et renvoie un message générique (sans détail technique).
     *
     * Liens :
     * - Appelée par : verifyAssertion(), verifyRegistration()
     *
     * @param SecurityEventType $type      type d'évènement à journaliser
     * @param User              $user      compte concerné
     * @param \Throwable        $exception cause de l'échec (journal applicatif seulement)
     *
     * @return JsonResponse erreur 401
     */
    private function failCeremony(SecurityEventType $type, User $user, \Throwable $exception): JsonResponse
    {
        $this->logger->warning('Échec de vérification de la clé de sécurité', [
            'username' => $user->getUsername(),
            'reason' => $exception->getMessage(),
            'exception_class' => $exception::class,
        ]);
        $this->securityEventLogger->log($type, $user->getUsername());

        return $this->jsonError('admin.second_factor.error.verification_failed', Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Sérialise des options WebAuthn pour le navigateur.
     *
     * @param PublicKeyCredentialOptions $options options de cérémonie
     *
     * @return JsonResponse les options, avec status « ok »
     */
    private function jsonOptions(PublicKeyCredentialOptions $options): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($this->serializer->serialize($options, 'json', self::OPTIONS_CONTEXT), true, 64, \JSON_THROW_ON_ERROR);
        $data['status'] = 'ok';

        return new JsonResponse($data);
    }

    /**
     * Construit une réponse JSON d'erreur avec un message traduit.
     *
     * @param string $messageKey clé de traduction du message
     * @param int    $status     code HTTP
     */
    private function jsonError(string $messageKey, int $status): JsonResponse
    {
        return new JsonResponse(['status' => 'error', 'errorMessage' => $this->translator->trans($messageKey)], $status);
    }

    /**
     * Renvoie le compte admin connecté.
     *
     * @throws \LogicException si l'utilisateur n'est pas un User (impossible avec ROLE_ADMIN)
     */
    private function getAdminUser(): User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : throw new \LogicException('Compte admin attendu.');
    }
}
