<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Le résumé public des nouveautés (NOUVEAUTES.md, page /nouveautes) ne contient aucune information technique
 * sensible : ni code, ni fichier interne, ni chemin, ni variable d'environnement, ni commande, ni faille.
 * Le détail technique reste dans CHANGELOG.md (admin seulement).
 *
 * Liens :
 * - Couvre : NOUVEAUTES.md
 * - Voir aussi : WhatsNewController, ChangelogRenderer::renderWhatsNew()
 */
final class WhatsNewContentTest extends TestCase
{
    /**
     * Vérifie le fichier contre une liste de motifs interdits.
     */
    public function testPublicSummaryHasNoTechnicalDetail(): void
    {
        $content = (string) file_get_contents(\dirname(__DIR__, 2).'/NOUVEAUTES.md');
        self::assertNotSame('', trim($content));

        $forbidden = [
            'code en ligne' => '/`/',
            'nom de fichier' => '/\.(php|ya?ml|twig|js|json|env|sh|md|lock)\b/i',
            'chemin' => '#\b(src|config|templates|public|var|vendor|bin|assets|tests|scripts|docs)/#',
            'variable d\'environnement' => '/\b[A-Z][A-Z0-9]*_[A-Z0-9_]+\b/',
            'commande' => '/\bapp:[a-z]/',
            'adresse d\'admin' => '#/admin|ADMIN_PATH#i',
            'faille' => '/\b(faille|vulnérabilit|CVE-|XSS|injection|CSRF|contournement)/i',
            'préversion' => '/\b(alpha|rc)\.\d/i',
            'adresse e-mail' => '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',
        ];
        foreach ($forbidden as $label => $pattern) {
            self::assertDoesNotMatchRegularExpression($pattern, $content, 'NOUVEAUTES.md contient un détail technique : '.$label.'.');
        }
    }
}
