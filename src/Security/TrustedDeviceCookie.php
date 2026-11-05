<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\SiteSettingsRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Cookie « appareil de confiance » : posé après une connexion admin complète (mot de passe + YubiKey), il fait
 * du cœur du pied de page un lien vers l'admin sur cet appareil seulement.
 *
 * Contenu : date d'expiration et numéro de génération, signés par HMAC-SHA256 (clé dérivée de APP_SECRET). Le
 * cookie ne donne AUCUN accès : l'admin exige toujours mot de passe et YubiKey. Il ne sert qu'à ne pas écrire
 * l'adresse de l'admin dans le HTML des autres visiteurs. La déconnexion ne le supprime pas ; le bouton
 * « Révoquer tous les appareils de confiance » (Clés de sécurité) incrémente la génération et les invalide tous.
 *
 * Liens :
 * - Utilisé par : Admin\SecondFactorController (pose du cookie), Twig\AdminLinkExtension (pied de page),
 *   Admin\SecurityKeyController::revokeTrustedDevices()
 * - Utilise : SiteSettingsRepository (SiteSettings::getTrustedDeviceGeneration())
 * - Voir aussi : EventSubscriber\TrustedDeviceCacheSubscriber (en-têtes de cache), tests/Controller/TrustedDeviceFooterTest
 */
final class TrustedDeviceCookie
{
    /** Nom du cookie. */
    public const NAME = 'gc_trusted_device';

    /** Durée de validité (jours). */
    public const LIFETIME_DAYS = 180;

    /** Contexte de dérivation de la clé HMAC (la clé n'est jamais APP_SECRET lui-même). */
    private const KEY_CONTEXT = 'gc-trusted-device-cookie-v1';

    /**
     * Résultat mémorisé par requête (le pied de page peut demander plusieurs fois).
     *
     * @var \WeakMap<Request, bool>|null
     */
    private ?\WeakMap $validity = null;

    /**
     * Reçoit le dépôt des réglages, l'horloge et le secret de l'application.
     *
     * @param SiteSettingsRepository $settingsRepository réglages (numéro de génération)
     * @param ClockInterface         $clock              horloge (remplaçable en test)
     * @param string                 $appSecret          APP_SECRET (kernel.secret)
     */
    public function __construct(
        private readonly SiteSettingsRepository $settingsRepository,
        private readonly ClockInterface $clock,
        #[Autowire('%kernel.secret%')]
        private readonly string $appSecret,
    ) {
    }

    /**
     * Construit le cookie signé à déposer sur la réponse (180 jours, HttpOnly, Secure, SameSite=Lax).
     *
     * Liens :
     * - Appelée par : Admin\SecondFactorController::verifyAssertion(), Admin\SecondFactorController::verifyRegistration()
     *
     * @return Cookie le cookie, valable pour tout le site (chemin « / »)
     */
    public function create(): Cookie
    {
        $expiresAt = $this->clock->now()->modify('+'.self::LIFETIME_DAYS.' days');

        return Cookie::create(self::NAME)
            ->withValue($this->sign($expiresAt->getTimestamp(), $this->currentGeneration()))
            ->withExpires($expiresAt)
            ->withPath('/')
            ->withSecure(true)
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX);
    }

    /**
     * Indique si la requête porte un cookie valide : signature exacte, date non dépassée, génération actuelle.
     *
     * Liens :
     * - Appelée par : Twig\AdminLinkExtension::getAdminLink()
     *
     * @param Request $request requête courante
     *
     * @return bool faux sans cookie, ou si le cookie est modifié, expiré ou révoqué
     */
    public function isValid(Request $request): bool
    {
        $this->validity ??= new \WeakMap();

        return $this->validity[$request] ??= $this->check((string) $request->cookies->get(self::NAME, ''));
    }

    /**
     * Fabrique la valeur signée « expiration.génération.signature » (utile aussi aux tests).
     *
     * Liens :
     * - Appelée par : self::create(), tests/Controller/TrustedDeviceFooterTest
     *
     * @param int $expiresAt  date d'expiration (horodatage Unix)
     * @param int $generation numéro de génération
     *
     * @return string la valeur du cookie
     */
    public function sign(int $expiresAt, int $generation): string
    {
        $payload = $expiresAt.'.'.$generation;

        return $payload.'.'.$this->hmac($payload);
    }

    /**
     * Contrôle une valeur de cookie.
     *
     * @param string $value valeur reçue (vide si absente)
     */
    private function check(string $value): bool
    {
        if (1 !== preg_match('/^(\d{1,12})\.(\d{1,9})\.([A-Za-z0-9_-]{43})$/', $value, $parts)) {
            return false;
        }

        // Comparaison à temps constant : la signature n'est pas devinable caractère par caractère.
        if (!hash_equals($this->hmac($parts[1].'.'.$parts[2]), $parts[3])) {
            return false;
        }

        return (int) $parts[1] > $this->clock->now()->getTimestamp()
            && (int) $parts[2] === $this->currentGeneration();
    }

    /**
     * Calcule la signature HMAC-SHA256 (base64 sans remplissage, adapté aux URL) d'un contenu.
     *
     * @param string $payload contenu signé
     */
    private function hmac(string $payload): string
    {
        $key = hash_hmac('sha256', self::KEY_CONTEXT, $this->appSecret, true);

        return rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $key, true)), '+/', '-_'), '=');
    }

    /**
     * Renvoie le numéro de génération actuel (réglages du site).
     */
    private function currentGeneration(): int
    {
        return $this->settingsRepository->getOrCreate()->getTrustedDeviceGeneration();
    }
}
