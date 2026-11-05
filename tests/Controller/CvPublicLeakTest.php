<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Certification;
use App\Entity\CvEntry;
use App\Entity\Education;
use App\Entity\Experience;
use App\Entity\Language;
use App\Entity\Profile;
use App\Enum\CareerSection;
use App\Enum\CertificationType;
use App\Enum\CvEntrySection;
use App\Service\Ai\ChatContextBuilder;
use App\Service\Ai\GuidedAssistant;
use App\Service\Content\CvContentProvider;
use App\Service\Cv\CvPdfRenderer;
use App\Tests\Support\RecruiterTestTrait;
use App\Tests\Support\VisibleTextAssertionsTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * CV public allégé (correction de recette n° 1, 2026-10-30) : les puces non cochées « Visible en public », le détail
 * du parcours terrain, le texte complet des rubriques (outils, expertises, logiciels), les outils des expériences et
 * les centres d'intérêt n'apparaissent NULLE PART côté public — HTML complet de l'accueil, de /cv (éléments masqués,
 * données structurées JSON-LD et balises de partage compris), plan du site, page Nouveautés, PDF publics, assistant
 * guidé — mais restent dans l'espace recruteur, le CV complet en PDF et le contexte de l'assistant IA. Même règle pour
 * les rubriques « Langues » et « Formations et certifications » réservées (correction de recette n° 2), dont les deux
 * listes « Diplômes et titres » et « Certifications et habilitations » sont triées par année décroissante (n° 3).
 *
 * Liens :
 * - Couvre : CvContentProvider (getContent(), getFullContent(), getPrivateContent()), CvController, CvPdfRenderer,
 *   GuidedAssistant, ChatContextBuilder::buildCvText(), templates/cv/*, recruiter_area/home.html.twig
 */
final class CvPublicLeakTest extends WebTestCase
{
    use RecruiterTestTrait;
    use VisibleTextAssertionsTrait;

    /** Textes réservés à l'espace recruteur, chacun unique pour être cherché dans les pages. */
    private const PRIVATE_TEXTS = [
        'PUCE-PRIVEE-PRO',
        'PUCE-PRIVEE-FONDATRICE',
        'DETAIL-TERRAIN-PRIVE',
        'OUTIL-XP-PRIVE',
        'OUTILS-COMPLET-PRIVE',
        'EXPERTISE-COMPLETE-PRIVEE',
        'LOGICIELS-COMPLETS-PRIVES',
        'LOGICIEL-SANS-RESUME',
        'INTERET-PRIVE',
        'Terrariophilie-essai',
        // Rubriques réservées (correction de recette n° 2) : langues, diplômes, certifications et habilitations
        'LANGUE-PRIVEE',
        'DIPLOME-PRIVE-2019',
        'DIPLOME-PRIVE-1995',
        'ATTESTATION-PRIVEE-2011',
        'HABILITATION-PRIVEE-2021',
    ];

    /**
     * Vérifie que rien de réservé n'apparaît dans les pages publiques (réponse HTML ou XML entière).
     */
    #[DataProvider('providePublicUrls')]
    public function testNothingReservedOnPublicPages(string $url): void
    {
        $client = self::createClient();
        $this->createContent();
        $client->request('GET', $url);

        self::assertResponseIsSuccessful();
        $this->assertNoPrivateText((string) $client->getResponse()->getContent(), $url);
    }

    /**
     * Pages publiques qui affichent ou référencent le CV.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function providePublicUrls(): iterable
    {
        yield 'accueil' => ['/'];
        yield 'cv' => ['/cv'];
        yield 'plan du site' => ['/sitemap.xml'];
        yield 'nouveautés' => ['/nouveautes'];
    }

    /**
     * Vérifie le CV public : puces publiques, mention « + n points », résumés des rubriques, ni centres d'intérêt
     * ni description du parcours terrain.
     */
    public function testPublicCvShowsSyntheticContent(): void
    {
        $client = self::createClient();
        $this->createContent();
        $crawler = $client->request('GET', '/cv');

        self::assertSelectorTextContains('main', 'Puce publique professionnelle.');
        self::assertSelectorTextContains('main', 'Puce publique fondatrice.');
        self::assertSame(['+ 1 point détaillé dans l\'espace recruteur', '+ 1 point détaillé dans l\'espace recruteur'], $crawler->filter('[data-testid="cv-hidden-count"]')->each(static fn ($node): string => trim(str_replace('🔒', '', self::withoutHyphenation($node->text())))));
        self::assertSelectorTextContains('#cv-tools + ul', 'Résumé outils public.');
        self::assertSelectorTextContains('#cv-software + ul', 'Conception : Résumé logiciels public.');
        self::assertSelectorNotExists('#cv-interest');
        self::assertSelectorTextContains('#cv-field + details', 'ZETA ÉNERGIE');
    }

