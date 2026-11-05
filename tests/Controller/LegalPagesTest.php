<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\SiteSettingsRepository;
use App\Tests\Support\VisibleTextAssertionsTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Pages légales (lot 8) : mentions légales (éditeur non professionnel sans coordonnées personnelles,
 * hébergeur complet, propriété intellectuelle, crédits) et politique de confidentialité complète.
 *
 * Liens :
 * - Couvre : LegalController, templates/legal/*.html.twig
 */
final class LegalPagesTest extends WebTestCase
{
    use VisibleTextAssertionsTrait;

    /**
     * Vérifie les mentions légales : éditeur, directeur de la publication, hébergeur (raison sociale,
     * adresse, téléphone), contact par le formulaire, aucune coordonnée personnelle.
     */
    public function testLegalNotice(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/mentions-legales');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#editeur', 'Directeur de la publication : Guillaume Couget');
        self::assertSelectorTextContains('#editeur', 'article 6, III, 2');
        self::assertSelectorExists('#editeur a[href="/contact"]');
        self::assertSelectorTextContains('#hebergeur', 'l’hébergeur, SAS au capital de XX 000 €');
        self::assertSelectorTextContains('#hebergeur', '1 rue de l’Exemple, 75000 Paris');
        self::assertSelectorTextContains('#hebergeur', '01 23 45 67 89');
        self::assertSelectorExists('#propriete-intellectuelle');
        self::assertSelectorExists('#credits li');
        self::assertSelectorExists('#donnees-personnelles a[href="/confidentialite"]');
        // Aucune adresse e-mail ni lien « mailto » de l'éditeur
        self::assertCount(0, $crawler->filter('main a[href^="mailto:"]'));
        self::assertDoesNotMatchRegularExpression('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $crawler->filter('main')->text());
    }

    /**
     * Vérifie que l'hébergeur se règle dans les paramètres (seule partie des textes légaux modifiable dans
     * l'admin, décision du 24/10/2026).
     */
    public function testHostComesFromSettings(): void
    {
        $client = self::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $settings = static::getContainer()->get(SiteSettingsRepository::class)->getOrCreate()
            ->setHostName('Hébergeur Test SAS')->setHostAddress('1 rue du Test, 69000 Lyon')->setHostPhone('06 12 34 56 78')->setHostWebsite('https://hebergeur.example');
        $entityManager->persist($settings);
        $entityManager->flush();

        $client->request('GET', '/mentions-legales');

        self::assertSelectorTextContains('#hebergeur', 'Hébergeur Test SAS');
        self::assertSelectorTextContains('#hebergeur', '1 rue du Test, 69000 Lyon');
        self::assertSelectorTextContains('#hebergeur', 'Téléphone : 06 12 34 56 78');
        self::assertSelectorExists('#hebergeur a[href="https://hebergeur.example"]');
    }

    /**
     * Vérifie la politique de confidentialité : toutes les sections, durées, sous-traitants, droits et CNIL.
     */
    public function testPrivacyPolicy(): void
    {
        $client = self::createClient();
        $client->request('GET', '/confidentialite');

        self::assertResponseIsSuccessful();
        foreach (['responsable', 'contact', 'espace-recruteur', 'decision', 'assistant-ia', 'notifications', 'e-mails', 'anti-robot', 'cookies', 'hebergement', 'destinataires', 'bases-legales', 'droits', 'securite'] as $section) {
            // Détail repliable sans JavaScript : élément details natif, titre de niveau 2 dans le résumé
            self::assertSelectorExists('details#'.$section.' > summary > h2', $section);
        }
        self::assertSelectorTextContains('#contact', '12 mois');
        self::assertSelectorTextContains('#anti-robot', 'Cloudflare');
        self::assertSelectorTextContains('#droits', 'CNIL');
        self::assertSelectorExists('#droits a[href^="https://www.cnil.fr/"]');
        self::assertSelectorTextContains('#assistant-ia', 'Mistral AI s\'engage à ne pas utiliser ces échanges');
        self::assertSelectorTextContains('#espace-recruteur', 'Ne plus recevoir ces rappels');
    }

    /**
     * Vérifie le résumé en tête de page : cinq lignes au plus, avant tout détail.
     */
    public function testPrivacySummaryIsShort(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/confidentialite');

        $items = $crawler->filter('[data-testid="privacy-summary"] li');
        self::assertGreaterThan(0, $items->count());
        self::assertLessThanOrEqual(5, $items->count());
        self::assertCount(0, $crawler->filter('[data-testid="privacy-summary"] details'));
    }

