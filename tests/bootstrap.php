<?php

declare(strict_types=1);

/*
 * Amorçage des tests PHPUnit : charge l'autoloader et les fichiers .env
 * (.env puis .env.test, voir phpunit.dist.xml pour APP_ENV=test), puis met la base de test à jour
 * (création si besoin, migrations) avant chaque suite : une table oubliée (ex. « failed_email
 * introuvable » dans var/log/test.log) ne peut plus fausser les résultats.
 * Désactivable avec TESTS_SKIP_MIGRATIONS=1 (ex. suite lancée sans base de données).
 */

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';
// Horloge simulée des liens signés (lien de connexion expiré après 30 minutes)
require __DIR__.'/Support/signature_clock.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

if ('1' !== ($_SERVER['TESTS_SKIP_MIGRATIONS'] ?? getenv('TESTS_SKIP_MIGRATIONS'))) {
    $console = escapeshellarg(dirname(__DIR__).'/bin/console');
    foreach ([
        'doctrine:database:create --if-not-exists --no-interaction',
        'doctrine:migrations:migrate --no-interaction --allow-no-migration',
    ] as $command) {
        exec(escapeshellarg(PHP_BINARY).' '.$console.' '.$command.' --env=test --quiet 2>&1', $output, $status);
        if (0 !== $status) {
            fwrite(STDERR, "Base de test : échec de « {$command} » :\n".implode("\n", $output)."\n");
            exit(1);
        }
    }
}
