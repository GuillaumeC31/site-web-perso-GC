<?php

declare(strict_types=1);

namespace App\DependencyInjection;

use App\Security\CloudflareIpRanges;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

/**
 * Processeur « trusted_proxies: » : liste des mandataires de confiance = plages officielles de Cloudflare (fichier
 * versionné config/cloudflare_ip_ranges.php) + mandataires de la variable donnée (SYMFONY_TRUSTED_PROXIES, ex.
 * l'adresse du cache de l’hébergeur établie par le diagnostic IP de l'admin).
 *
 * Avec la chaîne visiteur → Cloudflare → (cache de l’hébergeur) → Apache, Symfony lit X-Forwarded-For de droite à gauche en
 * sautant les mandataires de confiance : l'adresse retenue est la première qui n'en est pas un. Un en-tête envoyé
 * directement par un client (qui ne vient pas d'un mandataire de confiance) est ignoré.
 *
 * Usage (config/packages/framework.yaml) : trusted_proxies: '%env(trusted_proxies:SYMFONY_TRUSTED_PROXIES)%'
 *
 * Liens :
 * - Utilise : Security\CloudflareIpRanges
 * - Testé par : tests/Security/TrustedProxiesTest
 */
final readonly class TrustedProxiesEnvVarProcessor implements EnvVarProcessorInterface
{
    /**
     * Reçoit la racine du projet.
     *
     * @param string $projectDir racine du projet (fichier des plages)
     */
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    /**
     * Renvoie la liste, séparée par des virgules, des mandataires de confiance.
     *
     * Liens :
     * - Appelée par : le conteneur (résolution de %env(trusted_proxies:…)%), au démarrage du noyau
     *
     * @param string   $prefix préfixe (« trusted_proxies »)
     * @param string   $name   variable des mandataires supplémentaires
     * @param \Closure $getEnv lecture d'une variable d'environnement
     */
    public function getEnv(string $prefix, string $name, \Closure $getEnv): string
    {
        try {
            $extra = (string) $getEnv($name);
        } catch (\Throwable) {
            // Variable absente : seulement Cloudflare
            $extra = '';
        }
        $extras = array_values(array_filter(array_map('trim', explode(',', $extra)), static fn (string $proxy): bool => '' !== $proxy));

        return implode(',', array_unique([...CloudflareIpRanges::all($this->projectDir), ...$extras]));
    }

    /**
     * Déclare le préfixe « trusted_proxies ».
     *
     * @return array<string, string>
     */
    public static function getProvidedTypes(): array
    {
        return ['trusted_proxies' => 'string'];
    }
}
