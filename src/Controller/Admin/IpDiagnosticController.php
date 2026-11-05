<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Security\CloudflareIpRanges;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Diagnostic TEMPORAIRE de la chaîne de mandataires (v2.0.3) : affiche ce que le serveur reçoit (REMOTE_ADDR,
 * X-Forwarded-For, X-Forwarded-Proto, CF-Connecting-IP…) et l'adresse retenue par Symfony, pour établir l'adresse
 * du cache de l’hébergeur à ajouter dans SYMFONY_TRUSTED_PROXIES.
 *
 * Désactivé par défaut : la page n'existe (404) que si IP_DIAGNOSTIC=1 dans .env.local. Admin seulement (mot de
 * passe + YubiKey). Rien n'est enregistré ni journalisé. À retirer après usage : supprimer la ligne IP_DIAGNOSTIC
 * de .env.local, puis php bin/console cache:clear --env=prod.
 *
 * Liens :
 * - Route : admin_ip_diagnostic (/{ADMIN_PATH}/diagnostic-ip)
 * - Rend : templates/admin/ip_diagnostic/show.html.twig
 * - Utilise : Security\CloudflareIpRanges
 */
#[IsGranted('ADMIN_KEY_VERIFIED')]
#[Route('/diagnostic-ip', name: 'ip_diagnostic')]
final class IpDiagnosticController extends AbstractController
{
    /** En-têtes affichés (lus tels que reçus par PHP). */
    private const HEADERS = ['X-Forwarded-For', 'X-Forwarded-Proto', 'X-Forwarded-Host', 'X-Forwarded-Port', 'X-Real-IP', 'CF-Connecting-IP', 'CF-IPCountry', 'CF-Ray', 'Via', 'X-Varnish'];

    /**
     * Affiche la chaîne de mandataires vue par le serveur.
     *
     * Liens :
     * - Route : admin_ip_diagnostic (GET /{ADMIN_PATH}/diagnostic-ip)
     * - Rend : admin/ip_diagnostic/show.html.twig
     *
     * @param Request $request    requête courante
     * @param string  $enabled    IP_DIAGNOSTIC (« 1 » : page active)
     * @param string  $projectDir racine du projet (plages Cloudflare)
     *
     * @return Response la page de diagnostic (jamais mise en cache)
     *
     * @throws NotFoundHttpException si le diagnostic n'est pas activé
     */
    #[Route('', name: '', methods: ['GET'])]
    public function show(
        Request $request,
        #[Autowire('%env(default::IP_DIAGNOSTIC)%')]
        ?string $enabled,
        #[Autowire('%kernel.project_dir%')]
        string $projectDir,
    ): Response {
        if ('1' !== trim((string) $enabled)) {
            throw $this->createNotFoundException();
        }

        $remoteAddr = (string) $request->server->get('REMOTE_ADDR', '');
        $forwardedChain = array_values(array_filter(array_map('trim', explode(',', (string) $request->headers->get('X-Forwarded-For', '')))));
        $hops = array_map(fn (string $ip): array => $this->describe($ip, $projectDir), array_merge($forwardedChain, [$remoteAddr]));

        $headers = [];
        foreach (self::HEADERS as $name) {
            $headers[$name] = $request->headers->get($name);
        }

        $response = $this->render('admin/ip_diagnostic/show.html.twig', [
            'remote_addr' => $this->describe($remoteAddr, $projectDir),
            'headers' => $headers,
            'hops' => $hops,
            'client_ip' => $request->getClientIp(),
            'client_ips' => $request->getClientIps(),
            'is_secure' => $request->isSecure(),
            'trusted_proxies' => Request::getTrustedProxies(),
            'trusted_extra' => array_values(array_filter(Request::getTrustedProxies(), static fn (string $proxy): bool => !\in_array($proxy, CloudflareIpRanges::all($projectDir), true))),
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * Décrit une adresse : Cloudflare, adresse privée ou locale, mandataire de confiance.
     *
     * @param string $ip         adresse
     * @param string $projectDir racine du projet
     *
     * @return array{ip: string, cloudflare: bool, private: bool, trusted: bool}
     */
    private function describe(string $ip, string $projectDir): array
    {
        return [
            'ip' => $ip,
            'cloudflare' => CloudflareIpRanges::contains($projectDir, $ip),
            'private' => '' !== $ip && IpUtils::isPrivateIp($ip),
            'trusted' => '' !== $ip && IpUtils::checkIp($ip, Request::getTrustedProxies()),
        ];
    }
}
