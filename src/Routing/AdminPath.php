<?php

declare(strict_types=1);

namespace App\Routing;

/**
 * Fournit le segment d'URL de l'administration, lu dans la variable ADMIN_PATH.
 *
 * Le chemin de l'admin n'est jamais affiché dans l'interface publique : le rendre
 * configurable évite qu'il soit devinable (/admin). Il est résolu au moment de la
 * compilation du conteneur et des routes, car Symfony n'accepte pas %env()% dans
 * les chemins de routes ni dans access_control.
 *
 * Après un changement de ADMIN_PATH, il faut vider le cache (bin/console cache:clear).
 *
 * Liens :
 * - Utilisée par : config/packages/admin_path.php (paramètre app.admin_path),
 *   config/routes/admin.php (préfixe des routes admin)
 * - Voir aussi : config/packages/security.yaml (access_control sur ^/%app.admin_path%)
 */
final class AdminPath
{
    /** Valeur utilisée quand ADMIN_PATH est absente ou vide (développement). */
    public const DEFAULT_PATH = 'admin';

    /** Format autorisé : minuscules, chiffres et tirets, de 3 à 64 caractères. */
    private const PATTERN = '/^[a-z0-9](?:[a-z0-9-]{1,62}[a-z0-9])$/';

    /**
     * Lit ADMIN_PATH dans l'environnement et renvoie le segment validé.
     *
     * Liens :
     * - Appelée par : config/packages/admin_path.php, config/routes/admin.php
     * - Utilise : self::normalize()
     *
     * @return string le segment d'URL, sans barre oblique (ex. « admin »)
     *
     * @throws \InvalidArgumentException si la valeur fournie a un format invalide
     */
    public static function fromEnvironment(): string
    {
        $rawValue = $_SERVER['ADMIN_PATH'] ?? $_ENV['ADMIN_PATH'] ?? getenv('ADMIN_PATH');

        return self::normalize(\is_string($rawValue) ? $rawValue : null);
    }

    /**
     * Nettoie et valide un segment d'URL d'administration.
     *
     * Liens :
     * - Appelée par : self::fromEnvironment(), tests/Routing/AdminPathTest
     *
     * Exemple : normalize('/Console-7f3a/') renvoie « console-7f3a ».
     *
     * @param string|null $value valeur brute (null ou vide : valeur par défaut)
     *
     * @return string le segment validé, sans barre oblique
     *
     * @throws \InvalidArgumentException si le format n'est pas respecté
     */
    public static function normalize(?string $value): string
    {
        $path = strtolower(trim((string) $value, " \t\n\r\0\x0B/"));

        if ('' === $path) {
            return self::DEFAULT_PATH;
        }

        if (1 !== preg_match(self::PATTERN, $path)) {
            throw new \InvalidArgumentException(\sprintf('ADMIN_PATH invalide « %s » : minuscules, chiffres et tirets uniquement (3 à 64 caractères).', $path));
        }

        return $path;
    }
}
