<?php

declare(strict_types=1);

namespace App\Security;

use App\Enum\SecurityEventType;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Vérifie la réponse de Cloudflare Turnstile (anti-robot des formulaires publics, CDC §10).
 *
 * Le jeton posté par le widget (champ cf-turnstile-response) est validé auprès de l'API
 * siteverify de Cloudflare. Clés de test « toujours valide » en local ; vraies clés en production.
 * En cas d'erreur réseau, le jeton est refusé (le formulaire peut être renvoyé).
 *
 * Erreurs de CONFIGURATION (v2.0.3) notées au journal de sécurité (SecurityEventType::TurnstileConfigError), pour
 * l'alerte « Turnstile » du tableau de bord : clé secrète refusée par Cloudflare (codes serveur), clé de site
 * invalide ou nom d'hôte non autorisé (code d'erreur du widget, posté par turnstile_controller.js dans le champ
 * turnstile_error). Les refus normaux (jeton absent, faux ou expiré, robots) n'y sont jamais notés.
 *
 * Liens :
 * - Utilisé par : ContactController, RecruiterAreaController
 * - Utilise : SecurityEventLogger (erreurs de configuration), RequestStack (code d'erreur du widget)
 * - Testé par : tests/Controller/ContactControllerTest (réponses simulées : tests/Support/MockHttpResponseFactory)
 */
final readonly class TurnstileVerifier
{
    /** Adresse de l'API de vérification. */
    public const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** Nom du champ posté par le widget. */
    public const RESPONSE_FIELD = 'cf-turnstile-response';

    /** Champ caché où le navigateur note le code d'erreur du widget (assets/controllers/turnstile_controller.js). */
    public const CLIENT_ERROR_FIELD = 'turnstile_error';

    /** Codes de l'API siteverify qui signalent une erreur de configuration du serveur (clé secrète). */
    public const SERVER_CONFIG_CODES = ['missing-input-secret', 'invalid-input-secret'];

    /**
     * Débuts des codes d'erreur du widget qui signalent une erreur de configuration : 1101xx (clé de site invalide),
     * 1102xx (nom d'hôte non autorisé), 1104xx (paramètres du widget), 4000xx (clé de site invalide ou désactivée).
     * Les autres (échec du défi, délai, réseau) sont des refus normaux.
     */
    public const CLIENT_CONFIG_PREFIXES = ['1101', '1102', '1104', '4000'];

    /**
     * Reçoit les dépendances.
     *
     * @param HttpClientInterface $httpClient          client HTTP
     * @param LoggerInterface     $logger              journal (erreurs de l'API)
     * @param string              $secretKey           clé secrète Turnstile (TURNSTILE_SECRET_KEY)
     * @param SecurityEventLogger $securityEventLogger journal de sécurité (erreurs de configuration)
     * @param RequestStack        $requestStack        requête courante (code d'erreur du widget)
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private SecurityEventLogger $securityEventLogger,
        private RequestStack $requestStack,
        #[Autowire('%env(TURNSTILE_SECRET_KEY)%')]
        private string $secretKey,
    ) {
    }

    /** Message d'un jeton refusé (absent, invalide, ou vérification impossible). */
    public const ERROR_FAILED = 'form.turnstile_failed';

    /** Message d'un jeton expiré ou déjà utilisé (code Cloudflare « timeout-or-duplicate »). */
    public const ERROR_EXPIRED = 'form.turnstile_expired';

    /**
     * Indique si le jeton Turnstile est valide.
     *
     * Liens :
     * - Appelée par : les tests ; les contrôleurs utilisent check() (message adapté)
     *
     * @param string|null $token    jeton posté par le widget
     * @param string|null $remoteIp adresse IP du visiteur
     *
     * @return bool vrai si Cloudflare confirme le jeton
     */
    public function verify(?string $token, ?string $remoteIp): bool
    {
        return null === $this->check($token, $remoteIp);
    }

    /**
     * Vérifie le jeton et renvoie, s'il est refusé, la clé de traduction du message à afficher.
     *
     * Liens :
     * - Appelée par : ContactController::show(), RecruiterAreaController::passesChecks()
     *
     * @param string|null $token    jeton posté par le widget
     * @param string|null $remoteIp adresse IP du visiteur
     *
     * @return string|null null si le jeton est valide ; sinon ERROR_EXPIRED (jeton expiré ou déjà servi :
     *                     le widget se relance) ou ERROR_FAILED (refusé, absent, Cloudflare injoignable)
     */
    public function check(?string $token, ?string $remoteIp): ?string
    {
        if (null === $token || '' === $token) {
            $this->recordClientConfigError();

            return self::ERROR_FAILED;
        }

        try {
            $data = $this->httpClient->request('POST', self::VERIFY_URL, [
                'body' => array_filter(['secret' => $this->secretKey, 'response' => $token, 'remoteip' => $remoteIp]),
                'timeout' => 5,
            ])->toArray(false);
        } catch (\Throwable $exception) {
            $this->logger->warning('Turnstile : vérification impossible ({message}).', ['message' => $exception->getMessage()]);

            return self::ERROR_FAILED;
        }
        if (true === ($data['success'] ?? false)) {
            return null;
        }
        $codes = \is_array($data['error-codes'] ?? null) ? array_values(array_map('strval', $data['error-codes'])) : [];
        // Codes d'erreur seulement (ni jeton, ni adresse IP)
        $this->logger->info('Turnstile : jeton refusé ({codes}).', ['codes' => implode(', ', $codes)]);
        foreach (array_intersect($codes, self::SERVER_CONFIG_CODES) as $code) {
            $this->securityEventLogger->log(SecurityEventType::TurnstileConfigError, 'serveur '.$code);
        }

        return \in_array('timeout-or-duplicate', $codes, true) ? self::ERROR_EXPIRED : self::ERROR_FAILED;
    }

    /**
     * Indique si un code d'erreur du widget signale une erreur de configuration (clé de site, nom d'hôte…).
     *
     * Liens :
     * - Appelée par : recordClientConfigError() ; tests/Security/TurnstileConfigAlertTest
     *
     * @param string $code code d'erreur du widget (6 chiffres)
     */
    public static function isClientConfigCode(string $code): bool
    {
        if (1 !== preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        foreach (self::CLIENT_CONFIG_PREFIXES as $prefix) {
            if (str_starts_with($code, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Note au journal de sécurité le code d'erreur de configuration posté par le widget, s'il y en a un.
     *
     * Liens :
     * - Appelée par : check() (formulaire envoyé sans jeton)
     */
    private function recordClientConfigError(): void
    {
        $code = trim((string) $this->requestStack->getCurrentRequest()?->request->get(self::CLIENT_ERROR_FIELD, ''));
        if (self::isClientConfigCode($code)) {
            $this->securityEventLogger->log(SecurityEventType::TurnstileConfigError, 'navigateur '.$code);
        }
    }
}
