<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Repository\SiteSettingsRepository;
use App\Tests\Support\AdminTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Sécurité des envois dans l'admin (2026-10-29) : réglage du plafond des e-mails aux personnes non validées
 * (Communication › E-mails) et rappel « télécharger une copie de la sauvegarde » du tableau de bord.
 *
 * Liens :
 * - Couvre : Form\CommunicationAddressesType (unvalidatedEmailDailyCap), Admin\DashboardController::backupDownloaded(),
 *   templates/admin/dashboard/index.html.twig
 */
final class SendingSafetyTest extends WebTestCase
{
    use AdminTestTrait;

    /**
     * Vérifie le réglage du plafond : 30 par défaut, modifiable, 0 refusé.
     */
    public function testCapIsConfigurable(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $crawler = $client->request('GET', $this->adminUrl('/communication/e-mails'));
        self::assertSame('30', $crawler->filter('[data-testid="unvalidated-cap"] input')->attr('value'));

        $client->submit($crawler->filter('#adresses form')->form(['communication_addresses[unvalidatedEmailDailyCap]' => '0']));
        self::assertResponseStatusCodeSame(422);

        $crawler = $client->request('GET', $this->adminUrl('/communication/e-mails'));
        $client->submit($crawler->filter('#adresses form')->form(['communication_addresses[unvalidatedEmailDailyCap]' => '12']));
        self::assertResponseRedirects();
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertSame(12, static::getContainer()->get(SiteSettingsRepository::class)->getOrCreate()->getUnvalidatedEmailDailyCap());
    }

    /**
     * Vérifie le rappel de sauvegarde : affiché sans copie connue, masqué après « J'ai téléchargé une copie », de
     * retour après 30 jours ; jeton CSRF exigé.
     */
    public function testBackupReminder(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $crawler = $client->request('GET', $this->adminUrl(''));
        self::assertSelectorTextContains('[data-testid="backup-reminder"]', 'Aucune copie');

        $client->request('POST', $this->adminUrl('/sauvegarde-telechargee'), ['_token' => 'faux']);
        self::assertNull(static::getContainer()->get(SiteSettingsRepository::class)->getOrCreate()->getBackupDownloadedAt());

        $crawler = $client->request('GET', $this->adminUrl(''));
        $client->submit($crawler->filter('[data-testid="backup-reminder"]')->selectButton('J\'ai téléchargé une copie')->form());
        self::assertResponseRedirects($this->adminUrl(''));
        $client->followRedirect();
        self::assertSelectorNotExists('[data-testid="backup-reminder"]');

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $settings = static::getContainer()->get(SiteSettingsRepository::class)->getOrCreate()->setBackupDownloadedAt(new \DateTimeImmutable('-31 days'));
        $entityManager->persist($settings);
        $entityManager->flush();
        $client->request('GET', $this->adminUrl(''));
        self::assertSelectorTextContains('[data-testid="backup-reminder"]', 'plus de 30 jours');
    }
}
