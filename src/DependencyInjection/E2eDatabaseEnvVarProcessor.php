<?php

declare(strict_types=1);

namespace App\DependencyInjection;

use App\Service\Dev\E2eMode;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

/**
 * Processeur « e2e_db: » : en développement, pour une requête ou une commande des tests de bout en bout (E2eMode),
 * renvoie l'adresse de la base dédiée « <base>_e2e » ; sinon l'adresse telle quelle. Les suites Playwright ne
 * modifient ainsi jamais la base de développement (décision du 2026-10-29).
 *
 * Usage (config/packages/doctrine.yaml, when@dev) : url: '%env(e2e_db:resolve:DATABASE_URL)%'
 *
 * Liens :
 * - Utilise : Service\Dev\E2eMode
 * - Testé par : tests/Service/Dev/E2eModeTest
 */
final readonly class E2eDatabaseEnvVarProcessor implements EnvVarProcessorInterface
{
    /**
     * Reçoit l'environnement Symfony.
     *
     * @param string $environment environnement (base dédiée en « dev » seulement)
     */
    public function __construct(
        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
    }

    /**
     * Renvoie l'adresse de la base à utiliser.
     *
     * Liens :
     * - Appelée par : le conteneur (résolution de %env(e2e_db:…)%)
     */
    public function getEnv(string $prefix, string $name, \Closure $getEnv): string
    {
        $url = (string) $getEnv($name);

        return E2eMode::isActive($this->environment) ? E2eMode::databaseUrl($url) : $url;
    }

    /**
     * Déclare le préfixe « e2e_db ».
     *
     * @return array<string, string>
     */
    public static function getProvidedTypes(): array
    {
        return ['e2e_db' => 'string'];
    }
}