    /**
     * Vérifie que les deux PDF publics (HTML passé à mPDF) ne contiennent rien de réservé, et que le CV complet
     * (espace recruteur) contient tout.
     */
    #[DataProvider('provideVariants')]
    public function testPublicPdfsAreSyntheticAndFullPdfsComplete(string $variant): void
    {
        self::bootKernel();
        $this->createContent();
        $renderer = static::getContainer()->get(CvPdfRenderer::class);

        $public = $renderer->renderHtml($variant);
        $this->assertNoPrivateText($public, 'PDF '.$variant);
        self::assertStringContainsString('Puce publique professionnelle.', $public);
        self::assertStringContainsString('CV complet sur demande : guillaume-couget.fr/espace-recruteur', $public);
        self::assertStringContainsString('Coordonnées sur demande via l&#039;espace recruteur', $public);

        $full = $renderer->renderHtml($variant, true);
        // CV complet : diplômes puis certifications, chacun par année décroissante
        foreach ([['DIPLOME-PRIVE-2019', 'DIPLOME-PRIVE-1995'], ['HABILITATION-PRIVEE-2021', 'ATTESTATION-PRIVEE-2011'], ['DIPLOME-PRIVE-1995', 'Certifications et habilitations']] as [$before, $after]) {
            self::assertLessThan(strpos($full, $after), strpos($full, $before), $variant.' : '.$before.' avant '.$after);
        }
        foreach (self::PRIVATE_TEXTS as $text) {
            if ('OUTIL-XP-PRIVE' !== $text) {
                // Insensible à la casse : le PDF met en minuscule la 1re lettre de la description du parcours terrain
                self::assertStringContainsStringIgnoringCase($text, $full, 'CV complet '.$variant);
            }
        }
        self::assertStringNotContainsString('CV complet sur demande', $full);
    }

    /**
     * Versions du PDF.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function provideVariants(): iterable
    {
        yield 'ats' => ['ats'];
        yield 'design' => ['design'];
    }

    /**
     * Vérifie que le CV synthétique mis en page tient sur une page.
     */
    public function testSyntheticDesignPdfHasOnePage(): void
    {
        self::bootKernel();
        $this->createContent();
        $pdf = static::getContainer()->get(CvPdfRenderer::class)->render('design');

        self::assertSame(1, preg_match_all('#/Type\s*/Page[^s]#', $pdf));
    }

    /**
     * Vérifie que l'assistant guidé (contenu public) ne révèle rien de réservé, et que le contexte de l'assistant IA
     * garde tout le détail, sans note privée.
     */
    public function testGuidedAssistantIsSyntheticAndAiContextIsComplete(): void
    {
        self::bootKernel();
        $this->createContent();
        $guidedAssistant = static::getContainer()->get(GuidedAssistant::class);
        foreach (GuidedAssistant::TOPICS as $topic) {
            $this->assertNoPrivateText($guidedAssistant->answer($topic), 'assistant guidé '.$topic);
        }

        $context = static::getContainer()->get(ChatContextBuilder::class)->buildCvText();
        foreach (['PUCE-PRIVEE-PRO', 'PUCE-PRIVEE-FONDATRICE', 'DETAIL-TERRAIN-PRIVE', 'OUTILS-COMPLET-PRIVE', 'INTERET-PRIVE', 'LANGUE-PRIVEE', 'DIPLOME-PRIVE-2019', 'ATTESTATION-PRIVEE-2011', 'HABILITATION-PRIVEE-2021'] as $text) {
            self::assertStringContainsString($text, $context);
        }
        self::assertStringNotContainsString('NOTE-PRIVEE', $context);
        self::assertFalse(static::getContainer()->get(CvContentProvider::class)->getFullContent()['synthetic']);
    }

