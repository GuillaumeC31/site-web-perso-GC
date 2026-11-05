<?php

declare(strict_types=1);

/*
 * Plages d'adresses officielles de Cloudflare (proxy placé devant guillaume-couget.fr), déclarées comme mandataires
 * de confiance : seule une requête qui arrive d'une de ces adresses (ou d'un mandataire de SYMFONY_TRUSTED_PROXIES)
 * peut fournir l'adresse du visiteur par l'en-tête X-Forwarded-For.
 *
 * Source : https://www.cloudflare.com/ips-v4 et https://www.cloudflare.com/ips-v6 (relevées le 05/11/2026).
 * Contrôle : php bin/console app:security:cloudflare-ips (compare à la liste publiée ; en cas d'écart, alerte et
 * liste à recopier ici à la main : aucune mise à jour automatique).
 *
 * Liens :
 * - Lu par : App\DependencyInjection\TrustedProxiesEnvVarProcessor (config/packages/framework.yaml),
 *   App\Command\CloudflareIpRangesCommand, App\Controller\Admin\IpDiagnosticController
 */

return [
    'checked_at' => '2026-11-05',
    'ipv4' => [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
    ],
    'ipv6' => [
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ],
];