    /**
     * Vérifie « Cookies et stockage » : tout ce que le code dépose ou lit dans le navigateur (cookie de session, jeton
     * anti-falsification, Turnstile, thème, calculatrices favorites) et la phrase « aucun bandeau ».
     */
    public function testCookiesSectionListsEveryStorage(): void
    {
        $client = self::createClient();
        $client->request('GET', '/confidentialite');

        foreach (['GC_SESSID', '__Host-csrf-token_', 'Cloudflare Turnstile', 'theme', 'calculator.favorites'] as $name) {
            self::assertSelectorTextContains('[data-testid="cookies-table"]', $name);
        }
        self::assertSelectorTextContains('[data-testid="no-tracking"]', 'Ce site n\'utilise aucun cookie de mesure d\'audience ni de publicité : aucun bandeau de consentement n\'est donc nécessaire.');
    }

    /**
     * Vérifie les tableaux : destinataires (pays, encadrement du transfert, source officielle en https) et bases légales
     * avec durées (demandes d'accès, contact, assistant, notifications, journaux de sécurité).
     */
    public function testRecipientsAndLegalBasisTables(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/confidentialite');

        foreach (['l’hébergeur', 'mistral', 'cloudflare', 'google', 'telegram', 'discord'] as $recipient) {
            $row = $crawler->filter('[data-testid="recipients-table"] [data-recipient="'.$recipient.'"]');
            self::assertCount(1, $row, $recipient);
            self::assertCount(5, $row->filter('th, td'), $recipient);
            self::assertStringStartsWith('https://', (string) $row->filter('a')->attr('href'), $recipient);
        }
        self::assertSelectorTextContains('[data-recipient="cloudflare"]', 'Data Privacy Framework');
        foreach (['access' => 'précontractuelles', 'contact' => 'Intérêt légitime', 'assistant' => 'consentement', 'notifications' => '90 jours', 'security' => 'Obligation de sécurité'] as $processing => $expected) {
            self::assertSelectorTextContains('[data-testid="legal-basis-table"] [data-processing="'.$processing.'"]', $expected);
        }
    }

    /**
     * Vérifie la section « Score et synthèse » (article 22) selon le réglage de validation automatique : sans elle,
     * toute décision est prise à la main ; avec elle, le seuil est annoncé, un refus restant toujours humain.
     */
    public function testDecisionSectionFollowsAutoApprovalSetting(): void
    {
        $client = self::createClient();
        $client->request('GET', '/confidentialite');
        self::assertSelectorTextContains('#decision', 'Chaque demande est acceptée ou refusée par Guillaume Couget lui-même');
        self::assertSelectorTextContains('#decision', 'article 22');

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $settings = static::getContainer()->get(SiteSettingsRepository::class)->getOrCreate()->setAutoApprovalEnabled(true)->setAutoApprovalThreshold(70);
        $entityManager->persist($settings);
        $entityManager->flush();
        $client->request('GET', '/confidentialite');
        self::assertSelectorTextContains('#decision', 'atteint 70 sur 100 est acceptée automatiquement');
        self::assertSelectorTextContains('#decision', 'aucune demande n\'est refusée par un traitement automatisé');
    }

    /**
     * Vérifie la mention courte sous chaque formulaire public (finalité, durée, lien vers la section de la politique).
     */
    public function testPublicFormsShowAShortPrivacyNotice(): void
    {
        $client = self::createClient();
        foreach (['/contact' => 1, '/espace-recruteur' => 2, '/espace-recruteur/lien-invalide' => 1] as $url => $forms) {
            $crawler = $client->request('GET', $url);
            $notices = $crawler->filter('form [data-testid="form-privacy-notice"]');
            self::assertCount($forms, $notices, $url);
            foreach ($notices as $notice) {
                self::assertMatchesRegularExpression('/\d+ (mois|jours|minutes)/', (string) $notice->textContent, $url);
            }
            self::assertCount($forms, $crawler->filter('form [data-testid="form-privacy-notice"] a[href^="/confidentialite#"]'), $url);
        }
    }

    /**
     * Vérifie l'adresse dédiée aux droits : jamais en clair dans le HTML (parties inversées, texte de repli
     * sans arobase), présente seulement dans la politique de confidentialité.
     */
    public function testRgpdAddressIsProtectedAndOnlyOnPrivacyPage(): void
    {
        $client = self::createClient();
        $client->request('GET', '/confidentialite');
        $html = (string) $client->getResponse()->getContent();

        self::assertStringNotContainsString('rgpd@', $html);
        self::assertStringNotContainsString('mailto:', $html);
        self::assertSelectorTextContains('#droits [data-testid="rgpd-email"]', 'rgpd [arobase] guillaume-couget [point] fr');
        self::assertSelectorExists('#droits [data-controller="protected-email"][data-protected-email-user-value="dpgr"][data-protected-email-domain-value="tset.elpmaxe"]');

        foreach (['/', '/cv', '/contact', '/mentions-legales', '/espace-recruteur', '/projets', '/calculatrice'] as $url) {
            $client->request('GET', $url);
            self::assertStringNotContainsString('rgpd', mb_strtolower((string) $client->getResponse()->getContent()), $url);
        }
    }
}
