<?php

declare(strict_types=1);

namespace App\Service\Dev;

/**
 * Mode « tests de bout en bout » du processus PHP en cours, en DÉVELOPPEMENT seulement (jamais en test PHPUnit ni en
 * production) : requête d'un navigateur ou client de test (HeadlessChrome, Lighthouse, Playwright), ou commande lancée
 * par la suite Playwright (variable E2E_DATABASE=1, posée par playwright.config.js).
 *
 * Dans ce mode, le processus travaille sur la base dédiée « <base>_e2e » (E2eDatabaseEnvVarProcessor) et sur son
 * propre dossier de cache (Kernel::getCacheDir()) : les réglages réels de la base de développement ne sont jamais
 * modifiés par une suite, et les caches (tableau de bord, limiteurs, IA) ne se mélangent pas (décision du 2026-10-29).
 *
 * Classe sans dépendance : utilisée par le noyau avant la construction du conteneur.
 *
 * Liens :
 * - Utilisée par : App\Kernel::getCacheDir(), App\DependencyInjection\E2eDatabaseEnvVarProcessor, TestContext,
 *   Command\E2eDatabaseCommand
 * - Testée par : tests/Service/Dev/E2eModeTest (dont : toujours inactif en production)
 */
final class E2eMode
{
    /** Variable d'environnement des commandes lancées par la suite Playwright. */
    public const ENV_VAR = 'E2E_DATABASE';

    /** Suffixe du nom de la base dédiée aux tests de bout en bout. */
    public const DATABASE_SUFFIX = '_e2e';

    /**
     * Indique si le processus en cours relève des tests de bout en bout.
     *
     * Liens :
     * - Appelée par : Kernel::getCacheDir(), E2eDatabaseEnvVarProcessor::getEnv(), TestContext::isTestRequest()
     *
     * @param string               $environment environnement Symfony (seul « dev » peut être en mode e2e)
     * @param array<string, mixed> $server      variables du serveur ($_SERVER) ; null : celles du processus
     */
    public static function isActive(string $environment, ?array $server = null): bool
    {
        if ('dev' !== $environment) {
            return false;
        }
        $server ??= $_SERVER;
        // Sans agent : commande (la suite pose E2E_DATABASE=1) ; avec agent : requête HTTP
        if (!isset($server['HTTP_USER_AGENT'])) {
            return '1' === (string) ($server[self::ENV_VAR] ?? getenv(self::ENV_VAR));
        }
        foreach (TestContext::TEST_USER_AGENTS as $marker) {
            if (str_contains((string) $server['HTTP_USER_AGENT'], $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Renvoie l'adresse de la base dédiée : même serveur et même compte, nom suffixé par « _e2e ».
     *
     * Liens :
     * - Appelée par : E2eDatabaseEnvVarProcessor::getEnv()
     *
     * @param string $url DATABASE_URL de développement
     *
     * @throws \InvalidArgumentException si l'adresse n'a pas de nom de base
     */
    public static function databaseUrl(string $url): string
    {
        $updated = preg_replace_callback('#^([a-z0-9+]+://[^/]*/)([^?/]+)#i', static fn (array $m): string => $m[1].(str_ends_with($m[2], self::DATABASE_SUFFIX) ? $m[2] : $m[2].self::DATABASE_SUFFIX), $url, 1, $count);
        if (1 !== $count || null === $updated) {
            throw new \InvalidArgumentException('DATABASE_URL sans nom de base : base des tests de bout en bout introuvable.');
        }

        return $updated;
    }
}
