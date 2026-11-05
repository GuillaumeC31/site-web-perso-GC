<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CvEntry;
use App\Entity\Education;
use App\Entity\Experience;
use App\Entity\KeyFigure;
use App\Entity\Language;
use App\Entity\Profile;
use App\Entity\SkillPillar;
use App\Enum\CareerSection;
use App\Enum\CvEntrySection;
use App\Service\Cv\CvPdfRenderer;
use App\Tests\Support\VisibleTextAssertionsTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests du lot 3 : accueil, CV interactif et PDF publics (CV synthétique depuis le 2026-10-30 : détail de la
 * non-fuite dans CvPublicLeakTest).
 *
 * Règles vérifiées : aucune coordonnée en public (page ni PDF), nom d'entreprise
 * anonymisée jamais affiché, chiffres et réalisations « à compléter » masqués.
 *
 * Liens :
 * - Couvre : HomeController, CvController, CvContentProvider, CvPdfRenderer, templates home/ et cv/
 */
final class CvPagesTest extends WebTestCase
{
    use VisibleTextAssertionsTrait;
    /** Coordonnées privées fictives, enregistrées dans le profil pour vérifier qu'elles ne sortent jamais. */
    private const PRIVATE_VALUES = ['06 12 34 56 78', 'prive@example.test', '12 rue Secrète', 'XX k€'];

    /**
     * Vérifie l'accueil : textes du profil, chiffres complets seulement, parcours en bref.
     */
    public function testHomeShowsProfileAndHidesIncompleteFigures(): void
    {
        $client = self::createClient();
        $this->createContent();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Jean Test');
        self::assertSelectorTextContains('main', 'Disponible immédiatement · Lyon et Auvergne-Rhône-Alpes · Sur site · hybride · télétravail');
        self::assertSelectorTextContains('main', 'Plus de 25 ans');
        self::assertSelectorTextNotContains('main', 'personnes encadrées');
        self::assertSelectorTextContains('main', 'CAO/DAO, dimensionnement');
        self::assertSelectorTextContains('main', 'Responsable Bureau d\'Études');
        self::assertSelectorTextNotContains('main', '[nb]');
        self::assertSelectorTextNotContains('main', 'COMPLÉTER');
    }

