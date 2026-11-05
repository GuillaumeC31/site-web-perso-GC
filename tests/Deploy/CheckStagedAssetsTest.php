<?php

declare(strict_types=1);

namespace App\Tests\Deploy;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Contrôle avant commit (scripts/security/check-staged-assets.sh), dans un dépôt Git temporaire : fichiers
 * d'identifiants bloqués d'après leur nom (texte et Markdown ; tout « NE_PAS_PUSH »), code non concerné,
 * valeur de SALARY_FLOOR refusée dans un fichier versionné. Les fichiers créés sont fictifs.
 *
 * Liens :
 * - Couvre : scripts/security/check-staged-assets.sh (hook pre-commit, CI)
 */
final class CheckStagedAssetsTest extends TestCase
{
    /** Dépôt temporaire du test. */
    private string $repository;

    protected function setUp(): void
    {
        $this->repository = sys_get_temp_dir().'/check-staged-'.bin2hex(random_bytes(4));
        mkdir($this->repository.'/scripts/security', 0777, true);
        copy(\dirname(__DIR__, 2).'/scripts/security/check-staged-assets.sh', $this->repository.'/scripts/security/check-staged-assets.sh');
        $this->git('init', '-q');
    }

    protected function tearDown(): void
    {
        // Objets Git en lecture seule sous Windows : rendus modifiables avant la suppression
        $filesystem = new Filesystem();
        $filesystem->chmod($this->repository, 0777, 0000, true);
        $filesystem->remove($this->repository);
    }

    /**
     * Vérifie le résultat du contrôle pour un fichier indexé.
     *
     * @param string $path    chemin du fichier dans le dépôt
     * @param string $content contenu (fictif)
     * @param bool   $blocked vrai si le commit doit être bloqué
     */
    #[DataProvider('provideFiles')]
    public function testStagedFile(string $path, string $content, bool $blocked): void
    {
        $file = $this->repository.'/'.$path;
        if (!is_dir(\dirname($file))) {
            mkdir(\dirname($file), 0777, true);
        }
        file_put_contents($file, $content);
        $this->git('add', '-f', $path);

        $process = new Process(['bash', 'scripts/security/check-staged-assets.sh'], $this->repository);
        $process->run();

        self::assertSame($blocked ? 1 : 0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        if ($blocked) {
            self::assertStringContainsString($path, $process->getOutput());
        }
        self::assertStringNotContainsString('42000', $process->getOutput().$process->getErrorOutput(), 'Valeur jamais affichée.');
    }

    /**
     * Fichiers indexés et décision attendue.
     *
     * @return iterable<string, array{string, string, bool}>
     */
    public static function provideFiles(): iterable
    {
        yield 'NE_PAS_PUSH (Markdown)' => ['docs/IDENTIFIANT_NE_PAS_PUSH.md', "fictif\n", true];
        yield 'NE_PAS_PUSH (autre extension)' => ['notes/acces_ne_pas_push.json', "{}\n", true];
        yield 'identifiants.txt' => ['identifiants.txt', "fictif\n", true];
        yield 'mes-passwords.md' => ['docs/mes-passwords.md', "fictif\n", true];
        yield 'Secret sans extension' => ['Secret', "fictif\n", true];
        yield 'code : check-staged-secrets.sh' => ['scripts/security/check-staged-secrets.sh', "#!/bin/sh\n", false];
        yield 'code : ResetAdminPasswordCommand.php' => ['src/Command/ResetAdminPasswordCommand.php', "<?php\n", false];
        yield 'SALARY_FLOOR renseigné' => ['config/exemple.env', "SALARY_FLOOR=\n", true];
        yield 'SALARY_FLOOR vide (documentation)' => ['.env.example', "SALARY_FLOOR=\n", false];
        yield 'fichier ordinaire' => ['docs/NOTES.md', "Rien de secret.\n", false];
        yield 'DISCORD_BOT_TOKEN renseigné' => ['config/exemple.env', "DISCORD_BOT_TOKEN=abc.def.ghi\n", true];
        yield 'DISCORD_PUBLIC_KEY renseignée' => ['.env', "DISCORD_PUBLIC_KEY=0123abcd\n", true];
        yield 'TELEGRAM_BOT_TOKEN renseigné' => ['config/exemple.env', "TELEGRAM_BOT_TOKEN=123:ABC\n", true];
        yield 'variables de messagerie vides (documentation)' => ['.env.example', "DISCORD_BOT_TOKEN=\nTELEGRAM_BOT_TOKEN=\nDISCORD_ADMIN_USER_ID= # commentaire\n", false];
        yield 'valeurs factices des tests' => ['.env.test', "DISCORD_BOT_TOKEN=jeton-de-test-du-bot\n", false];
    }

    /**
     * Lance une commande Git dans le dépôt temporaire.
     */
    private function git(string ...$arguments): void
    {
        $process = new Process(['git', '-c', 'user.name=test', '-c', 'user.email=test@example.test', ...$arguments], $this->repository);
        $process->mustRun();
    }
}
