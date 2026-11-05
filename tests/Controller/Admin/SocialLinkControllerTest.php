<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\SocialLink;
use App\Enum\RecruiterStatus;
use App\Enum\SocialNetwork;
use App\Repository\SocialLinkRepository;
use App\Tests\Support\AdminTestTrait;
use App\Tests\Support\ConfirmationTrait;
use App\Tests\Support\RecruiterTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Réseaux sociaux gérés dans l'admin (Communication › Réseaux sociaux, décision du 2026-10-29) : adresse https du
 * domaine du réseau (« Autre » : https libre, nom obligatoire), nouveau lien masqué jusqu'à activation, afficher /
 * masquer, réordonner, supprimer (confirmation), endroits d'affichage (espace recruteur).
 *
 * Liens :
 * - Couvre : Admin\SocialLinkController, App\Entity\SocialLink, App\Enum\SocialNetwork, Twig\SocialLinksExtension,
 *   templates/admin/communication/social_links.html.twig, templates/recruiter_area/home.html.twig
 */
final class SocialLinkControllerTest extends WebTestCase
{
    use AdminTestTrait;
    use ConfirmationTrait;
    use RecruiterTestTrait;

    /**
     * Vérifie la règle de l'adresse selon le réseau.
     *
     * @param list<string> $expected chemins des erreurs attendues (vide : valide)
     */
    #[DataProvider('urls')]
    public function testUrlRules(SocialNetwork $network, ?string $label, string $url, array $expected): void
    {
        self::bootKernel();
        $link = (new SocialLink())->setNetwork($network)->setLabel($label)->setUrl($url);
        $paths = [];
        foreach (static::getContainer()->get(ValidatorInterface::class)->validate($link) as $violation) {
            $paths[] = $violation->getPropertyPath();
        }
        self::assertSame($expected, array_values(array_unique($paths)));
    }

    /**
     * Réseaux, adresses et erreurs attendues.
     *
     * @return iterable<string, array{SocialNetwork, string|null, string, list<string>}>
     */
    public static function urls(): iterable
    {
        yield 'LinkedIn valide' => [SocialNetwork::LinkedIn, null, 'https://www.linkedin.com/in/exemple', []];
        yield 'LinkedIn en http' => [SocialNetwork::LinkedIn, null, 'http://www.linkedin.com/in/exemple', ['url']];
        yield 'LinkedIn sur un autre domaine' => [SocialNetwork::LinkedIn, null, 'https://linkedin.com.example.org/in/x', ['url']];
        yield 'GitHub valide' => [SocialNetwork::GitHub, null, 'https://github.com/exemple', []];
        yield 'YouTube court' => [SocialNetwork::YouTube, null, 'https://youtu.be/abc', []];
        yield 'X ancien domaine' => [SocialNetwork::X, null, 'https://twitter.com/exemple', []];
        yield 'Discord invitation' => [SocialNetwork::Discord, null, 'https://discord.gg/abcdef', []];
        yield 'Telegram canal' => [SocialNetwork::Telegram, null, 'https://t.me/exemple', []];
        yield 'Malt sur Facebook' => [SocialNetwork::Malt, null, 'https://www.facebook.com/exemple', ['url']];
        yield 'Autre, https libre' => [SocialNetwork::Other, 'Mon blog', 'https://blog.exemple.fr', []];
        yield 'Autre sans nom' => [SocialNetwork::Other, null, 'https://blog.exemple.fr', ['label']];
        yield 'Autre en http' => [SocialNetwork::Other, 'Mon blog', 'http://blog.exemple.fr', ['url']];
        // v2.0.2 : Bluesky (bsky.app seulement) et Mastodon (toute instance en https, sans nom libre obligatoire)
        yield 'Bluesky valide' => [SocialNetwork::Bluesky, null, 'https://bsky.app/profile/exemple.bsky.social', []];
        yield 'Bluesky sur un autre domaine' => [SocialNetwork::Bluesky, null, 'https://bsky.social/profile/exemple', ['url']];
        yield 'Bluesky en http' => [SocialNetwork::Bluesky, null, 'http://bsky.app/profile/exemple', ['url']];
        yield 'Mastodon, instance libre' => [SocialNetwork::Mastodon, null, 'https://mastodon.social/@exemple', []];
        yield 'Mastodon, autre instance' => [SocialNetwork::Mastodon, null, 'https://piaille.fr/@exemple', []];
        yield 'Mastodon en http' => [SocialNetwork::Mastodon, null, 'http://mastodon.social/@exemple', ['url']];
    }

