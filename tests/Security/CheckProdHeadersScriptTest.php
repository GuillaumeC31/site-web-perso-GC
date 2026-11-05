<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * scripts/security/check-prod-headers.sh : contrôle la page, un fichier de build/, une 404 et un PDF, et signale
 * un en-tête manquant ou envoyé deux fois. Le vrai curl est remplacé par un faux qui renvoie des en-têtes fixes.
 *
 * Liens :
 * - Couvre : scripts/security/check-prod-headers.sh
 * - Voir aussi : tests/Security/SingleSecurityHeadersTest.php
 */
final class CheckProdHeadersScriptTest extends TestCase
{
    /** Dossier du faux curl (supprimé à la fin). */
    private string $fakeBin = '';

    /**
     * Supprime le faux curl.
     */
    protected function tearDown(): void
    {
        if ('' !== $this->fakeBin) {
            (new Filesystem())->remove($this->fakeBin);
        }
    }

    /**
     * En-têtes complets, une fois chacun : le contrôle réussit (code 0) sur les quatre adresses.
     */
    public function testCleanHeadersPass(): void
    {
        $process = $this->runWithHeaders(false);

        self::assertSame(0, $process->getExitCode(), $process->getOutput());
        self::assertStringContainsString('✓ En-têtes de sécurité OK', $process->getOutput());
        foreach (['Page HTML', 'Fichier de build', 'Page 404', 'PDF', 'Page avec formulaire', 'Page privée'] as $label) {
            self::assertStringContainsString($label.' :', $process->getOutput());
        }
        self::assertStringContainsString('/build/assets/app-TEST.css', $process->getOutput());
    }

    /**
     * Un en-tête envoyé deux fois (cas de la v2.0.0) est signalé comme doublon et fait échouer le contrôle.
     */
    public function testDuplicateHeaderIsReported(): void
    {
        $process = $this->runWithHeaders(true);

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('x-frame-options : présent 2 fois (doublon)', $process->getOutput());
        self::assertStringContainsString('Page HTML:x-frame-options-doublon', $process->getOutput());
    }

    /**
     * Lance le script avec un faux curl en tête du PATH.
     *
     * @param bool $duplicate vrai pour envoyer X-Frame-Options deux fois
     */
    private function runWithHeaders(bool $duplicate): Process
    {
        $this->fakeBin = sys_get_temp_dir().'/fake_curl_'.bin2hex(random_bytes(4));
        mkdir($this->fakeBin);

        $frame = $duplicate ? 'X-Frame-Options: DENY\\nX-Frame-Options: DENY\\n' : 'X-Frame-Options: DENY\\n';
        // Faux curl : en-têtes fixes avec -D, page d'accueil (qui cite un fichier de build/) sinon
        $script = <<<SH
            #!/usr/bin/env bash
            for arg in "\$@"; do
                if [[ "\$arg" == "-D" ]]; then
                    printf 'HTTP/1.1 200 OK\\nStrict-Transport-Security: max-age=31536000\\nX-Content-Type-Options: nosniff\\n{$frame}Referrer-Policy: strict-origin-when-cross-origin\\nContent-Security-Policy: default-src \\x27self\\x27; script-src \\x27self\\x27 \\x27nonce-abc\\x27\\nPermissions-Policy: camera=()\\nCache-Control: no-store, private, max-age=0\\n\\n'
                    exit 0
                fi
            done
            printf '<link rel="stylesheet" href="/build/assets/app-TEST.css">'
            SH;
        file_put_contents($this->fakeBin.'/curl', $script);
        chmod($this->fakeBin.'/curl', 0o755);

        $process = new Process(
            ['bash', '-c', 'PATH="$1:$PATH" bash scripts/security/check-prod-headers.sh https://site.example.test/', 'test', $this->toBashPath($this->fakeBin)],
            \dirname(__DIR__, 2),
        );
        $process->run();

        return $process;
    }

    /**
     * Convertit un chemin Windows (C:\…) en chemin de Git Bash (/c/…) ; inchangé sous Linux.
     *
     * @param string $path chemin du dossier
     */
    private function toBashPath(string $path): string
    {
        if ('Windows' !== \PHP_OS_FAMILY) {
            return $path;
        }

        return (string) preg_replace_callback('#^([A-Za-z]):[\\\\/]#', static fn (array $m): string => '/'.strtolower($m[1]).'/', str_replace('\\', '/', $path));
    }
}
