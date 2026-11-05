<?php

declare(strict_types=1);

namespace App\Tests\Service\Legacy;

use App\Entity\EnergyProvider;
use App\Entity\Fluid;
use App\Entity\Project;
use App\Entity\ProjectCategory;
use App\Entity\SocialLink;
use App\Enum\FluidState;
use App\Enum\ProjectType;
use App\Enum\SocialNetwork;
use App\Repository\SocialLinkRepository;
use App\Service\Admin\ProjectImageStorage;
use App\Service\Legacy\LegacyDatabaseReader;
use App\Service\Legacy\LegacyMigrator;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Migration de l'ancienne base : aperçu sans écriture, projets en brouillon avec catégories et images
 * converties, LinkedIn seul, données de calcul ajoutées ou mises à jour, relance sans effet ; aucune table
 * écartée n'est lue (recruteurs, comptes…). Ancienne base simulée en SQLite, en mémoire.
 *
 * Liens :
 * - Couvre : LegacyDatabaseReader, LegacyMigrator
 */
final class LegacyMigratorTest extends KernelTestCase
{
    /** Dossier temporaire « public/uploads » de l'ancien site. */
    private string $uploadsDir;

    protected function setUp(): void
    {
        $this->uploadsDir = sys_get_temp_dir().'/legacy-uploads-'.bin2hex(random_bytes(4));
        mkdir($this->uploadsDir.'/projects', 0777, true);
        // Image PNG de 40 × 30 px pour l'image du contenu
        $image = imagecreatetruecolor(40, 30);
        imagepng($image, $this->uploadsDir.'/projects/schema-1.png');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->uploadsDir.'/projects/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->uploadsDir.'/projects');
        rmdir($this->uploadsDir);
        parent::tearDown();
    }

    /**
     * Vérifie l'aperçu (rien d'écrit), l'application puis la relance sans effet.
     */
    public function testPreviewThenApplyThenNothingLeft(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist((new Fluid())->setName('Eau liquide')->setState(FluidState::Liquid)->setDensity(1000)->setSpecificHeatCapacity(4185)->setPosition(1));
        $entityManager->persist((new EnergyProvider())->setName('EDF')->setPlanName('Tarif Bleu (Base)')->setPriceKwh(0.19)->setPosition(1));
        // LinkedIn déjà présent dans la v2 (reprise par la migration du 2026-10-29)
        $entityManager->persist((new SocialLink())->setNetwork(SocialNetwork::LinkedIn)->setUrl('https://www.linkedin.com/in/exemple')->setVisible(true)->setPosition(1));
        $entityManager->flush();
        $migrator = self::getContainer()->get(LegacyMigrator::class);
        $reader = new LegacyDatabaseReader($this->createLegacyDatabase());

        $preview = $migrator->migrate($reader, $this->uploadsDir, false);
        self::assertSame(
            [['Projets', 'Simulateur d\'aube (ESP32)', 'ajouté (brouillon)'], ['Calculatrice : fluides', 'Glycérine', 'ajouté'], ['Calculatrice : imprimantes 3D', 'Bambu Lab A1', 'ajouté'], ['Calculatrice : offres d\'électricité', 'EDF — Tarif Bleu (Base)', 'remplacé']],
            array_map(static fn (array $c): array => [$c['area'], $c['item'], $c['action']], $preview['changes']),
        );
        self::assertContains('Réseau « GitHub » non repris (seul LinkedIn est affiché).', $preview['notes']);
        self::assertSame(0, $entityManager->getRepository(Project::class)->count([]), 'L\'aperçu n\'écrit rien.');

        $migrator->migrate($reader, $this->uploadsDir, true);
        $entityManager->clear();
        $project = $entityManager->getRepository(Project::class)->findOneBy(['slug' => 'simulateur-aube-esp32']);
        self::assertInstanceOf(Project::class, $project);
        self::assertSame(ProjectType::Lab, $project->getType());
        self::assertFalse($project->isPublished(), 'Toujours en brouillon, à relire avant publication.');
        // Catégories comparées triées : la relation ManyToMany n'a pas d'ordre garanti (échec aléatoire en CI)
        $slugs = array_map(static fn (ProjectCategory $c): string => $c->getSlug(), $project->getCategories()->toArray());
        sort($slugs);
        self::assertSame(['developpement', 'electronique'], $slugs);
        self::assertCount(1, $project->getImages());
        self::assertStringContainsString('/uploads/projects/'.$project->getImages()->first()->getFilename(), (string) $project->getContent());
        self::assertStringNotContainsString('schema-1.png', (string) $project->getContent());
        self::assertFileExists($this->uploadsDir.'/projects/schema-1.png', 'Le fichier de l\'ancien site n\'est ni déplacé ni supprimé.');
        self::assertSame(0.194, $entityManager->getRepository(EnergyProvider::class)->findOneBy(['name' => 'EDF'])?->getPriceKwh());
        // LinkedIn repris dans Communication › Réseaux sociaux, visible partout
        $linkedin = self::getContainer()->get(SocialLinkRepository::class)->findFirstLinkedIn();
        self::assertSame('https://www.linkedin.com/in/exemple', $linkedin?->getUrl());
        self::assertTrue($linkedin->isShownAt(SocialLink::PLACE_FOOTER) && $linkedin->isShownAt(SocialLink::PLACE_STRUCTURED_DATA));

        self::assertSame([], $migrator->migrate($reader, $this->uploadsDir, false)['changes'], 'Relance : plus rien à reprendre.');

        // Image convertie écrite dans public/uploads/projects : supprimée (fichier et variante)
        self::getContainer()->get(ProjectImageStorage::class)->remove($project->getImages()->first()->getFilename());
    }

