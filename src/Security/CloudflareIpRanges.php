<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Plages d'adresses officielles de Cloudflare, lues dans le fichier versionné config/cloudflare_ip_ranges.php.
 *
 * Seules ces plages (et les mandataires ajoutés dans SYMFONY_TRUSTED_PROXIES, ex. le cache de l’hébergeur)
 * sont des mandataires de confiance : une requête qui n'en vient pas ne peut jamais imposer son adresse par
 * X-Forwarded-For (TrustedProxiesEnvVarProcessor).
 *
 * Liens :
 * - Utilisée par : DependencyInjection\TrustedProxiesEnvVarProcessor, Command\CloudflareIpRangesCommand,
 *   Controller\Admin\IpDiagnosticController
 * - Lit : config/cloudflare_ip_ranges.php
 */
final class CloudflareIpRanges
{
    /** Fichier versionné des plages (relatif à la racine du projet). */
    public const FILE = '/config/cloudflare_ip_ranges.php';

    /**
     * Renvoie toutes les plages (IPv4 puis IPv6) du fichier versionné.
     *
     * Liens :
     * - Appelée par : TrustedProxiesEnvVarProcessor::getEnv(), contains(), CloudflareIpRangesCommand
     *
     * @param string $projectDir racine du projet
     *
     * @return list<string> plages au format CIDR
     */
    public static function all(string $projectDir): array
    {
        $ranges = self::read($projectDir);

        return [...$ranges['ipv4'], ...$ranges['ipv6']];
    }

    /**
     * Renvoie les plages du fichier, par famille, avec leur date de relevé.
     *
     * Liens :
     * - Appelée par : all(), CloudflareIpRangesCommand::execute()
     *
     * @param string $projectDir racine du projet
     *
     * @return array{checked_at: string, ipv4: list<string>, ipv6: list<string>}
     *
     * @throws \RuntimeException si le fichier est absent ou mal formé
     */
    public static function read(string $projectDir): array
    {
        $file = $projectDir.self::FILE;
        $data = is_file($file) ? require $file : null;
        if (!\is_array($data) || !\is_array($data['ipv4'] ?? null) || !\is_array($data['ipv6'] ?? null)) {
            throw new \RuntimeException('Plages Cloudflare : '.self::FILE.' absent ou mal formé.');
        }

        return [
            'checked_at' => (string) ($data['checked_at'] ?? ''),
            'ipv4' => array_values(array_map('strval', $data['ipv4'])),
            'ipv6' => array_values(array_map('strval', $data['ipv6'])),
        ];
    }

    /**
     * Indique si une adresse appartient à une plage de Cloudflare.
     *
     * Liens :
     * - Appelée par : IpDiagnosticController::show()
     *
     * @param string $projectDir racine du projet
     * @param string $ip         adresse à tester
     */
    public static function contains(string $projectDir, string $ip): bool
    {
        return '' !== $ip && IpUtils::checkIp($ip, self::all($projectDir));
    }
}
