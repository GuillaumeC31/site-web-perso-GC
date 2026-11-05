<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\RecruitmentArgument;
use App\Repository\RecruitmentArgumentRepository;
use App\Repository\SiteSettingsRepository;
use App\Service\Ai\ChatContextBuilder;
use App\Tests\Support\AdminTestTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Bloc « Pourquoi me recruter » (v2.0.2) : contenu de départ (6 arguments masqués, bloc coupé), affichage sur
 * l'accueil seulement si le bloc est activé ET qu'un argument est visible, assistant IA limité aux arguments affichés,
 * app:content:sync-textes sans effet sur les arguments, aperçu et interrupteurs de l'admin.
 *
 * Liens :
 * - Couvre : Entity\RecruitmentArgument, Service\Content\RecruitmentArguments, Admin\RecruitmentArgumentController,
 *   HomeController, Ai\ChatContextBuilder::buildSiteSummary(), migration Version20261105110359,
 *   templates/_partials/_recruitment_arguments.html.twig
 * - Voir aussi : tests/Controller/Admin/AdminCrudPagesTest (liste et formulaires du CRUD)
 */
final class RecruitmentArgumentTest extends WebTestCase
{
    use AdminTestTrait;

    /** Titres du contenu de départ (migration), dans l'ordre. */
    private const DEFAULT_TITLES = [
        'Du terrain au bureau d\'études',
        'Une double compétence climatique et électrique',
        'Des projets menés de front, des équipes coordonnées',
        'Du besoin client à l\'offre chiffrée',
        'La rigueur des secteurs exigeants',
        'Des outils numériques au service des équipes',
    ];

