<?php

declare(strict_types=1);

namespace App\Tests\Deploy;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Le déploiement (scripts/deploy.sh, étape 0) refuse des clés Turnstile vides ou de test
 * (production et environnement de test) : contrôle de scripts/security/check-turnstile-keys.php sur des
 * fichiers d'environnement fictifs. Les valeurs ne sont jamais affichées en entier.
 *
 * Liens :
 * - Couvre : scripts/security/check-turnstile-keys.php, appel dans scripts/deploy.sh
 */
final class TurnstileKeysCheckTest extends TestCase
{
    /** Clés au format des vraies clés (fictives). */
    private const REAL_SITE = '0x4AAAAEXEMPLEXXXXXXXX';
    private const REAL_SECRET = '0x4AAAAEXEMPLEXXXXXXXXXXXXXXXXXXXX';

    /**
     * Vérifie le résultat du contrôle pour un fichier d'environnement donné.
     *
     * @param string $env      contenu du fichier .env.local fictif
     * @param int    $exitCode code de sortie attendu
     * @param string $message  extrait attendu de la sortie
     */
    #[DataProvider('provideEnvFiles')]
    public function testKeysAreChecked(string $env, int $exitCode, string $message): void
    {
        $file = tempnam(sys_get_temp_dir(), 'env_');
        file_put_contents((string) $file, $env);
        // PHP du test lui-même : même contrôle sous Windows (poste) et sous Linux (l’hébergeur)
        $process = new Process([\PHP_BINARY, 'scripts/security/check-turnstile-keys.php', (string) $file], \dirname(__DIR__, 2));
        $process->run();
        @unlink((string) $file);

        $output = $process->getOutput().$process->getErrorOutput();
        self::assertSame($exitCode, $process->getExitCode(), $output);
        self::assertStringContainsString($message, $output);
        self::assertStringNotContainsString(self::REAL_SECRET, $output, 'Le secret n\'est jamais affiché.');
    }

    /**
     * Fichiers d'environnement : vraies clés, absentes, vides, clés de test du site et secrètes.
     *
     * @return iterable<string, array{string, int, string}>
     */
    public static function provideEnvFiles(): iterable
    {
        $real = 'TURNSTILE_SITE_KEY='.self::REAL_SITE."\nTURNSTILE_SECRET_KEY=".self::REAL_SECRET."\n";
        yield 'vraies clés' => [$real, 0, 'Clés Turnstile : OK'];
        yield 'vraies clés entre guillemets' => ['TURNSTILE_SITE_KEY="'.self::REAL_SITE."\"\nTURNSTILE_SECRET_KEY='".self::REAL_SECRET."'\n", 0, 'OK'];
        yield 'clés absentes' => ["APP_ENV=prod\n", 1, 'TURNSTILE_SITE_KEY est vide'];
        yield 'secret vide' => ['TURNSTILE_SITE_KEY='.self::REAL_SITE."\nTURNSTILE_SECRET_KEY=\n", 1, 'TURNSTILE_SECRET_KEY est vide'];
        yield 'site de test « toujours valide »' => ["TURNSTILE_SITE_KEY=1x00000000000000000000AA\nTURNSTILE_SECRET_KEY=".self::REAL_SECRET."\n", 1, 'clé de TEST'];
        yield 'site de test « toujours refuser »' => ["TURNSTILE_SITE_KEY=2x00000000000000000000AB\nTURNSTILE_SECRET_KEY=".self::REAL_SECRET."\n", 1, 'clé de TEST'];
        yield 'site de test « interactif »' => ["TURNSTILE_SITE_KEY=3x00000000000000000000FF\nTURNSTILE_SECRET_KEY=".self::REAL_SECRET."\n", 1, 'clé de TEST'];
        yield 'secret de test « toujours valide »' => ['TURNSTILE_SITE_KEY='.self::REAL_SITE."\nTURNSTILE_SECRET_KEY=1x0000000000000000000000000000000AA\n", 1, 'TURNSTILE_SECRET_KEY est une clé de TEST'];
        yield 'secret de test « jeton déjà utilisé »' => ['TURNSTILE_SITE_KEY='.self::REAL_SITE."\nTURNSTILE_SECRET_KEY=3x0000000000000000000000000000000AA\n", 1, 'clé de TEST'];
        yield 'dernière valeur retenue' => ["TURNSTILE_SITE_KEY=1x00000000000000000000AA\nTURNSTILE_SITE_KEY=".self::REAL_SITE."\nTURNSTILE_SECRET_KEY=".self::REAL_SECRET."\n", 0, 'OK'];
    }

    /**
     * Vérifie que le script de déploiement appelle le contrôle avant toute modification (étape 0).
     */
    public function testDeployScriptRunsTheCheckFirst(): void
    {
        $script = (string) file_get_contents(\dirname(__DIR__, 2).'/scripts/deploy.sh');
        $check = strpos($script, 'php scripts/security/check-turnstile-keys.php .env.local');
        self::assertNotFalse($check);
        self::assertLessThan(strpos($script, 'git pull --ff-only'), $check);
        self::assertStringContainsString('set -euo pipefail', $script, 'Une erreur du contrôle arrête le déploiement.');
    }
}
