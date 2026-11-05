<?php

declare(strict_types=1);

namespace App\Service\Dev;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Reconnaît précisément ce qui vient des tests de bout en bout, en développement seulement (ailleurs : jamais) :
 * - requête d'un navigateur sans interface ou d'un client de test (« HeadlessChrome », Lighthouse, « Playwright/ ») ;
 * - session du compte admin de test « e2e_axe » ou d'un recruteur en « example.test » ;
 * - commande lancée par la suite Playwright (E2E_DATABASE=1, E2eMode) ;
 * - adresse e-mail du domaine réservé aux tests « example.test ».
 * Une action réelle (navigateur normal, compte réel) n'est jamais reconnue comme un test : le nettoyage de fin de
 * suite ne la touche pas (décision du 2026-10-29, aucune suppression selon une plage horaire).
 *
 * Liens :
 * - Utilisé par : EventListener\TestTraceListener (repère test_trace), Service\Mail\ResilientMailer (étiquette
 *   Mailpit, battement « dernier e-mail parti »), Service\Ai\E2eFakeAiProvider (IA simulée pour les tests seulement)
 * - Voir aussi : TestTraceCleaner (mêmes repères)
 * - Testé par : tests/Service/Dev/TestContextTest
 */
final readonly class TestContext
{
    /** Navigateurs et clients HTTP des tests (aussi repères du journal de sécurité). */
    public const TEST_USER_AGENTS = ['HeadlessChrome', 'Chrome-Lighthouse', 'Playwright/'];

    /** Compte admin des tests (app:e2e:private-sessions). */
    public const TEST_ADMIN = 'e2e_axe';

    /** Domaine réservé aux tests (RFC 2606). */
    public const TEST_DOMAIN = 'example.test';

    /** Étiquette Mailpit des e-mails envoyés par les tests (en-tête X-Tags, supprimés en fin de suite). */
    public const MAILPIT_TAG = 'e2e-trace';

    /**
     * Reçoit la requête et la session courantes.
     *
     * @param string $environment environnement Symfony (repères en développement seulement)
     */
    public function __construct(
        private RequestStack $requestStack,
        private ?Security $security,
        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
    }

    /**
     * Indique si la requête en cours vient des tests (navigateur de test, compte de test) ; toujours faux hors
     * développement et en ligne de commande.
     *
     * Liens :
     * - Appelée par : EventListener\TestTraceListener, ResilientMailer, E2eFakeAiProvider
     */
    public function isTestRequest(): bool
    {
        if ('dev' !== $this->environment) {
            return false;
        }
        // Commande lancée par la suite Playwright (E2E_DATABASE=1)
        if (null === $this->requestStack->getMainRequest() && E2eMode::isActive($this->environment)) {
            return true;
        }
        $userAgent = (string) $this->requestStack->getMainRequest()?->headers->get('User-Agent');
        foreach (self::TEST_USER_AGENTS as $marker) {
            if (str_contains($userAgent, $marker)) {
                return true;
            }
        }
        $identifier = (string) $this->security?->getUser()?->getUserIdentifier();

        return self::TEST_ADMIN === $identifier || self::isTestAddress($identifier);
    }

    /**
     * Indique si les repères des tests s'appliquent (environnement de développement seulement).
     *
     * Liens :
     * - Appelée par : ResilientMailer::tagIfTest()
     */
    public function isDevelopment(): bool
    {
        return 'dev' === $this->environment;
    }

    /**
     * Indique si une adresse e-mail appartient au domaine des tests (« x@example.test », « x@y.example.test »).
     *
     * Liens :
     * - Appelée par : isTestRequest(), ResilientMailer, EmailLog
     */
    public static function isTestAddress(string $address): bool
    {
        $address = strtolower(trim($address));

        return str_ends_with($address, '@'.self::TEST_DOMAIN) || str_ends_with($address, '.'.self::TEST_DOMAIN);
    }
}
