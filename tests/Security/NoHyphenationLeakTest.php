<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Project;
use App\Entity\Recruiter;
use App\Enum\NotificationEvent;
use App\Enum\ProjectType;
use App\Enum\ProjectVisibility;
use App\Repository\SiteSettingsRepository;
use App\Service\Ai\ChatAssistant;
use App\Service\Ai\ChatContextBuilder;
use App\Service\Ai\GuidedAssistant;
use App\Service\Content\Hyphenator;
use App\Service\Import\CvContentSynchronizer;
use App\Service\Import\TextesSiteParser;
use App\Service\Notification\Notification;
use App\Service\Notification\NotificationDispatcher;
use App\Service\ProjectPackage\ProjectExporter;
use App\Service\Recruiter\RecruiterAccessManager;
use App\Tests\Support\MockHttpResponseFactory;
use App\Tests\Support\RecruiterTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/**
 * Les césures invisibles (U+00AD) et coupures possibles (U+200B) du texte justifié ne servent qu'à
 * l'affichage des pages : elles n'apparaissent JAMAIS dans ce qui est envoyé à Mistral, dans les balises
 * title, meta description, Open Graph, Twitter, données structurées JSON-LD, ni dans le sitemap, les e-mails
 * (texte et HTML), l'export des projets ou la base (aucune réécriture par la synchronisation).
 *
 * Liens :
 * - Couvre : Hyphenator (filtres Twig hyphenate / hyphenate_html, affichage seulement), ChatContextBuilder,
 *   ChatAssistant (requête envoyée), GuidedAssistant, SeoExtension (base.html.twig), SitemapController,
 *   RecruiterMailer, NotificationDispatcher, ProjectExporter, CvContentSynchronizer
 * - Fixture : tests/Fixtures/textes_site_sample.md (mots longs : « installations », « conventionnelle »…)
 */
final class NoHyphenationLeakTest extends WebTestCase
{
    use RecruiterTestTrait;

    /** Fixture (personne fictive). */
    private const FIXTURE = __DIR__.'/../Fixtures/textes_site_sample.md';

    /** Pages publiques contrôlées (le projet est créé par le test). */
    private const PAGES = ['/', '/cv', '/projets', '/projets/caracterisation-climatique', '/mentions-legales', '/confidentialite', '/calculatrice', '/calculatrice/electricite/loi-ohm', '/contact', '/espace-recruteur'];

    protected function setUp(): void
    {
        MockHttpResponseFactory::reset();
        self::bootKernel();
        $this->clearPublicFormLimits();
        $this->createNotifiedAdmin();
        self::ensureKernelShutdown();
    }

    /**
     * Vérifie les métadonnées des pages et le sitemap ; les pages, elles, sont bien coupées (le filtre agit).
     */
    public function testPageMetadataAndSitemapHaveNoHyphenation(): void
    {
        $client = self::createClient();
        $this->loadContent();

        $bodies = '';
        foreach (self::PAGES as $url) {
            $crawler = $client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
            $head = [$crawler->filter('title')->text('')];
            foreach ($crawler->filter('meta[name="description"], meta[property^="og:"], meta[name^="twitter:"], link[rel="canonical"]') as $node) {
                self::assertInstanceOf(\DOMElement::class, $node);
                $head[] = $node->getAttribute('content').$node->getAttribute('href');
            }
            foreach ($crawler->filter('script[type="application/ld+json"]') as $node) {
                $head[] = $node->textContent;
            }
            $this->assertNoHyphenation(implode("\n", $head), 'métadonnées de '.$url);
            $bodies .= $crawler->filter('main')->text('');
        }
        self::assertStringContainsString(Hyphenator::SOFT_HYPHEN, $bodies, 'Le texte justifié des pages est bien coupé.');

        foreach (['/sitemap.xml', '/robots.txt'] as $url) {
            $client->request('GET', $url);
            $this->assertNoHyphenation((string) $client->getResponse()->getContent(), $url);
        }
    }

    /**
     * Vérifie les consignes et le contexte envoyés à Mistral (requête réelle simulée), l'assistant guidé.
     */
    public function testNothingSentToTheAiIsHyphenated(): void
    {
        $client = self::createClient();
        $this->loadContent();
        $client->request('GET', '/cv');
        $container = static::getContainer();
        $settings = $container->get(SiteSettingsRepository::class)->getOrCreate()->setAiRequestsPerSecond(20);
        $container->get(EntityManagerInterface::class)->persist($settings);
        $container->get(EntityManagerInterface::class)->flush();

        $builder = $container->get(ChatContextBuilder::class);
        $this->assertNoHyphenation($builder->buildSystemPrompt('Mistral AI', 'modele-principal-test'), 'consignes et contexte');
        $this->assertNoHyphenation($builder->buildInstructions('Mistral AI', 'modele-principal-test'), 'consignes');
        foreach (GuidedAssistant::TOPICS as $topic) {
            $this->assertNoHyphenation($container->get(GuidedAssistant::class)->answer($topic), 'assistant guidé : '.$topic);
        }

        $container->get(ChatAssistant::class)->ask($this->createRecruiter('ia@acme.test'), 'Quelles installations frigorifiques a-t-il conçues ?');
        $requests = MockHttpResponseFactory::requestsTo('/v1/chat');
        self::assertNotEmpty($requests, 'Une requête est partie vers l\'API simulée.');
        foreach ($requests as $request) {
            $this->assertNoHyphenation($request['body'], 'requête envoyée à Mistral');
        }
    }

