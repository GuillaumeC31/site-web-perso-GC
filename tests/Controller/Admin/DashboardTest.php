<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use App\Enum\RecruiterStatus;
use App\Service\Dashboard\DashboardMetrics;
use App\Tests\Support\AdminTestTrait;
use App\Tests\Support\MockHttpResponseFactory;
use App\Tests\Support\RecruiterTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Page du tableau de bord : grille des dix tuiles (statut en icône ET texte, lien), bouton « Actualiser »
 * (toast de confirmation), récapitulatif seulement si quelque chose a changé (état « déjà vu » gardé sur le
 * compte admin), pastille du menu, formulaire « Renvoyer » prêt pour la fenêtre de confirmation.
 *
 * Liens :
 * - Couvre : Admin\DashboardController, templates/admin/dashboard/*.html.twig, Twig\DashboardExtension,
 *   EventSubscriber\DashboardCacheSubscriber
 */
final class DashboardTest extends WebTestCase
{
    use AdminTestTrait;
    use RecruiterTestTrait;

    protected function setUp(): void
    {
        MockHttpResponseFactory::reset();
    }

    /**
     * Vérifie la grille : dix tuiles, chacune avec titre, statut (icône et texte), valeur et lien.
     */
    public function testTilesAreRendered(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $crawler = $client->request('GET', $this->adminUrl());
        self::assertResponseIsSuccessful();

        $tiles = $crawler->filter('[data-testid="dashboard-tiles"] > section');
        self::assertCount(\count(DashboardMetrics::TILES), $tiles);
        // Tuile « Turnstile » ajoutée en v2.0.3
        foreach (DashboardMetrics::TILES as $id) {
            $tile = $crawler->filter('[data-testid="tile-'.$id.'"]');
            self::assertCount(1, $tile, $id);
            self::assertContains($tile->attr('data-status'), ['ok', 'warning', 'problem']);
            // Statut lisible sans la couleur : icône (aria-hidden) ET texte
            $status = $tile->filter('div > [data-status]')->first();
            self::assertMatchesRegularExpression('/OK|À surveiller|Problème/', $status->text());
            self::assertCount(1, $status->filter('svg[aria-hidden="true"]'));
            self::assertNotEmpty($tile->filter('a.link')->last()->attr('href'));
        }
        self::assertSelectorTextContains('[data-testid="tile-tasks"]', 'Aucune sauvegarde notée');
        self::assertSelectorTextContains('[data-testid="tile-site"]', 'PHP '.\PHP_VERSION);
        self::assertSelectorExists('[data-testid="tile-communication"] a[href$="/communication"]');
        self::assertSelectorNotExists('[data-testid="dashboard-recap"]', 'Première visite : pas de récapitulatif.');
    }

    /**
     * Vérifie le récapitulatif : absent à la première visite, présent (une fois) quand une tuile a changé,
     * puis absent tant que rien ne change ; l'état vu est gardé sur le compte admin.
     */
    public function testRecapOnlyWhenSomethingChanged(): void
    {
        $client = self::createClient();
        $admin = $this->loginFullyAsAdmin($client);
        $client->request('GET', $this->adminUrl());
        self::assertSelectorNotExists('[data-testid="dashboard-recap"]');
        $this->getEntityManager()->clear();
        $seen = $this->getEntityManager()->find(User::class, $admin->getId())?->getDashboardSeen();
        self::assertIsArray($seen);
        self::assertSame('ok:0', $seen['recruiters']);

        // Une nouvelle demande arrive (le formulaire public vide le cache ; ici, création directe + actualisation)
        $recruiter = $this->createRecruiter('recap@acme.test', RecruiterStatus::Pending, null);
        // Demande d'il y a une heure : loin du seuil de 24 h ouvrées (« hier à la même heure » tombait pile sur le seuil
        // un jour de semaine, et le statut basculait d'une requête à l'autre)
        $this->getEntityManager()->getConnection()->executeStatement('UPDATE recruiter SET requested_at = ? WHERE id = ?', [(new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'), $recruiter->getId()]);
        $client->request('GET', $this->adminUrl().'?actualiser=1');
        $crawler = $client->followRedirect();
        $recap = $crawler->filter('[data-testid="dashboard-recap"]');
        self::assertCount(1, $recap);
        self::assertSame('notify', $recap->attr('data-controller'));
        self::assertSame('true', $recap->attr('data-notify-dialog-value'));
        self::assertStringContainsString('Recruteurs', $recap->text());
        self::assertStringNotContainsString('Messages', $recap->text(), 'Seules les tuiles changées sont listées.');

        $client->request('GET', $this->adminUrl());
        self::assertSelectorNotExists('[data-testid="dashboard-recap"]');
    }

    /**
     * Vérifie « Actualiser » : relit les indicateurs, revient au tableau de bord avec un message (toast).
     */
    public function testRefreshButton(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $crawler = $client->request('GET', $this->adminUrl());
        $client->click($crawler->selectLink('Actualiser')->link());
        self::assertResponseRedirects($this->adminUrl());
        $client->followRedirect();
        self::assertSelectorTextContains('[data-notify-target="toast"][data-notify-type="info"]', 'Indicateurs actualisés.');
    }

    /**
     * Vérifie la pastille du menu : demandes en attente + e-mails non envoyés + sauvegarde absente ;
     * elle suit aussitôt une décision (cache vidé après chaque envoi de formulaire).
     */
    public function testMenuBadgeCountsWhatNeedsAction(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $recruiter = $this->createRecruiter('pastille@acme.test', RecruiterStatus::Pending, null);
        $client->request('GET', $this->adminUrl().'?actualiser=1');
        $client->followRedirect();
        // 1 demande + sauvegarde jamais notée
        self::assertSelectorTextContains('[data-testid="admin-attention-badge"]', '2');
        self::assertSelectorTextContains('[data-testid="admin-attention-badge"] .sr-only', '2 points à traiter');

        $crawler = $client->request('GET', $this->adminUrl('/recruteurs/'.$recruiter->getId()));
        $client->submit($crawler->selectButton('Refuser')->form(['_confirmed' => '1']));
        $client->request('GET', $this->adminUrl());
        self::assertSelectorTextContains('[data-testid="admin-attention-badge"]', '1');
    }
}
