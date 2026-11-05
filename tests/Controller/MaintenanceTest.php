<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\SiteSettings;
use App\Entity\SocialLink;
use App\Enum\SocialNetwork;
use App\Repository\SiteSettingsRepository;
use App\Tests\Support\AdminTestTrait;
use App\Tests\Support\VisibleTextAssertionsTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Mode maintenance : site public en 503 avec Retry-After (calculé depuis la date de réactivation), message
 * choisi ou par défaut, date en clair (sans JavaScript), « Retour imminent » et rechargement 60 s quand la date
 * est dépassée, noindex, page autonome ; admin et adresses IP autorisées toujours servis ; aperçu dans l'admin
 * sans activer la maintenance ; site normal quand le mode est désactivé.
 *
 * Liens :
 * - Couvre : MaintenanceSubscriber, MaintenancePage, Admin\SiteSettingsController::maintenancePreview(),
 *   templates/maintenance.html.twig
 */
final class MaintenanceTest extends WebTestCase
{
    use AdminTestTrait;
    use ClockSensitiveTrait;
    use VisibleTextAssertionsTrait;

    /**
     * Vérifie les réponses avec et sans le mode maintenance (sans date : 1 h, message choisi).
     */
    public function testMaintenanceModeBlocksPublicSiteOnly(): void
    {
        $client = self::createClient();
        $this->configure(fn (SiteSettings $s) => $s->setMaintenanceEnabled(true)->setMaintenanceMessage('Retour prévu à 18 h.'));

        foreach (['/', '/cv', '/espace-recruteur'] as $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(503, $url);
            self::assertResponseHeaderSame('Retry-After', '3600');
        }
        self::assertSelectorTextContains('h1', 'Site en maintenance');
        self::assertSelectorTextContains('main', 'Retour prévu à 18 h.');
        self::assertSelectorTextContains('main', 'Retour très bientôt.');
        self::assertSelectorExists('meta[name="robots"][content="noindex, nofollow"]');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        self::assertSelectorNotExists('meta[http-equiv="refresh"]');
        // Page autonome : aucun fichier compilé du site
        self::assertStringNotContainsString('/build/', (string) $client->getResponse()->getContent());

        $client->request('GET', '/'.static::getContainer()->getParameter('app.admin_path').'/connexion');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/robots.txt');
        self::assertResponseIsSuccessful();

        $this->configure(fn (SiteSettings $s) => $s->setMaintenanceEnabled(false));
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
    }

    /**
     * Vérifie la date de réactivation : écrite en clair à l'heure de Paris (lisible sans JavaScript), compte à
     * rebours masqué aux lecteurs d'écran, Retry-After en secondes jusqu'à la date ; message par défaut court,
     * sans « Retour très bientôt » répété.
     */
    public function testUpcomingDateIsReadableWithoutJavascript(): void
    {
        $client = self::createClient();
        self::mockTime('2026-10-27 10:00:00 UTC');
        $this->configure(fn (SiteSettings $s) => $s->setMaintenanceEnabled(true)->setMaintenanceMessage(null)->setMaintenanceUntil(new \DateTimeImmutable('2026-10-29 13:05:00 UTC')));

        $client->request('GET', '/');

        self::assertResponseStatusCodeSame(503);
        self::assertResponseHeaderSame('Retry-After', (string) (2 * 86400 + 3 * 3600 + 5 * 60));
        self::assertSelectorTextContains('main', 'Quelques améliorations sont en cours. Merci de votre patience.');
        // Espaces insécables autour du « h » : l'heure n'est jamais coupée en fin de ligne
        self::assertSelectorTextContains('main', "Retour prévu le jeudi 29 octobre à 14\u{a0}h\u{a0}05.");
        self::assertSelectorTextNotContains('main', 'Retour très bientôt');
        self::assertSelectorExists('ul.countdown[hidden][aria-hidden="true"]');
        self::assertSelectorNotExists('meta[http-equiv="refresh"]');
    }

