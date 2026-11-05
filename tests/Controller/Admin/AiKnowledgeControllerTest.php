<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\AiKnowledgeEntry;
use App\Entity\UnansweredQuestion;
use App\Enum\AiKnowledgeCategory;
use App\Repository\AiKnowledgeEntryRepository;
use App\Repository\AiKnowledgeRevisionRepository;
use App\Repository\SiteSettingsRepository;
use App\Service\Cv\CvPdfRenderer;
use App\Tests\Support\AdminTestTrait;
use App\Tests\Support\ConfirmationTrait;
use App\Tests\Support\MockHttpResponseFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Page « Cerveau de l'IA » : trois niveaux affichés, rappel, compteurs de jetons et avertissement, blocage
 * des données privées à l'enregistrement, plafond de taille, historique, export Markdown, questions sans
 * réponse (« Créer une fiche », « Ignorer »), banc d'essai ; fiches jamais affichées en public ni dans les PDF.
 *
 * Liens :
 * - Couvre : Admin\AiKnowledgeController, templates/admin/ai_knowledge/*.html.twig, AiKnowledgeBudget
 */
final class AiKnowledgeControllerTest extends WebTestCase
{
    use AdminTestTrait;
    use ConfirmationTrait;

    protected function setUp(): void
    {
        MockHttpResponseFactory::reset();
    }

    /**
     * Vérifie la page : socle et résumé en lecture seule, rappel, fiches groupées avec leurs jetons, budget.
     */
    public function testPageShowsTheThreeLevels(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $this->addEntry('Mobilité', 'Mobile dans toute l\'Auvergne-Rhône-Alpes.', AiKnowledgeCategory::Personal);

        $client->request('GET', $this->adminUrl('/cerveau-ia'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="ai-knowledge-reminder"]', 'Tout ce qui est écrit ici est envoyé à Mistral AI à chaque question. N\'y mettez rien de confidentiel.');
        self::assertSelectorTextContains('main', '1. Socle de sécurité (fixe)');
        self::assertSelectorTextContains('main', '2. Résumé du site (automatique)');
        self::assertSelectorExists('[data-testid="ai-site-summary"] pre');
        self::assertSelectorTextContains('[data-testid="ai-category-personal"]', 'Mobilité');
        self::assertSelectorTextContains('[data-testid="ai-category-personal"]', 'jetons');
        self::assertSelectorTextContains('[data-testid="ai-knowledge-budget"]', 'autorisés');
        self::assertSelectorTextContains('[data-testid="ai-knowledge-budget"]', 'Questions possibles par mois');
    }

    /**
     * Vérifie le blocage d'une fiche qui contient une donnée privée : rien n'est enregistré, motif expliqué.
     */
    public function testPrivateDataBlocksSaving(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);

