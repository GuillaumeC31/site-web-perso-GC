<?php

declare(strict_types=1);

namespace App\Tests\Service\Cv;

use App\Entity\KeyFigure;
use App\Repository\ProfileRepository;
use App\Repository\SiteSettingsRepository;
use App\Service\Cv\PublicCvPdfCache;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Cache des PDF publics du CV : un seul calcul par contenu, service rapide depuis le cache, purge à chaque
 * modification du contenu du CV (admin ou synchronisation : tout passe par Doctrine), fichiers hors de public/.
 * L'absence de cache pour le CV complet de l'espace recruteur est vérifiée dans RecruiterAreaControllerTest.
 *
 * Liens :
 * - Couvre : Service\Cv\PublicCvPdfCache, EventListener\CvPdfCacheInvalidationListener, CvController::pdf(),
 *   app:content:sync-textes (purge)
 */
final class PublicCvPdfCacheTest extends WebTestCase
{
    /**
     * Le premier téléchargement produit et garde le PDF ; le suivant le sert depuis le cache, en moins de 300 ms.
     */
    public function testPdfIsCachedAndServedQuickly(): void
    {
        $client = self::createClient();
        $cache = self::getContainer()->get(PublicCvPdfCache::class);
        $cache->clear();

        $client->request('GET', '/cv/pdf/design');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        $first = (string) $client->getResponse()->getContent();
        self::assertStringStartsWith('%PDF', $first);
        self::assertSame(1, self::getContainer()->get(PublicCvPdfCache::class)->count());

        $start = hrtime(true);
        $cached = self::getContainer()->get(PublicCvPdfCache::class)->get('design');
        $elapsedMs = (hrtime(true) - $start) / 1e6;

        self::assertSame($first, $cached, 'Même fichier, servi depuis le cache.');
        self::assertLessThan(300, $elapsedMs, 'PDF en cache servi en moins de 300 ms.');

        $client->request('GET', '/cv/pdf/ats');
        self::assertSame(2, self::getContainer()->get(PublicCvPdfCache::class)->count(), 'Un fichier par version.');
    }

    /**
     * Une modification du contenu du CV (profil, chiffre clé) vide le cache ; le PDF suivant est recalculé.
     */
    public function testCvChangeInvalidatesCache(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $cache = $container->get(PublicCvPdfCache::class);
        $entityManager = $container->get(EntityManagerInterface::class);
        $cache->clear();

        $before = $cache->get('ats');
        self::assertSame(1, $cache->count());

        $profile = $container->get(ProfileRepository::class)->getOrCreate();
        $profile->setJobTitle('Titre modifié pour le test du cache');
        $entityManager->persist($profile);
        $entityManager->flush();
        self::assertSame(0, $cache->count(), 'Profil modifié : cache vidé.');

        $after = $cache->get('ats');
        self::assertNotSame($before, $after);
        self::assertSame(1, $cache->count());

        $entityManager->persist((new KeyFigure())->setValue('12')->setLabel('chiffre de test')->setPosition(99));
        $entityManager->flush();
        self::assertSame(0, $cache->count(), 'Chiffre clé ajouté : cache vidé.');
    }

    /**
     * La synchronisation du CV avec TEXTES_SITE.md (app:content:sync-textes --apply) vide le cache.
     */
    public function testSyncTextesInvalidatesCache(): void
    {
        self::bootKernel();
        $cache = self::getContainer()->get(PublicCvPdfCache::class);
        $cache->clear();
        $cache->get('ats');
        self::assertSame(1, $cache->count());

        $tester = new CommandTester((new Application(self::$kernel))->find('app:content:sync-textes'));
        $tester->execute(['path' => \dirname(__DIR__, 2).'/Fixtures/textes_site_sample.md', '--apply' => true]);

        self::assertSame(0, $cache->count(), 'Contenu du CV synchronisé : cache vidé.');
    }

    /**
     * Une écriture qui ne touche pas le CV (paramètres du site) garde le cache.
     */
    public function testUnrelatedChangeKeepsCache(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $cache = $container->get(PublicCvPdfCache::class);
        $cache->clear();
        $cache->get('ats');

        $settings = $container->get(SiteSettingsRepository::class)->getOrCreate();
        $settings->revokeTrustedDevices();
        $entityManager = $container->get(EntityManagerInterface::class);
        $entityManager->persist($settings);
        $entityManager->flush();

        self::assertSame(1, $cache->count());
    }

    /**
     * Les fichiers du cache sont dans var/, jamais dans public/.
     */
    public function testCacheLivesOutsidePublic(): void
    {
        self::bootKernel();
        $cache = self::getContainer()->get(PublicCvPdfCache::class);
        $cache->clear();
        $cache->get('ats');

        $projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
        self::assertCount(1, glob($projectDir.'/var/cv-pdf/test/ats-*.pdf') ?: []);
        self::assertSame([], glob($projectDir.'/public/**/*.pdf') ?: []);
        $cache->clear();
    }
}
