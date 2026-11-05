<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Fichier /.well-known/security.txt (RFC 9116, décision du 2026-10-29) : champs obligatoires Contact et Expires, un seul
 * Expires, échéance future et à un an au plus (à renouveler chaque année),
 * adresses en https, aucune adresse e-mail en clair (contact par le formulaire du site).
 *
 * Quand ce test échoue sur l'échéance, renouveler le champ Expires (un an plus tard) : c'est son rôle de rappel.
 *
 * Liens :
 * - Couvre : public/.well-known/security.txt
 */
final class SecurityTxtTest extends TestCase
{
    /**
     * Vérifie le contenu du fichier.
     */
    public function testSecurityTxtFollowsRfc9116(): void
    {
        $content = (string) file_get_contents(\dirname(__DIR__, 2).'/public/.well-known/security.txt');
        $fields = [];
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            if ('' === trim($line) || str_starts_with($line, '#')) {
                continue;
            }
            self::assertMatchesRegularExpression('/^[A-Za-z-]+: \S/', $line, 'Ligne « Champ: valeur ».');
            [$name, $value] = explode(': ', $line, 2);
            $fields[$name][] = $value;
        }

        self::assertSame(['https://guillaume-couget.fr/contact'], $fields['Contact'] ?? null);
        self::assertSame(['fr'], $fields['Preferred-Languages'] ?? null);
        self::assertSame(['https://guillaume-couget.fr/.well-known/security.txt'], $fields['Canonical'] ?? null);
        self::assertCount(1, $fields['Expires'] ?? [], 'Un seul champ Expires.');
        $expires = new \DateTimeImmutable($fields['Expires'][0]);
        self::assertGreaterThan(new \DateTimeImmutable(), $expires, 'security.txt expiré : renouveler le champ Expires (un an).');
        self::assertLessThanOrEqual(new \DateTimeImmutable('+366 days'), $expires, 'Échéance à un an au plus (RFC 9116, § 2.5.5).');
        self::assertStringNotContainsString('@', $content, 'Aucune adresse e-mail en clair.');
        self::assertTrue(mb_check_encoding($content, 'ASCII'), 'Texte ASCII (servi en text/plain).');
    }
}