    /**
     * Bluesky et Mastodon dans le pied de page : nom du réseau, icône SVG locale propre (pas l'icône générique),
     * simple lien rel="me noopener noreferrer" en nouvel onglet.
     */
    public function testBlueskyAndMastodonInFooter(): void
    {
        $client = self::createClient();
        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $entityManager->persist((new SocialLink())->setNetwork(SocialNetwork::Bluesky)->setUrl('https://bsky.app/profile/exemple.bsky.social')->setVisible(true)->setPosition(1));
        $entityManager->persist((new SocialLink())->setNetwork(SocialNetwork::Mastodon)->setUrl('https://mastodon.social/@exemple')->setVisible(true)->setPosition(2));
        $entityManager->flush();

        $crawler = $client->request('GET', '/mentions-legales');

        self::assertResponseIsSuccessful();
        foreach (['Bluesky' => 'https://bsky.app/profile/exemple.bsky.social', 'Mastodon' => 'https://mastodon.social/@exemple'] as $name => $url) {
            $link = $crawler->filter('footer a[href="'.$url.'"]');
            self::assertCount(1, $link, $name);
            self::assertStringContainsString($name, $link->text());
            self::assertSame('me noopener noreferrer', $link->attr('rel'));
            self::assertSame('_blank', $link->attr('target'));
            $icon = $link->filter('svg path');
            self::assertCount(1, $icon);
            self::assertSame('currentColor', $link->filter('svg')->attr('fill'), $name.' : icône du réseau, pas l\'icône générique (trait).');
        }
        self::assertStringContainsString('M12 10.8c-1.087', (string) $client->getResponse()->getContent(), 'Tracé Bluesky');
        self::assertStringContainsString('M23.268 5.313', (string) $client->getResponse()->getContent(), 'Tracé Mastodon');
    }

    /**
     * Vérifie l'onglet, l'ajout (masqué par défaut), l'affichage d'un clic, l'ordre et la suppression confirmée.
     */
    public function testManageLinks(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $crawler = $client->request('GET', $this->adminUrl('/communication/reseaux-sociaux'));
        self::assertResponseIsSuccessful();
        self::assertSame('Réseaux sociaux', trim($crawler->filter('[data-testid="communication-tabs"] [aria-current="page"]')->text()));
        self::assertSelectorTextContains('[data-testid="social-reminder"]', 'lien d\'invitation public');
        self::assertSelectorExists('[data-testid="info-box"]');

        foreach ([['github', 'https://github.com/exemple'], ['malt', 'https://www.malt.fr/profile/exemple']] as [$network, $url]) {
            $crawler = $client->request('GET', $this->adminUrl('/communication/reseaux-sociaux/nouveau'));
            $client->submit($crawler->selectButton('Enregistrer')->form(['social_link[network]' => $network, 'social_link[url]' => $url]));
            self::assertResponseRedirects($this->adminUrl('/communication/reseaux-sociaux'));
        }
        $links = static::getContainer()->get(SocialLinkRepository::class)->findAllOrdered();
        self::assertSame([SocialNetwork::GitHub, SocialNetwork::Malt], array_map(static fn (SocialLink $l): SocialNetwork => $l->getNetwork(), $links));
        self::assertFalse($links[0]->isVisible(), 'Nouveau lien masqué jusqu\'à activation.');

        // Adresse refusée : domaine d'un autre réseau
        $crawler = $client->request('GET', $this->adminUrl('/communication/reseaux-sociaux/nouveau'));
        $client->submit($crawler->selectButton('Enregistrer')->form(['social_link[network]' => 'linkedin', 'social_link[url]' => 'https://github.com/exemple']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Adresse attendue sur le domaine de LinkedIn');

        // Afficher d'un clic, puis monter Malt en premier
        $crawler = $client->request('GET', $this->adminUrl('/communication/reseaux-sociaux'));
        $client->submit($crawler->filter('[data-network="github"]')->selectButton('Afficher')->form());
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('[data-network="github"]', 'Visible');
        $client->submit($crawler->filter('[data-network="malt"] form[action$="/deplacer/haut"]')->form());
        static::getContainer()->get('doctrine')->getManager()->clear();
        $links = static::getContainer()->get(SocialLinkRepository::class)->findAllOrdered();
        self::assertSame([SocialNetwork::Malt, SocialNetwork::GitHub], array_map(static fn (SocialLink $l): SocialNetwork => $l->getNetwork(), $links));
        self::assertTrue($links[1]->isVisible());

        // Suppression : page de confirmation, puis suppression
        $crawler = $client->request('GET', $this->adminUrl('/communication/reseaux-sociaux'));
        $client->submit($crawler->filter('[data-network="malt"]')->selectButton('Supprimer')->form());
        $this->confirmAction($client);
        self::assertCount(1, static::getContainer()->get(SocialLinkRepository::class)->findAll());
    }

    /**
     * Vérifie l'espace recruteur : liens visibles cochés « espace recruteur » seulement.
     */
    public function testRecruiterAreaShowsCheckedLinks(): void
    {
        $client = self::createClient();
        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $entityManager->persist((new SocialLink())->setNetwork(SocialNetwork::LinkedIn)->setUrl('https://www.linkedin.com/in/exemple')->setVisible(true)->setPosition(1));
        $entityManager->persist((new SocialLink())->setNetwork(SocialNetwork::Instagram)->setUrl('https://www.instagram.com/perso')->setVisible(true)->setShowInRecruiterArea(false)->setPosition(2));
        $entityManager->flush();
        $client->loginUser($this->createRecruiter('rh@acme.test', RecruiterStatus::Approved, '+30 days'), 'recruiter');

        $client->request('GET', '/espace-recruteur/accueil');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="recruiter-social-links"] a[href="https://www.linkedin.com/in/exemple"][target="_blank"]');
        self::assertSelectorNotExists('[data-testid="recruiter-social-links"] a[href*="instagram"]', 'Réseau personnel décoché pour l\'espace recruteur.');
    }
}