    /**
     * Vérifie l'espace recruteur : toutes les puces, le parcours terrain détaillé, les rubriques complètes et les
     * centres d'intérêt ; boutons « CV complet (PDF) » et « Version ATS complète (PDF) ».
     */
    public function testRecruiterAreaShowsEverything(): void
    {
        $client = self::createClient();
        $this->createContent();
        $client->loginUser($this->createRecruiter(), 'recruiter');
        $client->request('GET', '/espace-recruteur/accueil');

        self::assertResponseIsSuccessful();
        $html = self::withoutHyphenation((string) $client->getResponse()->getContent());
        foreach (self::PRIVATE_TEXTS as $text) {
            if ('OUTIL-XP-PRIVE' !== $text) {
                self::assertStringContainsString($text, $html, $text);
            }
        }
        self::assertSelectorNotExists('[data-testid="cv-hidden-count"]');
        // Deux listes, chacune par année décroissante, quel que soit l'ordre saisi (correction de recette n° 3)
        self::assertSame(['2019', '1995'], $client->getCrawler()->filter('[data-testid="cv-diplomas"] .cv-date')->each(static fn ($node): string => trim($node->text())));
        self::assertSame(['2021', '2011'], $client->getCrawler()->filter('[data-testid="cv-certifications"] .cv-date')->each(static fn ($node): string => trim($node->text())));
        self::assertSelectorTextContains('main', 'Diplômes et titres');
        self::assertSelectorTextContains('main', 'Certifications et habilitations');
        self::assertSelectorTextContains('main', 'CV complet (PDF)');
        self::assertSelectorTextContains('main', 'Version ATS complète (PDF)');
    }

    /**
     * Vérifie la note du CV public : lien « Demander un accès » avec le délai des paramètres ; pour un recruteur
     * connecté, « voir le CV complet » vers son espace.
     */
    public function testSyntheticNoteAdaptsToRecruiterSession(): void
    {
        $client = self::createClient();
        $this->createContent();
        $client->request('GET', '/cv');
        $lines = $client->getCrawler()->filter('[data-testid="cv-synthetic-note"] p')->each(static fn ($node): string => preg_replace('/\s+/u', ' ', trim(self::withoutHyphenation($node->text()))));
        self::assertSame([
            'Ceci est une version courte de mon CV.',
            '→ Missions détaillées, formations, langues et coordonnées sont dans l\'espace recruteur.',
            'Accès gratuit et sécurisé : demande en moins d\'une minute, réponse sous 24 h ouvrées. → Demander votre accès',
        ], $lines);
        self::assertSelectorExists('[data-testid="cv-synthetic-note"] p:first-child.font-semibold');
        self::assertSelectorExists('[data-testid="cv-synthetic-note"] p:last-child a[href="/espace-recruteur"]');
        self::assertNull($client->getCookieJar()->get('GC_SESSID'), 'Aucune session ouverte pour un visiteur.');

        $client->loginUser($this->createRecruiter(), 'recruiter');
        $client->request('GET', '/cv');
        self::assertSelectorTextContains('[data-testid="cv-synthetic-note"]', 'Vous êtes connecté. → Voir le CV complet');
        self::assertSelectorExists('[data-testid="cv-synthetic-note"] a[href="/espace-recruteur/accueil"]');
    }

    /**
     * Vérifie qu'aucun texte réservé n'apparaît dans un contenu public.
     *
     * @param string $content contenu (HTML, XML, texte)
     * @param string $where   page ou document (message d'échec)
     */
    private function assertNoPrivateText(string $content, string $where): void
    {
        // Césures invisibles retirées : un mot coupé ne doit pas faire passer le test à tort
        $content = self::withoutHyphenation($content);
        foreach (self::PRIVATE_TEXTS as $text) {
            self::assertStringNotContainsStringIgnoringCase($text, $content, $where.' : « '.$text.' » ne doit pas apparaître.');
        }
    }

