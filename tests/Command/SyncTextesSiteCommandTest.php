<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SyncTextesSiteCommand;
use App\Entity\Certification;
use App\Entity\CvEntry;
use App\Entity\Experience;
use App\Entity\KeyFigure;
use App\Entity\Profile;
use App\Entity\Project;
use App\Entity\Skill;
use App\Entity\SkillPillar;
use App\Enum\CareerSection;
use App\Enum\CertificationType;
use App\Enum\ProjectType;
use App\Repository\ExperienceRepository;
use App\Repository\ProfileRepository;
use App\Service\Import\CvContentSynchronizer;
use App\Service\Import\TextesSiteParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Synchronisation du CV avec TEXTES_SITE.md (lot 6-bis) : aperçu sans écriture, application, relance
 * sans effet, données privées jamais importées (sauf la note privée d'une expérience, dans son champ).
 *
 * Liens :
 * - Couvre : SyncTextesSiteCommand (app:content:sync-textes), TextesSiteParser, CvContentSynchronizer
 * - Fixture : tests/Fixtures/textes_site_sample.md (personne fictive)
 */
final class SyncTextesSiteCommandTest extends KernelTestCase
{
    /** Chemin de la fixture. */
    private const FIXTURE = __DIR__.'/../Fixtures/textes_site_sample.md';

    /**
     * Vérifie l'aperçu : la liste des changements est donnée, rien n'est écrit en base.
     */
    public function testPreviewWritesNothing(): void
    {
        self::bootKernel();
        $this->createExistingContent();
        $report = sys_get_temp_dir().'/sync-textes-test.md';

        $display = $this->executeSync(['path' => self::FIXTURE, '--report' => $report]);

        self::assertStringContainsString('Rien n\'a été écrit', $display);
        self::assertStringContainsString('Ancien titre', (string) file_get_contents($report));
        self::assertStringContainsString('Technicien supérieur polyvalent', (string) file_get_contents($report));
        @unlink($report);
        $this->entityManager()->clear();
        self::assertSame('Ancien titre', static::getContainer()->get(ProfileRepository::class)->getOrCreate()->getJobTitle());
        self::assertSame(1, $this->entityManager()->getRepository(Skill::class)->count([]));
    }

    /**
     * Vérifie l'application : profil, chiffres, domaines, expériences dans leurs rubriques, rubriques du
     * CV, brouillon d'étude de cas renommé ; une seconde passe ne change plus rien.
     */
    public function testApplyAlignsContentAndIsIdempotent(): void
    {
        self::bootKernel();
        $this->createExistingContent();

        $this->executeSync(['path' => self::FIXTURE, '--apply' => true]);
        $this->entityManager()->clear();

        $profile = static::getContainer()->get(ProfileRepository::class)->getOrCreate();
        self::assertSame('Technicien supérieur polyvalent', $profile->getJobTitle());
        self::assertSame('Sur site · hybride', $profile->getWorkModes());
        self::assertSame('En recherche active, disponible en mars', $profile->getSearchStatus());
        self::assertSame('0600000000', $profile->getPrivatePhone(), 'Les coordonnées privées ne sont jamais touchées.');
        self::assertNull($profile->getContractTypes(), 'Un texte « à compléter » n\'est pas importé.');
        self::assertSame('XX à YY k€ brut annuel, selon le poste', $profile->getPrivateSalaryExpectation(), 'Prétentions 🔒 : dans leur champ privé.');
        self::assertTrue($profile->isShowSalaryInRecruiterArea());

        $clearances = $this->entityManager()->getRepository(Certification::class)->findAll();
        self::assertCount(1, $clearances);
        self::assertSame(
            [CertificationType::Clearance, 'Habilitations électriques B1V et BR (2018 – 2021, recyclage à prévoir)', 'Atelier Martin', '2018'],
            [$clearances[0]->getType(), $clearances[0]->getName(), $clearances[0]->getIssuer(), $clearances[0]->getObtainedOn()?->format('Y')],
        );

        $figures = $this->entityManager()->getRepository(KeyFigure::class)->findAll();
        self::assertCount(1, $figures, 'Le chiffre « [nb] » est écarté.');
        self::assertSame(0, $this->entityManager()->getRepository(Skill::class)->count([]));
        self::assertSame(['Essais', 'Terrain'], array_map(static fn (SkillPillar $p): string => $p->getName(), $this->entityManager()->getRepository(SkillPillar::class)->findBy([], ['position' => 'ASC'])));

        $experiences = static::getContainer()->get(ExperienceRepository::class)->findAllOrdered();
        self::assertSame(
            ['professional', 'professional', 'foundation', 'foundation', 'field'],
            array_map(static fn (Experience $e): string => $e->getCareerSection()->value, $experiences),
        );
        self::assertSame('ACME TEST (ATS)', $experiences[0]->getCompanyName());
        self::assertSame('Lyon', $experiences[0]->getLocation());
        self::assertSame('Essais et mise au point', $experiences[0]->getSummary());
        self::assertSame('Rupture conventionnelle en décembre 2025.', $experiences[0]->getPrivateNote());
        self::assertStringNotContainsString('Rupture', (string) $experiences[0]->getMissions());
        self::assertSame('', $experiences[1]->getCompanyName(), 'Activité sans employeur.');
        self::assertSame(['Chef d\'équipe', '2016-01-01', '2021-06-01'], [$experiences[2]->getJobTitle(), $experiences[2]->getStartDate()?->format('Y-m-d'), $experiences[2]->getEndDate()?->format('Y-m-d')]);
        self::assertSame(['Technicien', '2010-09-01', '2016-01-01'], [$experiences[3]->getJobTitle(), $experiences[3]->getStartDate()?->format('Y-m-d'), $experiences[3]->getEndDate()?->format('Y-m-d')]);
        self::assertSame(CareerSection::Field, $experiences[4]->getCareerSection());
        self::assertSame('Câblage et dépannage.', $experiences[4]->getMissions());

        $entries = $this->entityManager()->getRepository(CvEntry::class)->findAll();
        self::assertCount(5, $entries, 'Le conseil « 💡 » est écarté.');
        $draft = $this->entityManager()->getRepository(Project::class)->findOneBy([]);
        self::assertInstanceOf(Project::class, $draft);
        self::assertSame(['Banc d\'essai automatisé (ACME)', 'banc-d-essai-automatise-acme'], [$draft->getTitle(), $draft->getSlug()]);

        // Aucune donnée privée de la fixture n'a été importée
        $dump = json_encode([$profile->getPitch(), $profile->getSummary(), array_map(static fn (Experience $e): ?string => $e->getMissions(), $experiences)], \JSON_UNESCAPED_UNICODE);
        foreach (['06 12 34 56 78', 'jean.exemple@example.test', 'rue de l\'Exemple', '01/01/1980', 'XX k€', '38 à 42'] as $private) {
            self::assertStringNotContainsString($private, (string) $dump);
        }

        self::assertStringContainsString('déjà aligné', $this->executeSync(['path' => self::FIXTURE]));
    }

    /**
     * Vérifie l'alignement ligne à ligne des expériences : une seule expérience modifiée dans le fichier →
     * un seul changement « remplacé », identifiants gardés.
     */
    public function testChangedExperienceIsUpdatedInPlace(): void
    {
        self::bootKernel();
        $this->createExistingContent();
        $this->executeSync(['path' => self::FIXTURE, '--apply' => true]);
        $entityManager = $this->entityManager();
        $entityManager->clear();
        $experiences = static::getContainer()->get(ExperienceRepository::class)->findAllOrdered();
        $ids = array_map(static fn (Experience $e): ?int => $e->getId(), $experiences);

        $changed = sys_get_temp_dir().'/textes-site-modifie.md';
        file_put_contents($changed, str_replace(': câblage et dépannage.', ': câblage, dépannage et essais.', (string) file_get_contents(self::FIXTURE)));
        $report = sys_get_temp_dir().'/sync-textes-ligne.md';
        $this->executeSync(['path' => $changed, '--report' => $report]);
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($report)), static fn (string $l): bool => str_starts_with($l, '- **')));
        self::assertCount(1, $lines);
        self::assertStringContainsString('remplacé', $lines[0]);

        $this->executeSync(['path' => $changed, '--apply' => true]);
        @unlink($changed);
        @unlink($report);
        $entityManager->clear();
        $experiences = static::getContainer()->get(ExperienceRepository::class)->findAllOrdered();
        self::assertSame($ids, array_map(static fn (Experience $e): ?int => $e->getId(), $experiences));
        self::assertSame('Câblage, dépannage et essais.', $experiences[4]->getMissions());
    }

    /**
     * Vérifie qu'une expérience insérée dans le fichier (nombre d'expériences changé) donne un seul « ajouté » :
     * les autres expériences sont gardées (mêmes identifiants), dans l'ordre du fichier.
     */
    public function testInsertedExperienceIsAddedWithoutReplacingTheOthers(): void
    {
        self::bootKernel();
        $this->createExistingContent();
        $this->executeSync(['path' => self::FIXTURE, '--apply' => true]);
        $entityManager = $this->entityManager();
        $entityManager->clear();
        $ids = array_map(static fn (Experience $e): ?int => $e->getId(), static::getContainer()->get(ExperienceRepository::class)->findAllOrdered());

        $changed = sys_get_temp_dir().'/textes-site-insertion.md';
        file_put_contents($changed, str_replace(
            '- **Apprenti électricien — GARAGE DURAND · Bron**',
            "- **Commerce — emploi saisonnier** (06/2008 – 08/2008) : saison d'été.\n- **Apprenti électricien — GARAGE DURAND · Bron**",
            (string) file_get_contents(self::FIXTURE),
        ));
        $report = sys_get_temp_dir().'/sync-textes-insertion.md';
        $this->executeSync(['path' => $changed, '--report' => $report]);
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($report)), static fn (string $l): bool => str_starts_with($l, '- **')));
        self::assertCount(1, $lines);
        self::assertStringContainsString('Commerce — emploi saisonnier (06/2008 – 08/2008)** — ajouté', $lines[0]);

        $this->executeSync(['path' => $changed, '--apply' => true]);
        @unlink($changed);
        @unlink($report);
        $entityManager->clear();
        $experiences = static::getContainer()->get(ExperienceRepository::class)->findAllOrdered();
        self::assertCount(6, $experiences);
        self::assertSame('Restauration', $experiences[4]->getJobTitle());
        self::assertSame($ids, array_values(array_filter(array_map(static fn (Experience $e): ?int => $e->getId(), $experiences), static fn (?int $id): bool => \in_array($id, $ids, true))));
        self::assertSame([1, 2, 3, 4, 5, 6], array_map(static fn (Experience $e): int => $e->getPosition(), $experiences));
    }

    /**
     * Vérifie l'icône des piliers (v2.0.1) : « Projets » reçoit l'icône « project », et un pilier existant au bon
     * nom mais à l'ancienne icône (« generic », comme en v2.0.0) est réaligné par une nouvelle synchronisation.
     */
    public function testPillarIconIsSetAndRealigned(): void
    {
        self::bootKernel();
        $this->createExistingContent();
        $changed = sys_get_temp_dir().'/textes-site-projets.md';
        file_put_contents($changed, str_replace('| **Terrain** | Mise en service |', '| **Projets** | Pilotage et coordination |', (string) file_get_contents(self::FIXTURE)));

        $this->executeSync(['path' => $changed, '--apply' => true]);
        $entityManager = $this->entityManager();
        $entityManager->clear();
        $pillar = $entityManager->getRepository(SkillPillar::class)->findOneBy(['name' => 'Projets']);
        self::assertInstanceOf(SkillPillar::class, $pillar);
        self::assertSame('project', $pillar->getIcon());
        self::assertContains('project', SkillPillar::ICONS);

        // État de la v2.0.0 : même nom et même résumé, ancienne icône
        $pillar->setIcon('generic');
        $entityManager->flush();
        $report = sys_get_temp_dir().'/sync-textes-projets.md';
        $this->executeSync(['path' => $changed, '--report' => $report]);
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($report)), static fn (string $l): bool => str_starts_with($l, '- **')));
        self::assertCount(1, $lines, 'Seule l\'icône du pilier diffère.');
        self::assertStringContainsString('Domaine 2', $lines[0]);

        $this->executeSync(['path' => $changed, '--apply' => true]);
        $entityManager->clear();
        self::assertSame('project', $entityManager->getRepository(SkillPillar::class)->findOneBy(['name' => 'Projets'])?->getIcon());
        self::assertStringContainsString('déjà aligné', $this->executeSync(['path' => $changed]));
        @unlink($changed);
        @unlink($report);

        // Le partiel dessine bien une icône propre à « project » (pas l'horloge générique)
        $twig = static::getContainer()->get('twig');
        $project = $twig->render('_partials/_pillar_icon.html.twig', ['icon' => 'project']);
        self::assertStringContainsString('M9 3h6v3H9z', $project);
        self::assertNotSame($twig->render('_partials/_pillar_icon.html.twig', ['icon' => 'generic']), $project);
    }

    /**
     * Vérifie qu'en production la commande exige --production, puis fonctionne (ici en aperçu).
     */
    public function testProductionRequiresExplicitOption(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $command = new SyncTextesSiteCommand($container->get(TextesSiteParser::class), $container->get(CvContentSynchronizer::class), 'prod');
        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute(['path' => self::FIXTURE]));
        self::assertStringContainsString('--production', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute(['path' => self::FIXTURE, '--production' => true, '--dry-run' => true]));
        self::assertStringContainsString('Rien n\'a été écrit', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    /**
     * Vérifie le refus d'un fichier absent.
     */
    public function testMissingFileFails(): void
    {
        self::bootKernel();
        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('app:content:sync-textes'));

        self::assertSame(Command::FAILURE, $tester->execute(['path' => 'absent.md']));
        self::assertStringContainsString('introuvable', $tester->getDisplay());
    }

    /**
     * Crée un contenu existant : profil avec coordonnées privées, une compétence, un brouillon d'étude de cas.
     */
    private function createExistingContent(): void
    {
        $entityManager = $this->entityManager();
        $entityManager->persist((new Profile())->setFullName('Jean Exemple')->setJobTitle('Ancien titre')->setPrivatePhone('0600000000'));
        $pillar = (new SkillPillar())->setName('Ancien pilier')->setIcon('generic')->setPosition(1);
        $entityManager->persist($pillar);
        $entityManager->persist((new Skill())->setName('Ancienne compétence')->setPillar($pillar)->setPosition(1));
        $entityManager->persist((new Project())->setType(ProjectType::CaseStudy)->setTitle('Ancien cas')->setSlug('ancien-cas'));
        $entityManager->flush();
    }

    /**
     * Lance la commande et renvoie sa sortie (espaces normalisés).
     *
     * @param array<string, mixed> $input arguments et options
     */
    private function executeSync(array $input): string
    {
        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('app:content:sync-textes'));
        self::assertSame(Command::SUCCESS, $tester->execute($input));

        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    /**
     * Gestionnaire Doctrine du conteneur de test.
     */
    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