    /**
     * Crée l'ancienne base : tables reprises, et une table de recruteurs qui ne doit jamais être lue.
     */
    private function createLegacyDatabase(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            'CREATE TABLE project (id INTEGER, title TEXT, slug TEXT, excerpt TEXT, content TEXT, cover_image TEXT, is_published INTEGER, repository_url TEXT, demo_url TEXT)',
            'CREATE TABLE category (id INTEGER, name TEXT, slug TEXT)',
            'CREATE TABLE project_category (project_id INTEGER, category_id INTEGER)',
            'CREATE TABLE social_network (id INTEGER, name TEXT, url TEXT, is_active INTEGER, position INTEGER)',
            'CREATE TABLE fluid (id INTEGER, name TEXT, state TEXT, density REAL, specific_heat_capacity REAL)',
            'CREATE TABLE printer3d (id INTEGER, brand TEXT, model TEXT, power_consumption INTEGER)',
            'CREATE TABLE energy_provider (id INTEGER, name TEXT, plan_name TEXT, price_kwh REAL)',
            'CREATE TABLE recruiter (id INTEGER, email TEXT)',
        ] as $sql) {
            $connection->executeStatement($sql);
        }
        $connection->insert('project', ['id' => 4, 'title' => 'Simulateur d\'aube (ESP32)', 'slug' => 'simulateur-aube-esp32', 'excerpt' => 'Un simulateur.', 'content' => '<p>Schéma :</p><img src="/uploads/projects/schema-1.png" alt="">', 'cover_image' => null, 'is_published' => 1, 'repository_url' => 'http://exemple.test/depot', 'demo_url' => null]);
        $connection->insert('category', ['id' => 1, 'name' => 'Electronique', 'slug' => 'electronique']);
        $connection->insert('category', ['id' => 3, 'name' => 'Développement', 'slug' => 'developpement']);
        $connection->insert('project_category', ['project_id' => 4, 'category_id' => 1]);
        $connection->insert('project_category', ['project_id' => 4, 'category_id' => 3]);
        $connection->insert('social_network', ['id' => 1, 'name' => 'LinkedIn', 'url' => 'https://www.linkedin.com/in/exemple/', 'is_active' => 1, 'position' => 10]);
        $connection->insert('social_network', ['id' => 2, 'name' => 'GitHub', 'url' => 'https://github.com/exemple', 'is_active' => 1, 'position' => 20]);
        $connection->insert('fluid', ['id' => 1, 'name' => 'Eau liquide', 'state' => 'liquide', 'density' => 1000, 'specific_heat_capacity' => 4185]);
        $connection->insert('fluid', ['id' => 2, 'name' => 'Glycérine', 'state' => 'liquide', 'density' => 1261, 'specific_heat_capacity' => 2430]);
        $connection->insert('printer3d', ['id' => 1, 'brand' => 'Bambu Lab', 'model' => 'A1', 'power_consumption' => 200]);
        $connection->insert('energy_provider', ['id' => 1, 'name' => 'EDF', 'plan_name' => 'Tarif Bleu (Base)', 'price_kwh' => 0.194]);
        $connection->insert('recruiter', ['id' => 1, 'email' => 'rh@exemple.test']);

        return $connection;
    }
}