    /**
     * Contenu de départ : les 6 arguments, tous masqués, et le bloc coupé ; l'accueil n'affiche rien.
     */
    public function testDefaultContentIsHiddenAndBlockIsOff(): void
    {
        $client = self::createClient();
        $arguments = self::getContainer()->get(RecruitmentArgumentRepository::class)->findAllOrdered();

        self::assertSame(self::DEFAULT_TITLES, array_map(static fn (RecruitmentArgument $a): string => $a->getTitle(), $arguments));
        self::assertSame([false], array_values(array_unique(array_map(static fn (RecruitmentArgument $a): bool => $a->isVisible(), $arguments))));
        self::assertFalse(self::getContainer()->get(SiteSettingsRepository::class)->getOrCreate()->isRecruitmentBlockEnabled());
        self::assertStringContainsString('FD X 15-140', $arguments[4]->getText());

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="recruitment-arguments"]');
    }

    /**
     * Bloc activé sans argument visible : toujours absent de l'accueil.
     */
    public function testBlockOnWithoutVisibleArgumentStaysHidden(): void
    {
        $client = self::createClient();
        $this->setBlock(true);

        $client->request('GET', '/');

        self::assertSelectorNotExists('[data-testid="recruitment-arguments"]');
    }

    /**
     * Arguments visibles mais bloc coupé : absent de l'accueil.
     */
    public function testVisibleArgumentsWithBlockOffStayHidden(): void
    {
        $client = self::createClient();
        $this->showArguments([0, 1]);

        $client->request('GET', '/');

        self::assertSelectorNotExists('[data-testid="recruitment-arguments"]');
    }

    /**
     * Bloc activé : seuls les arguments visibles s'affichent, dans l'ordre ; les brouillons restent absents.
     */
    public function testActiveBlockShowsOnlyVisibleArguments(): void
    {
        $client = self::createClient();
        $this->setBlock(true);
        $this->showArguments([2, 4]);

        $crawler = $client->request('GET', '/');

        self::assertSelectorTextContains('[data-testid="recruitment-arguments"] h2', 'Pourquoi me recruter');
        $titles = $crawler->filter('[data-testid="recruitment-argument"] h3')->each(static fn ($node): string => trim($node->text()));
        self::assertSame([self::DEFAULT_TITLES[2], self::DEFAULT_TITLES[4]], $titles);
        self::assertStringNotContainsString(self::DEFAULT_TITLES[0], (string) $client->getResponse()->getContent());
    }

    /**
     * L'assistant IA ne reçoit que les arguments affichés : rien si le bloc est coupé, jamais un brouillon.
     */
    public function testAssistantSeesOnlyDisplayedArguments(): void
    {
        self::bootKernel();
        $builder = self::getContainer()->get(ChatContextBuilder::class);
        $this->showArguments([1]);

        self::assertStringNotContainsString('POURQUOI ME RECRUTER', $builder->buildSiteSummary(), 'Bloc coupé : rien pour l\'IA.');

        $this->setBlock(true);
        $summary = $builder->buildSiteSummary();
        self::assertStringContainsString('POURQUOI ME RECRUTER', $summary);
        self::assertStringContainsString(self::DEFAULT_TITLES[1], $summary);
        foreach ([0, 2, 3, 4, 5] as $draft) {
            self::assertStringNotContainsString(self::DEFAULT_TITLES[$draft], $summary, 'Brouillon jamais envoyé à l\'IA.');
        }
    }

    /**
     * app:content:sync-textes --apply ne touche jamais aux arguments (nombre, titres, interrupteurs).
     */
    public function testSyncTextesNeverTouchesArguments(): void
    {
        self::bootKernel();
        $this->showArguments([3]);
        $before = $this->snapshot();

        $tester = new CommandTester((new Application(self::$kernel))->find('app:content:sync-textes'));
        $tester->execute(['path' => \dirname(__DIR__, 2).'/Fixtures/textes_site_sample.md', '--apply' => true]);

        $this->getEntityManager()->clear();
        self::assertSame($before, $this->snapshot());
    }

    /**
     * Admin : aperçu (arguments affichés, puis brouillons avec le badge « Masqué »), interrupteur d'un argument et
     * interrupteur général, avec retour à la liste et message.
     */
    public function testAdminPreviewAndSwitches(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);

        $client->request('GET', $this->adminUrl('/pourquoi-me-recruter/apercu'));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="recruitment-preview-empty"]');

        $crawler = $client->request('GET', $this->adminUrl('/pourquoi-me-recruter/apercu?brouillons=1'));
        self::assertCount(6, $crawler->filter('[data-testid="recruitment-argument"]'));
        self::assertSelectorTextContains('[data-testid="recruitment-argument"]', 'Masqué');

        // Interrupteur de la première ligne
        $crawler = $client->request('GET', $this->adminUrl('/pourquoi-me-recruter'));
        self::assertSelectorTextContains('[data-testid="recruitment-block-switch"]', 'coupé');
        $client->submit($crawler->filter('form[action$="/affichage"]')->first()->form());
        self::assertResponseRedirects($this->adminUrl('/pourquoi-me-recruter'), 303);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'L\'argument est affiché.');

        // Interrupteur général
        $crawler = $client->request('GET', $this->adminUrl('/pourquoi-me-recruter'));
        $client->submit($crawler->selectButton('Activer le bloc')->form());
        self::assertResponseRedirects($this->adminUrl('/pourquoi-me-recruter'), 303);
        $client->followRedirect();
        self::assertSelectorTextContains('[data-testid="recruitment-block-switch"]', 'activé');

        $crawler = $client->request('GET', $this->adminUrl('/pourquoi-me-recruter/apercu'));
        self::assertCount(1, $crawler->filter('[data-testid="recruitment-argument"]'));
        $client->request('GET', '/');
        self::assertSelectorTextContains('[data-testid="recruitment-arguments"]', self::DEFAULT_TITLES[0]);
    }

    /**
     * Active ou coupe le bloc (interrupteur général).
     *
     * @param bool $enabled état voulu
     */
    private function setBlock(bool $enabled): void
    {
        $settings = self::getContainer()->get(SiteSettingsRepository::class)->getOrCreate()->setRecruitmentBlockEnabled($enabled);
        $this->getEntityManager()->persist($settings);
        $this->getEntityManager()->flush();
    }

    /**
     * Marque « Afficher » les arguments de départ aux rangs donnés (0 à 5).
     *
     * @param list<int> $indexes rangs dans l'ordre d'affichage
     */
    private function showArguments(array $indexes): void
    {
        $arguments = self::getContainer()->get(RecruitmentArgumentRepository::class)->findAllOrdered();
        foreach ($indexes as $index) {
            $arguments[$index]->setVisible(true);
        }
        $this->getEntityManager()->flush();
    }

    /**
     * État des arguments (titre, texte, visible, ordre), pour comparer avant et après.
     *
     * @return list<array{string, string, bool, int}>
     */
    private function snapshot(): array
    {
        return array_map(
            static fn (RecruitmentArgument $a): array => [$a->getTitle(), $a->getText(), $a->isVisible(), $a->getPosition()],
            self::getContainer()->get(RecruitmentArgumentRepository::class)->findAllOrdered(),
        );
    }
}
