<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Service\Ai\ChatContextBuilder;
use App\Service\Ai\GuidedAssistant;
use App\Service\Cv\CvPdfRenderer;
use App\Service\Import\CvContentSynchronizer;
use App\Service\Import\TextesSiteParser;
use App\Service\Recruiter\RecruiterAccessManager;
use App\Tests\Support\RecruiterTestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/**
 * Le plancher de rémunération (ligne 🚫 de TEXTES_SITE.md) n'est jamais importé ni affiché : ni en public,
 * ni dans l'espace recruteur, ni dans les PDF, ni dans le contexte envoyé au chatbot. Les prétentions 🔒,
 * elles, ne sont visibles que dans l'espace recruteur et le CV PDF complet.
 *
 * La fixture utilise des montants fictifs (prétentions XX à YY k€, plancher XX k€) ; le vrai fichier,
 * hors dépôt, est vérifié sur le poste de développement sans que son montant soit écrit dans le dépôt.
 *
 * Liens :
 * - Couvre : TextesSiteParser (lignes 🔒 / 🚫 du § 3), CvContentSynchronizer, CvContentProvider (public /
 *   privé), CvPdfRenderer, ChatContextBuilder, GuidedAssistant, RecruiterAreaController (accueil)
 * - Fixture : tests/Fixtures/textes_site_sample.md
 */
final class SalaryFloorNeverExposedTest extends WebTestCase
{
    use RecruiterTestTrait;

    /** Fixture (personne fictive). */
    private const FIXTURE = __DIR__.'/../Fixtures/textes_site_sample.md';

    /** Fichier de textes réel, hors dépôt (présent sur le poste de développement seulement). */
    private const TEXTES_SITE = __DIR__.'/../../../TEXTES_SITE.md';

    /** Prétentions de la fixture (ligne 🔒). */
    private const SALARY = 'XX à YY k€ brut annuel, selon le poste';

    /**
     * Vérifie, après import de la fixture, chaque endroit où le CV est montré ou envoyé.
     */
    public function testFloorAppearsNowhereAndSalaryOnlyInTheRecruiterArea(): void
    {
        $client = self::createClient();
        $container = static::getContainer();
        $markdown = (string) file_get_contents(self::FIXTURE);
        $floor = $this->floorPattern($markdown);
        $content = $container->get(TextesSiteParser::class)->extract($markdown);
        self::assertDoesNotMatchRegularExpression($floor, (string) json_encode($content, \JSON_UNESCAPED_UNICODE));
        $container->get(CvContentSynchronizer::class)->synchronize($content, true);

        // Public : pages, PDF publics, contexte du chatbot et réponses de l'assistant guidé
        $public = [];
        foreach (['/', '/cv'] as $url) {
            $client->request('GET', $url);
            self::assertResponseIsSuccessful();
            $public[$url] = (string) $client->getResponse()->getContent();
        }
        $pdf = $container->get(CvPdfRenderer::class);
        foreach (CvPdfRenderer::VARIANTS as $variant) {
            $public['PDF '.$variant] = $pdf->renderHtml($variant);
        }
        $chat = $container->get(ChatContextBuilder::class);
        // Chatbot de l'espace recruteur : prétentions envoyées seulement si elles y sont affichées (case
        // cochée par la fixture), jamais le plancher
        $prompt = $chat->buildSystemPrompt('Mistral AI', 'modele-de-test');
        self::assertDoesNotMatchRegularExpression($floor, $prompt, 'Plancher envoyé à l\'IA');
        self::assertStringContainsString(self::SALARY, $prompt, 'Prétentions affichées dans l\'espace recruteur, donc connues de l\'assistant.');
        foreach (GuidedAssistant::TOPICS as $topic) {
            $public['assistant guidé : '.$topic] = $container->get(GuidedAssistant::class)->answer($topic);
        }
        foreach ($public as $where => $text) {
            self::assertDoesNotMatchRegularExpression($floor, $text, 'Plancher visible : '.$where);
            self::assertStringNotContainsString('38 à 42', $text, 'Prétentions visibles hors de l\'espace recruteur : '.$where);
        }

        // Espace recruteur : prétentions présentes, plancher absent
        $private = ['PDF complet ats' => $pdf->renderHtml('ats', true), 'PDF complet design' => $pdf->renderHtml('design', true)];
        $this->createRecruiter('plancher@acme.test');
        $container->get(RecruiterAccessManager::class)->sendLoginLink('plancher@acme.test');
        $this->logIn($client, $this->extractRecruiterLink($this->lastEmail()));
        $client->request('GET', '/espace-recruteur/accueil');
        self::assertResponseIsSuccessful();
        $private['espace recruteur'] = (string) $client->getResponse()->getContent();
        foreach ($private as $where => $text) {
            self::assertDoesNotMatchRegularExpression($floor, $text, 'Plancher visible : '.$where);
            self::assertStringContainsString(self::SALARY, $text, 'Prétentions absentes : '.$where);
        }
    }

    /**
     * Vérifie que le vrai TEXTES_SITE.md, analysé, ne contient pas son plancher (poste de développement).
     */
    public function testRealTextesSiteFloorIsNeverExtracted(): void
    {
        if (!is_file(self::TEXTES_SITE)) {
            self::markTestSkipped('TEXTES_SITE.md absent (hors dépôt) : seule la fixture est vérifiée.');
        }
        $markdown = (string) file_get_contents(self::TEXTES_SITE);
        $content = (new TextesSiteParser())->extract($markdown);

        self::assertDoesNotMatchRegularExpression($this->floorPattern($markdown), (string) json_encode($content, \JSON_UNESCAPED_UNICODE));
        self::assertArrayHasKey('salary', $content['recruiter_only'], 'Prétentions 🔒 lues.');
    }

    /**
     * Construit l'expression qui reconnaît le plancher personnel (ligne 🚫 « Plancher … : N k€ … ») sous ses
     * formes courantes (« N k€ », « Nk€ », « N 000 € », « N000 »), sans écrire le montant dans le test.
     *
     * @param string $markdown contenu du fichier de textes
     */
    private function floorPattern(string $markdown): string
    {
        self::assertSame(1, preg_match('/^.*🚫.*Plancher[^:]*:\s*(\d+)\s*k€/mu', $markdown, $matches), 'Ligne 🚫 du plancher introuvable.');

        return '/(?<!\d)'.$matches[1].'(?:\s*k€|[\s\x{202F}\x{A0}.]?000)(?!\d)/u';
    }

    /**
     * Ouvre un lien de connexion (page de confirmation), puis envoie le formulaire de connexion (POST).
     */
    private function logIn(KernelBrowser $client, string $link): void
    {
        $crawler = $client->request('GET', $link);
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Me connecter')->form());
    }

    /**
     * Dernier e-mail envoyé (lien de connexion).
     */
    private function lastEmail(): Email
    {
        $messages = array_values(array_filter(self::getMailerMessages(), static fn ($m): bool => $m instanceof Email));
        $last = end($messages);
        self::assertInstanceOf(Email::class, $last);

        return $last;
    }
}