    /**
     * Vérifie les e-mails (texte et HTML) : accusé de réception, notification, validation, lien de connexion.
     */
    public function testEmailsHaveNoHyphenation(): void
    {
        $client = self::createClient();
        $this->loadContent();
        $client->request('GET', '/cv');
        $container = static::getContainer();
        $manager = $container->get(RecruiterAccessManager::class);

        $manager->requestAccess((new Recruiter(new \DateTimeImmutable()))->setEmail('caracterisation@acme.test')
            ->setFullName('Dominique Caractérisation')->setCompany('Établissements climatiques')
            ->setMessage('Recrutement : responsable des installations frigorifiques.'), '127.0.0.1');
        $recruiter = $container->get(EntityManagerInterface::class)->getRepository(Recruiter::class)->findOneBy(['email' => 'caracterisation@acme.test']);
        self::assertInstanceOf(Recruiter::class, $recruiter);
        $manager->approve($recruiter);
        $manager->sendLoginLink('caracterisation@acme.test');
        $container->get(NotificationDispatcher::class)->notify(new Notification(NotificationEvent::Contact, 'Nouveau message : caractérisation', ['Entreprise' => 'Établissements climatiques'], 'https://localhost/admin'));

        $emails = array_filter(self::getMailerMessages(), static fn ($m): bool => $m instanceof Email);
        self::assertGreaterThanOrEqual(4, \count($emails));
        foreach ($emails as $email) {
            $this->assertNoHyphenation((string) $email->getSubject().$email->getTextBody().$email->getHtmlBody(), 'e-mail « '.$email->getSubject().' »');
        }
    }

    /**
     * Vérifie l'export d'un projet et la base : rien n'est réécrit avec des césures après l'affichage des pages.
     */
    public function testExportAndDatabaseHaveNoHyphenation(): void
    {
        $client = self::createClient();
        $this->loadContent();
        foreach (self::PAGES as $url) {
            $client->request('GET', $url);
        }
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $entityManager->clear();

        // Synchronisation : rien à changer après l'affichage des pages (la base n'a pas été réécrite)
        $content = $container->get(TextesSiteParser::class)->extract((string) file_get_contents(self::FIXTURE));
        self::assertSame([], $container->get(CvContentSynchronizer::class)->synchronize($content, false));

        $connection = $entityManager->getConnection();
        foreach (['profile', 'experience', 'cv_entry', 'project', 'education', 'certification', 'key_figure', 'skill_pillar', 'language'] as $table) {
            $columns = array_filter($connection->createSchemaManager()->listTableColumns($table), static fn ($c): bool => \in_array($c->getType()::class, [\Doctrine\DBAL\Types\StringType::class, \Doctrine\DBAL\Types\TextType::class], true));
            foreach ($columns as $column) {
                $count = (int) $connection->fetchOne(\sprintf('SELECT COUNT(*) FROM %s WHERE %s LIKE ? OR %s LIKE ?', $table, $column->getName(), $column->getName()), ['%'.Hyphenator::SOFT_HYPHEN.'%', '%'.Hyphenator::BREAK_OPPORTUNITY.'%']);
                self::assertSame(0, $count, \sprintf('Césure enregistrée en base : %s.%s', $table, $column->getName()));
            }
        }

        $project = $entityManager->getRepository(Project::class)->findOneBy(['slug' => 'caracterisation-climatique']);
        self::assertInstanceOf(Project::class, $project);
        $archive = $container->get(ProjectExporter::class)->createArchive($project);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($archive));
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = (string) $zip->getNameIndex($i);
            if (!str_ends_with($name, '.webp')) {
                $this->assertNoHyphenation((string) $zip->getFromIndex($i), 'export : '.$name);
            }
        }
        $zip->close();
        @unlink($archive);
    }

    /**
     * Charge le contenu de la fixture et un projet publié aux mots longs.
     */
    private function loadContent(): void
    {
        $container = static::getContainer();
        $content = $container->get(TextesSiteParser::class)->extract((string) file_get_contents(self::FIXTURE));
        $container->get(CvContentSynchronizer::class)->synchronize($content, true);

        $entityManager = $container->get(EntityManagerInterface::class);
        $entityManager->persist((new Project())->setType(ProjectType::CaseStudy)->setTitle('Caractérisation climatique')
            ->setSlug('caracterisation-climatique')->setSummary('Caractérisation et qualification des enceintes climatiques industrielles.')
            ->setClientLabel('Un laboratoire pharmaceutique')->setContext('Contexte : réglementation pharmaceutique.')
            ->setProblem('Problème : homogénéité insuffisante.')->setSolution('Solution : régulation proportionnelle.')
            ->setResult('Résultat : stabilité améliorée.')->setCompetences("Métrologie\nRégulation")
            ->setContent("## Caractérisation\n\nLa caractérisation thermique des installations frigorifiques.")
            ->setIsPublished(true)->setVisibility(ProjectVisibility::Public));
        $entityManager->flush();
    }

    /**
     * Échoue si le texte contient une césure invisible ou une coupure possible.
     */
    private function assertNoHyphenation(string $text, string $where): void
    {
        self::assertStringNotContainsString(Hyphenator::SOFT_HYPHEN, $text, 'Césure invisible dans : '.$where);
        self::assertStringNotContainsString(Hyphenator::BREAK_OPPORTUNITY, $text, 'Coupure invisible dans : '.$where);
    }
}