    /**
     * Vérifie la date dépassée : « Retour imminent », rechargement toutes les 60 s sans JavaScript, Retry-After 60.
     */
    public function testPassedDateAnnouncesImminentReturn(): void
    {
        $client = self::createClient();
        self::mockTime('2026-10-29 14:00:00 UTC');
        $this->configure(fn (SiteSettings $s) => $s->setMaintenanceEnabled(true)->setMaintenanceUntil(new \DateTimeImmutable('2026-10-29 13:05:00 UTC')));

        $client->request('GET', '/');

        self::assertResponseHeaderSame('Retry-After', '60');
        self::assertSelectorTextContains('main', 'Retour imminent.');
        self::assertResponseHeaderSame('Refresh', '60');
        self::assertSelectorNotExists('meta[http-equiv="refresh"]');
        self::assertSelectorNotExists('ul.countdown');
    }

    /**
     * Vérifie les adresses IP autorisées (site normal) et le refus d'une adresse invalide dans Paramètres.
     */
    public function testAllowedIpsSeeTheNormalSite(): void
    {
        $client = self::createClient();
        $this->configure(fn (SiteSettings $s) => $s->setMaintenanceEnabled(true)->setMaintenanceAllowedIps("203.0.113.0/24\n2001:db8::1"));

        $client->request('GET', '/', server: ['REMOTE_ADDR' => '203.0.113.42']);
        self::assertResponseIsSuccessful();
        $client->request('GET', '/', server: ['REMOTE_ADDR' => '198.51.100.7']);
        self::assertResponseStatusCodeSame(503);

        $settings = (new SiteSettings())->setMaintenanceAllowedIps("203.0.113.0/24\npas-une-ip\n10.0.0.0/40");
        $violations = static::getContainer()->get('validator')->validate($settings);
        self::assertCount(2, $violations);
        self::assertSame('maintenanceAllowedIps', $violations[0]->getPropertyPath());
    }

    /**
     * Vérifie l'aperçu dans l'admin : page de maintenance en 200, bandeau « Aperçu », sans activer la
     * maintenance ; liens sociaux visibles cochés « page de maintenance » (Communication › Réseaux sociaux), jamais un
     * lien masqué ni décoché ; réservé à l'admin.
     */
    public function testAdminPreviewDoesNotEnableMaintenance(): void
    {
        $client = self::createClient();
        $client->request('GET', $this->adminUrl('/parametres/maintenance/apercu'));
        self::assertResponseRedirects();

        $this->loginFullyAsAdmin($client);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist((new SocialLink())->setNetwork(SocialNetwork::LinkedIn)->setUrl('https://www.linkedin.com/in/exemple')->setVisible(true));
        $entityManager->persist((new SocialLink())->setNetwork(SocialNetwork::GitHub)->setUrl('https://github.com/exemple')->setVisible(true)->setShowOnMaintenance(false));
        $entityManager->persist((new SocialLink())->setNetwork(SocialNetwork::Malt)->setUrl('https://www.malt.fr/profile/exemple'));
        $entityManager->flush();
        $client->request('GET', $this->adminUrl('/parametres/maintenance/apercu'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.preview', 'Aperçu : le site n\'est pas en maintenance');
        self::assertSelectorExists('a[href="https://www.linkedin.com/in/exemple"][target="_blank"][rel~="noopener"][rel~="noreferrer"]');
        self::assertSelectorNotExists('a[href="https://github.com/exemple"]');
        self::assertSelectorNotExists('a[href="https://www.malt.fr/profile/exemple"]');
        self::assertFalse(static::getContainer()->get(SiteSettingsRepository::class)->getOrCreate()->isMaintenanceEnabled());
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
    }

    /**
     * Modifie et enregistre les paramètres du site.
     *
     * @param callable(SiteSettings): mixed $change modification à appliquer
     */
    private function configure(callable $change): void
    {
        $settings = static::getContainer()->get(SiteSettingsRepository::class)->getOrCreate();
        $change($settings);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($settings);
        $entityManager->flush();
    }
}