    /**
     * Vérifie le CV : expériences, nom d'entreprise anonymisé remplacé, réalisations incomplètes masquées.
     */
    public function testCvHidesAnonymizedCompanyAndIncompleteAchievements(): void
    {
        $client = self::createClient();
        $this->createContent();
        $client->request('GET', '/cv');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'ALPHA ESSAIS GROUP');
        self::assertSelectorTextContains('main', 'Réduit les délais d\'études de 20 %');
        self::assertSelectorTextNotContains('main', 'personnes au bureau');
        self::assertSelectorTextContains('main', 'PME industrielle, 80 salariés');
        self::assertSelectorTextNotContains('body', 'Vrai Nom Confidentiel');
        self::assertSelectorTextContains('main', '2017 – 2019');
        self::assertSelectorTextContains('main', '05/2021 – 12/2025');
        // Note privée (motif de fin de poste) : jamais en public
        self::assertSelectorTextNotContains('body', 'Fin de mission');
    }

    /**
     * Vérifie l'ordre des rubriques du CV de référence et le regroupement « Expérience fondatrice ».
     */
    public function testCvFollowsReferenceOrder(): void
    {
        $client = self::createClient();
        $this->createContent();
        $crawler = $client->request('GET', '/cv');

        $headings = $crawler->filter('main h2.cv-heading')->each(static fn ($node): string => $node->text());
        self::assertSame([
            'Profil', 'Expériences professionnelles', 'Expérience fondatrice — Conception, production et terrain',
            'Ce que je recherche', 'Outils techniques au service des équipes', 'Parcours terrain', 'Expertises métiers',
            'Logiciels et environnements techniques', 'Savoir-être', 'Langues', 'Formations et certifications',
        ], $headings, 'CV public synthétique : sans centres d\'intérêt (espace recruteur).');
        // Deux postes de la même entreprise : un seul en-tête, deux postes datés
        self::assertCount(1, $crawler->filter('#cv-foundation + ol > li'));
        self::assertSelectorTextContains('#cv-foundation + ol', '2014 – Responsable de production');
        self::assertSelectorTextContains('#cv-foundation + ol', '2007 – Technicien de production');
        self::assertSelectorTextContains('#cv-foundation + ol', '03/2005 – 01/2017');
        // Majuscule initiale sans toucher au reste
        self::assertSelectorTextContains('main', 'Savoir écouter');
    }

    /**
     * Vérifie qu'aucune coordonnée privée du profil n'apparaît sur l'accueil ni sur le CV.
     */
    #[DataProvider('providePublicCvUrls')]
    public function testNoPrivateDataOnPublicPages(string $url): void
    {
        $client = self::createClient();
        $this->createContent();
        $client->request('GET', $url);
        $html = (string) $client->getResponse()->getContent();

        foreach (self::PRIVATE_VALUES as $private) {
            self::assertStringNotContainsString($private, $html, $url);
        }
        self::assertStringNotContainsString('Vrai Nom Confidentiel', $html);
    }

    /**
     * URL publiques qui affichent le contenu du CV.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function providePublicCvUrls(): iterable
    {
        yield 'accueil' => ['/'];
        yield 'cv' => ['/cv'];
    }

    /**
     * Vérifie que les deux PDF sont produits et téléchargés.
     */
    #[DataProvider('providePdfVariants')]
    public function testPdfIsDownloaded(string $variant, string $filename): void
    {
        $client = self::createClient();
        $this->createContent();
        $client->request('GET', '/cv/pdf/'.$variant);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringContainsString($filename, (string) $client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringStartsWith('%PDF-', (string) $client->getResponse()->getContent());
    }

    /**
     * Vérifie que le contenu des PDF ne contient aucune coordonnée privée ni nom anonymisé.
     */
    #[DataProvider('providePdfVariantNames')]
    public function testPdfContainsNoPrivateData(string $variant): void
    {
        self::bootKernel();
        $this->createContent();
        $html = static::getContainer()->get(CvPdfRenderer::class)->renderHtml($variant);

        self::assertStringContainsString('ALPHA ESSAIS GROUP', $html);
        self::assertStringNotContainsString('Fin de mission', $html);
        foreach (self::PRIVATE_VALUES as $private) {
            self::assertStringNotContainsString($private, $html);
        }
        self::assertStringNotContainsString('Vrai Nom Confidentiel', $html);
        self::assertStringNotContainsString('[nb]', $html);
        self::assertDoesNotMatchRegularExpression('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $html);
    }

    /**
     * Vérifie que, sans photo envoyée depuis l'admin, l'accueil et le CV affichent la photo par défaut.
     */
    public function testPagesShowDefaultPhotoWithoutAdminPhoto(): void
    {
        $client = self::createClient();
        $this->createContent();

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('img[src$="images/photos/guillaume-couget.webp"][width="800"][height="1000"][loading="eager"][alt="Portrait de Guillaume Couget"]');

        $client->request('GET', '/cv');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('img[src$="images/photos/guillaume-couget-miniature.webp"][width="400"][height="400"][alt="Portrait de Guillaume Couget"]');
    }

    /**
     * Vérifie que, sans photo envoyée depuis l'admin, le PDF design contient la miniature par défaut.
     */
    public function testPdfDesignShowsDefaultPhotoWithoutAdminPhoto(): void
    {
        self::bootKernel();
        $this->createContent();
        $html = static::getContainer()->get(CvPdfRenderer::class)->renderHtml('design');

        self::assertMatchesRegularExpression('#<img src="[^"]*/public/images/photos/guillaume-couget-miniature\.webp" class="photo"#', $html);
    }

    /**
     * Versions du PDF et nom de fichier attendu.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function providePdfVariants(): iterable
    {
        yield 'ats' => ['ats', 'CV-Guillaume-Couget-synthetique-ATS.pdf'];
        yield 'design' => ['design', 'CV-Guillaume-Couget-synthetique.pdf'];
    }

    /**
     * Versions du PDF.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function providePdfVariantNames(): iterable
    {
        yield 'ats' => ['ats'];
        yield 'design' => ['design'];
    }

    /**
     * Vérifie qu'une version de PDF inconnue répond 404.
     */
    public function testUnknownPdfVariantReturns404(): void
    {
        $client = self::createClient();
        $client->request('GET', '/cv/pdf/autre');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Crée un contenu de CV de test, avec des coordonnées privées et des éléments incomplets.
     */
    private function createContent(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $entityManager->persist((new Profile())
            ->setFullName('Jean Test')
            ->setJobTitle('Responsable Bureau d\'Études — Génie climatique & électrique')
            ->setPitch('Je conçois des installations climatiques.')
            ->setSummary("Premier paragraphe.\n\nSecond paragraphe.")
            ->setAvailability('Disponible immédiatement')
            ->setLocation('Lyon et Auvergne-Rhône-Alpes')
            ->setWorkModes('Sur site · hybride · télétravail')
            ->setPrivatePhone(self::PRIVATE_VALUES[0])
            ->setPrivateEmail(self::PRIVATE_VALUES[1])
            ->setPrivatePostalAddress(self::PRIVATE_VALUES[2])
            ->setPrivateSalaryExpectation(self::PRIVATE_VALUES[3]));

        $entityManager->persist((new KeyFigure())->setValue('Plus de 25 ans')->setLabel('d\'expérience en génie climatique')->setPosition(1));
        $entityManager->persist((new KeyFigure())->setValue('[nb]')->setLabel('personnes encadrées')->setPosition(2));

        $entityManager->persist((new SkillPillar())->setName('Conception')->setSummary('CAO/DAO, dimensionnement')->setIcon('design')->setPosition(1));

        $entityManager->persist((new Experience())
            ->setJobTitle('Responsable Bureau d\'Études')
            ->setCompanyName('ALPHA ESSAIS GROUP')
            ->setStartDate(new \DateTimeImmutable('2021-05-01'))
            ->setEndDate(new \DateTimeImmutable('2025-12-01'))
            ->setAchievements("Réduit les délais d'études de 20 %\nEncadrement de [nb] personnes au bureau")
            ->setPublicItems(['Réduit les délais d\'études de 20 %'])
            ->setPrivateNote('Fin de mission en décembre 2025.')
            ->setPosition(1));
        $entityManager->persist((new Experience())
            ->setJobTitle('Chargé de conception')
            ->setCompanyName('Vrai Nom Confidentiel')
            ->setIsCompanyAnonymized(true)
            ->setPublicCompanyLabel('PME industrielle, 80 salariés')
            ->setCompanyDetails('Filiale de Vrai Nom Confidentiel')
            ->setStartDate(new \DateTimeImmutable('2017-01-01'))
            ->setEndDate(new \DateTimeImmutable('2019-01-01'))
            ->setDatesInYears(true)
            ->setPosition(2));
        // « Expérience fondatrice » : deux postes successifs de la même entreprise
        foreach ([['Responsable de production', '2014-01-01', '2017-01-01', 3], ['Technicien de production', '2005-03-01', '2014-01-01', 4]] as [$title, $start, $end, $position]) {
            $entityManager->persist((new Experience())->setCareerSection(CareerSection::Foundation)->setJobTitle($title)
                ->setCompanyName('DELTA THERMIQUE / EPSILON LABO')->setLocation('Villeurbanne')->setMissions('Mission de '.$title)->setPublicItems(['Mission de '.$title])
                ->setStartDate(new \DateTimeImmutable($start))->setEndDate(new \DateTimeImmutable($end))->setDatesInYears(true)->setPosition($position));
        }
        $entityManager->persist((new Experience())->setCareerSection(CareerSection::Field)->setJobTitle('Technicien de maintenance')
            ->setCompanyName('ZETA ÉNERGIE')->setMissions('Maintenance préventive.')->setStartDate(new \DateTimeImmutable('2004-06-01'))
            ->setEndDate(new \DateTimeImmutable('2005-02-01'))->setPosition(5));
        foreach (CvEntrySection::cases() as $index => $section) {
            $entityManager->persist((new CvEntry())->setSection($section)->setTitle(CvEntrySection::Tools === $section ? null : 'Titre')
                ->setContent(CvEntrySection::SoftSkill === $section ? 'savoir écouter' : 'Contenu '.$section->value)->setPublicSummary('Résumé '.$section->value)->setPosition($index + 1));
        }
        $entityManager->persist((new Education())->setYear(2019)->setTitle('Titre Développeur Web')->setLocation('Lyon')->setInstitution('École Exemple')->setPosition(1));
        $entityManager->persist((new Language())->setName('Français')->setProficiency('langue maternelle')->setPosition(1));

        $entityManager->flush();
        // Comme une vraie requête : les relations sont relues depuis la base.
        $entityManager->clear();
    }
}
