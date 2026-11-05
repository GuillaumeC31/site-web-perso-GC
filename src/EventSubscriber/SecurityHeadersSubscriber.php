<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\CspNonceGenerator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Ajoute les en-têtes HTTP de sécurité à toutes les réponses de l'application.
 *
 * - Content-Security-Policy stricte : scripts et styles du site uniquement, plus
 *   les scripts en ligne qui portent le nonce de la requête ;
 * - HSTS (en HTTPS hors développement), X-Content-Type-Options, Referrer-Policy,
 *   Permissions-Policy, X-Frame-Options et frame-ancestors 'none'.
 *
 * En développement, la CSP autorise aussi le serveur Vite (HMR).
 *
 * Liens :
 * - Utilise : CspNonceGenerator
 * - Voir aussi : scripts/security/check-prod-headers.sh (vérification en production),
 *   tests/Security/SecurityHeadersTest
 */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    /** Origine du serveur de développement Vite (voir vite.config.js). */
    private const VITE_DEV_ORIGIN = 'https://127.0.0.1:5173';
    /** Origine de Cloudflare Turnstile (anti-robot des formulaires publics). */
    private const TURNSTILE_ORIGIN = 'https://challenges.cloudflare.com';

    /**
     * Reçoit les dépendances nécessaires au calcul des en-têtes.
     *
     * Liens :
     * - Appelée par : le conteneur Symfony (autowiring)
     *
     * @param CspNonceGenerator $nonceGenerator fournit le nonce de la requête
     * @param string            $environment    environnement du noyau (dev, test, prod)
     */
    public function __construct(
        private readonly CspNonceGenerator $nonceGenerator,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    /**
     * Déclare l'écoute de la réponse, après les autres écouteurs (priorité basse).
     *
     * Liens :
     * - Appelée par : l'EventDispatcher de Symfony
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['addSecurityHeaders', -10]];
    }

    /**
     * Pose les en-têtes de sécurité sur la réponse principale.
     *
     * Les en-têtes déjà présents ne sont pas écrasés (une action peut donc définir
     * sa propre politique si besoin).
     *
     * Liens :
     * - Appelée par : l'évènement kernel.response
     * - Utilise : self::buildContentSecurityPolicy()
     *
     * @param ResponseEvent $event évènement contenant la requête et la réponse
     */
    public function addSecurityHeaders(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $defaults = [
            'Content-Security-Policy' => $this->buildContentSecurityPolicy(),
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];

        // HSTS seulement en HTTPS et hors développement (évite de bloquer 127.0.0.1).
        if ('dev' !== $this->environment && $event->getRequest()->isSecure()) {
            $defaults['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($defaults as $name => $value) {
            if (!$headers->has($name)) {
                $headers->set($name, $value);
            }
        }
    }

    /**
     * Construit la valeur de l'en-tête Content-Security-Policy.
     *
     * Liens :
     * - Appelée par : self::addSecurityHeaders()
     * - Utilise : CspNonceGenerator::getNonce()
     *
     * @return string la politique complète, directives séparées par « ; »
     */
    private function buildContentSecurityPolicy(): string
    {
        $nonce = \sprintf("'nonce-%s'", $this->nonceGenerator->getNonce());
        $isDev = 'dev' === $this->environment;

        // Styles : 'unsafe-inline' sans nonce. Turbo injecte sa barre de progression
        // en ligne après chaque navigation, alors que la CSP reste celle de la
        // première page : un nonce de style la bloquerait. Les scripts, eux,
        // restent strictement limités au nonce.
        $directives = [
            'default-src' => ["'self'"],
            // Cloudflare Turnstile (formulaires de contact et de l'espace recruteur) : script et cadre du défi
            'script-src' => ["'self'", $nonce, self::TURNSTILE_ORIGIN],
            'style-src' => ["'self'", "'unsafe-inline'"],
            'img-src' => ["'self'", 'data:'],
            'font-src' => ["'self'"],
            'connect-src' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'none'"],
            'base-uri' => ["'self'"],
            'object-src' => ["'none'"],
            'frame-src' => [self::TURNSTILE_ORIGIN],
        ];

        if ($isDev) {
            // Vite sert les fichiers depuis son serveur et se connecte en WebSocket (HMR).
            $directives['script-src'][] = self::VITE_DEV_ORIGIN;
            $directives['style-src'][] = self::VITE_DEV_ORIGIN;
            $directives['font-src'][] = self::VITE_DEV_ORIGIN;
            $directives['img-src'][] = self::VITE_DEV_ORIGIN;
            $directives['connect-src'][] = self::VITE_DEV_ORIGIN;
            $directives['connect-src'][] = 'wss://127.0.0.1:5173';
        } else {
            $directives['upgrade-insecure-requests'] = [];
        }

        $parts = [];
        foreach ($directives as $name => $sources) {
            $parts[] = trim($name.' '.implode(' ', $sources));
        }

        return implode('; ', $parts);
    }
}
