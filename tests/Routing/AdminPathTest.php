<?php

declare(strict_types=1);

namespace App\Tests\Routing;

use App\Routing\AdminPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires du segment d'URL de l'administration (App\Routing\AdminPath).
 */
final class AdminPathTest extends TestCase
{
    /**
     * Vérifie que les valeurs valides sont nettoyées (barres obliques, majuscules, espaces).
     */
    #[DataProvider('provideValidValues')]
    public function testNormalizeAcceptsValidValues(?string $rawValue, string $expected): void
    {
        self::assertSame($expected, AdminPath::normalize($rawValue));
    }

    /**
     * Jeux de valeurs valides : valeur brute, segment attendu.
     *
     * @return iterable<string, array{0: string|null, 1: string}>
     */
    public static function provideValidValues(): iterable
    {
        yield 'valeur absente' => [null, AdminPath::DEFAULT_PATH];
        yield 'chaîne vide' => ['', AdminPath::DEFAULT_PATH];
        yield 'valeur simple' => ['console-7f3a', 'console-7f3a'];
        yield 'barres et majuscules' => ['/Console-7F3A/', 'console-7f3a'];
        yield 'espaces autour' => ['  gestion  ', 'gestion'];
    }

    /**
     * Vérifie qu'une valeur dangereuse ou mal formée est refusée (pas de route admin imprévisible).
     */
    #[DataProvider('provideInvalidValues')]
    public function testNormalizeRejectsInvalidValues(string $rawValue): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AdminPath::normalize($rawValue);
    }

    /**
     * Jeux de valeurs invalides.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function provideInvalidValues(): iterable
    {
        yield 'trop court' => ['ab'];
        yield 'sous-dossier' => ['admin/secret'];
        yield 'caractère spécial' => ['admin$'];
        yield 'expression régulière' => ['adm.*'];
        yield 'tiret final' => ['admin-'];
    }
}