        $crawler = $client->request('GET', $this->adminUrl('/cerveau-ia/nouvelle'));
        $client->submit($crawler->selectButton('Enregistrer')->form([
            'ai_knowledge_entry[title]' => 'Contact',
            'ai_knowledge_entry[content]' => 'Appelez-moi au 06 12 34 56 78.',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Cette fiche contient un numéro de téléphone');
        self::assertSame(0, static::getContainer()->get(AiKnowledgeEntryRepository::class)->count([]));

        $client->submit($client->getCrawler()->selectButton('Enregistrer')->form([
            'ai_knowledge_entry[title]' => 'Plancher',
            'ai_knowledge_entry[content]' => 'Pas en dessous de XX k€.',
        ]));
        self::assertSelectorTextContains('main', 'plancher de prétentions');
        self::assertSame(0, static::getContainer()->get(AiKnowledgeEntryRepository::class)->count([]));
    }

    /**
     * Vérifie création, modification (historique), export Markdown et suppression avec confirmation.
     */
    public function testCreateEditHistoryExportDelete(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);

        $crawler = $client->request('GET', $this->adminUrl('/cerveau-ia/nouvelle'));
        $client->submit($crawler->selectButton('Enregistrer')->form([
            'ai_knowledge_entry[title]' => 'Télétravail',
            'ai_knowledge_entry[content]' => 'Deux jours par semaine appréciés.',
            'ai_knowledge_entry[category]' => 'job_preferences',
        ]));
        self::assertResponseRedirects($this->adminUrl('/cerveau-ia'));
        $entry = static::getContainer()->get(AiKnowledgeEntryRepository::class)->findOneBy(['title' => 'Télétravail']);
        self::assertNotNull($entry);
        self::assertTrue($entry->isActive());

        $crawler = $client->request('GET', $this->adminUrl('/cerveau-ia/'.$entry->getId()));
        $client->submit($crawler->selectButton('Enregistrer')->form(['ai_knowledge_entry[content]' => 'Trois jours par semaine appréciés.']));
        self::assertResponseRedirects();
        $revisions = static::getContainer()->get(AiKnowledgeRevisionRepository::class)->findForEntry($entry);
        self::assertCount(1, $revisions);
        self::assertSame('Deux jours par semaine appréciés.', $revisions[0]->getContent());

        $client->request('GET', $this->adminUrl('/cerveau-ia/'.$entry->getId().'/historique'));
        self::assertSelectorTextContains('[data-testid="ai-history"]', 'Deux jours par semaine appréciés.');

        $client->request('GET', $this->adminUrl('/cerveau-ia/export.md'));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/markdown', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('attachment', (string) $client->getResponse()->headers->get('Content-Disposition'));
        $markdown = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('## Préférences de poste', $markdown);
        self::assertStringContainsString('### Télétravail', $markdown);
        self::assertStringContainsString('Trois jours par semaine appréciés.', $markdown);

        $crawler = $client->request('GET', $this->adminUrl('/cerveau-ia'));
        $client->submit($crawler->filter('form[action$="/'.$entry->getId().'/suppression"]')->form());
        $this->confirmAction($client, 'Supprimer la fiche « Télétravail » ?');
        self::assertResponseRedirects($this->adminUrl('/cerveau-ia'));
        self::assertSame(0, static::getContainer()->get(AiKnowledgeEntryRepository::class)->count([]));
    }

    /**
     * Vérifie le plafond de taille : une fiche qui le ferait dépasser ne peut pas être activée.
     */
    public function testSizeLimitBlocksActivation(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $settings = static::getContainer()->get(SiteSettingsRepository::class)->getOrCreate()->setAiKnowledgeTokenLimit(20);
        $this->getEntityManager()->persist($settings);
        $this->getEntityManager()->flush();
        $entry = $this->addEntry('Longue fiche', str_repeat('Une phrase assez longue sur son parcours. ', 5), AiKnowledgeCategory::Faq, false);

        $crawler = $client->request('GET', $this->adminUrl('/cerveau-ia'));
        $client->submit($crawler->filter('form[action$="/'.$entry->getId().'/activation"]')->form());
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'au-delà du plafond de 20');
        $this->getEntityManager()->clear();
        self::assertFalse(static::getContainer()->get(AiKnowledgeEntryRepository::class)->find($entry->getId())?->isActive());
        self::assertSame(20, $settings->getAiKnowledgeTokenLimit());
    }

    /**
     * Vérifie l'avertissement : des fiches lourdes réduisent le nombre de questions possibles par mois.
     */
    public function testBudgetWarning(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $this->addEntry('FAQ détaillée', str_repeat('Réponse détaillée à une question fréquente des recruteurs. ', 120), AiKnowledgeCategory::Faq);

        $client->request('GET', $this->adminUrl('/cerveau-ia'));
        self::assertSelectorTextContains('[data-testid="ai-knowledge-budget-warning"]', 'réduisent de');
    }