    /**
     * Crée un CV de test : chaque texte réservé est unique (PRIVATE_TEXTS).
     */
    private function createContent(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist((new Profile())->setFullName('Jean Test')->setJobTitle('Responsable technique')->setSummary('Profil public.')
            ->setLanguagesPublic(false)->setEducationsPublic(false));
        $entityManager->persist((new Language())->setName('LANGUE-PRIVEE')->setProficiency('courant')->setPosition(1));
        // Positions dans le désordre : l'affichage trie par année décroissante
        $entityManager->persist((new Education())->setYear(1995)->setTitle('DIPLOME-PRIVE-1995')->setPosition(1));
        $entityManager->persist((new Education())->setYear(2019)->setTitle('DIPLOME-PRIVE-2019')->setPosition(2));
        $entityManager->persist((new Certification())->setType(CertificationType::Certification)->setName('ATTESTATION-PRIVEE-2011')
            ->setObtainedOn(new \DateTimeImmutable('2011-01-01'))->setPosition(1));
        $entityManager->persist((new Certification())->setType(CertificationType::Clearance)->setName('HABILITATION-PRIVEE-2021')
            ->setObtainedOn(new \DateTimeImmutable('2021-01-01'))->setPosition(2));

        $entityManager->persist((new Experience())->setJobTitle('Responsable Bureau d\'Études')->setCompanyName('ALPHA ESSAIS GROUP')
            ->setStartDate(new \DateTimeImmutable('2023-02-01'))->setEndDate(new \DateTimeImmutable('2025-12-01'))
            ->setMissions("Puce publique professionnelle.\nPUCE-PRIVEE-PRO détail du poste.")->setPublicItems(['Puce publique professionnelle.'])
            ->setTools('OUTIL-XP-PRIVE')->setPrivateNote('NOTE-PRIVEE de fin de poste.')->setPosition(1));
        $entityManager->persist((new Experience())->setCareerSection(CareerSection::Foundation)->setJobTitle('Responsable de production')
            ->setCompanyName('DELTA THERMIQUE / EPSILON LABO')->setStartDate(new \DateTimeImmutable('2014-01-01'))->setEndDate(new \DateTimeImmutable('2017-01-01'))
            ->setDatesInYears(true)->setMissions("Puce publique fondatrice.\nPUCE-PRIVEE-FONDATRICE.")->setPublicItems(['Puce publique fondatrice.'])->setPosition(2));
        // Parcours terrain : la description reste privée, même cochée publique
        $entityManager->persist((new Experience())->setCareerSection(CareerSection::Field)->setJobTitle('Technicien de maintenance')
            ->setCompanyName('ZETA ÉNERGIE')->setStartDate(new \DateTimeImmutable('2004-06-01'))->setEndDate(new \DateTimeImmutable('2005-02-01'))
            ->setMissions('DETAIL-TERRAIN-PRIVE maintenance.')->setPublicItems(['DETAIL-TERRAIN-PRIVE maintenance.'])->setPosition(3));

        $entries = [
            [CvEntrySection::Tools, null, 'OUTILS-COMPLET-PRIVE.', 'Résumé outils public.'],
            [CvEntrySection::Expertise, 'Génie climatique', 'EXPERTISE-COMPLETE-PRIVEE.', 'Résumé expertise public.'],
            [CvEntrySection::Software, 'Conception', 'LOGICIELS-COMPLETS-PRIVES.', 'Résumé logiciels public.'],
            [CvEntrySection::Software, 'Autres', 'LOGICIEL-SANS-RESUME.', null],
            [CvEntrySection::SoftSkill, 'Écoute', 'savoir écouter.', null],
            [CvEntrySection::Interest, 'Terrariophilie-essai', 'INTERET-PRIVE.', 'Résumé ignoré : jamais en public.'],
        ];
        foreach ($entries as $index => [$section, $title, $content, $summary]) {
            $entityManager->persist((new CvEntry())->setSection($section)->setTitle($title)->setContent($content)->setPublicSummary($summary)->setPosition($index + 1));
        }

        $entityManager->flush();
        $entityManager->clear();
    }
}
