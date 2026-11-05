<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Tests\Support\MockHttpResponseFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:security:cloudflare-ips (v2.0.3) : liste officielle identique → succès ; liste changée → échec, plages à
 * ajouter et à retirer affichées, alerte avec --notify, fichier versionné jamais modifié.
 *
 * Liens :
 * - Couvre : Command\CloudflareIpRangesCommand, Security\CloudflareIpRanges
 * - Utilise : tests/Support/MockHttpResponseFactory (listes officielles simulées)
 */
final class CloudflareIpRangesCommandTest extends KernelTestCase
{
    /**
     * Remet les réponses simulées à zéro.
     */
    protected function setUp(): void
    {
        MockHttpResponseFactory::reset();
    }

    /**
     * Liste officielle identique au fichier versionné : succès, aucune alerte.
     */
    public function testUpToDateListSucceeds(): void
    {
        $tester = $this->tester();

        self::assertSame(0, $tester->execute(['--notify' => true]));
        self::assertStringContainsString('Plages Cloudflare à jour', $tester->getDisplay());
        self::assertCount(0, MockHttpResponseFactory::requestsTo('discord'));
    }

    /**
     * Liste officielle changée : échec, écarts affichés, alerte envoyée, fichier versionné inchangé.
     */
    public function testChangedListFailsAndAlerts(): void
    {
        $file = \dirname(__DIR__, 2).'/config/cloudflare_ip_ranges.php';
        $before = (string) file_get_contents($file);
        MockHttpResponseFactory::$cloudflareRanges = ['ipv4' => ['173.245.48.0/20', '192.0.2.0/24'], 'ipv6' => ['2400:cb00::/32']];
        $tester = $this->tester();

        self::assertSame(1, $tester->execute(['--notify' => true]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('à ajouter : 192.0.2.0/24', $display);
        self::assertStringContainsString('à retirer : 103.21.244.0/22', $display);
        self::assertStringContainsString('à retirer : 2606:4700::/32', $display);
        self::assertNotEmpty(MockHttpResponseFactory::requestsTo('discord'), 'Alerte « Général du site » envoyée.');
        self::assertSame($before, (string) file_get_contents($file), 'Aucune mise à jour automatique du fichier.');
    }

    /**
     * Liste officielle vide (réponse illisible) : échec sans rien conclure.
     */
    public function testEmptyListFails(): void
    {
        MockHttpResponseFactory::$cloudflareRanges = ['ipv4' => [], 'ipv6' => []];
        $tester = $this->tester();

        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('vide ou illisible', $tester->getDisplay());
    }

    /**
     * Prépare la commande.
     */
    private function tester(): CommandTester
    {
        self::bootKernel();

        return new CommandTester((new Application(self::$kernel))->find('app:security:cloudflare-ips'));
    }
}