    /**
     * Vérifie les questions sans réponse : « Créer une fiche » (pré-remplie, question traitée) et « Ignorer ».
     */
    public function testUnansweredQuestions(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $toCreate = new UnansweredQuestion(new \DateTimeImmutable(), 'Parle-t-il allemand ?');
        $toIgnore = new UnansweredQuestion(new \DateTimeImmutable(), 'A-t-il un véhicule ?', UnansweredQuestion::REASON_BLOCKED, 'Peugeot');
        $this->getEntityManager()->persist($toCreate);
        $this->getEntityManager()->persist($toIgnore);
        $this->getEntityManager()->flush();

        $crawler = $client->request('GET', $this->adminUrl('/cerveau-ia/questions-sans-reponse'));
        self::assertSelectorTextContains('[data-testid="unanswered-list"]', 'Parle-t-il allemand ?');
        self::assertSelectorTextContains('[data-testid="unanswered-list"]', 'Peugeot');

        $client->click($crawler->filter('a[href*="question='.$toCreate->getId().'"]')->link());
        self::assertSelectorTextContains('[data-testid="ai-entry-from-question"]', 'Parle-t-il allemand ?');
        $client->submit($client->getCrawler()->selectButton('Enregistrer')->form(['ai_knowledge_entry[content]' => 'Il parle anglais ; il ne parle pas allemand.']));
        self::assertResponseRedirects();
        self::assertSame('Parle-t-il allemand ?', static::getContainer()->get(AiKnowledgeEntryRepository::class)->findOneBy([])?->getTitle());

        $crawler = $client->request('GET', $this->adminUrl('/cerveau-ia/questions-sans-reponse'));
        $client->submit($crawler->filter('form[action$="/'.$toIgnore->getId().'/ignorer"]')->form());
        $client->followRedirect();
        self::assertSelectorTextContains('main', 'Aucune question sans réponse');
    }

    /**
     * Vérifie le banc d'essai : réponse, fiche utilisée, élément bloquant affichés.
     */
    public function testTestBench(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $this->addEntry('Télétravail', 'Il apprécie deux jours de télétravail par semaine.', AiKnowledgeCategory::JobPreferences);
        MockHttpResponseFactory::$mistralQueue[] = MockHttpResponseFactory::mistralChat('Il apprécie deux jours de télétravail par semaine.');

        $crawler = $client->request('GET', $this->adminUrl('/cerveau-ia'));
        $client->submit($crawler->selectButton('Tester')->form(['question' => 'Télétravail ?']));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="ai-test-result"]', 'Il apprécie deux jours de télétravail par semaine.');
        self::assertSelectorTextContains('[data-testid="ai-test-used"]', 'Télétravail');

        MockHttpResponseFactory::$mistralQueue[] = MockHttpResponseFactory::mistralChat('Il a travaillé chez Thales.');
        $client->submit($client->getCrawler()->selectButton('Tester')->form(['question' => 'Où ?']));
        self::assertSelectorTextContains('[data-testid="ai-test-blocked"]', 'Thales');

        $client->request('GET', $this->adminUrl('/assistant-ia'));
        self::assertSelectorTextContains('main', 'Essai admin');
    }

    /**
     * Vérifie que les fiches ne sont jamais affichées en public ni dans les PDF.
     */
    public function testEntriesNeverShownPublicly(): void
    {
        $client = self::createClient();
        $this->addEntry('Secret de fiche', 'Phrase témoin réservée à l\'assistant XYZ-FICHE.', AiKnowledgeCategory::Faq);
        foreach (['/', '/cv', '/projets', '/contact', '/espace-recruteur'] as $url) {
            $client->request('GET', $url);
            self::assertStringNotContainsString('XYZ-FICHE', (string) $client->getResponse()->getContent(), $url);
        }
        $pdf = static::getContainer()->get(CvPdfRenderer::class);
        foreach (CvPdfRenderer::VARIANTS as $variant) {
            self::assertStringNotContainsString('XYZ-FICHE', $pdf->renderHtml($variant), $variant);
            self::assertStringNotContainsString('XYZ-FICHE', $pdf->renderHtml($variant, true), $variant.' complet');
        }
    }

    /**
     * Ajoute une fiche directement en base.
     */
    private function addEntry(string $title, string $content, AiKnowledgeCategory $category, bool $active = true): AiKnowledgeEntry
    {
        $entry = (new AiKnowledgeEntry())->setTitle($title)->setContent($content)->setCategory($category)->setActive($active);
        $this->getEntityManager()->persist($entry);
        $this->getEntityManager()->flush();

        return $entry;
    }
}
