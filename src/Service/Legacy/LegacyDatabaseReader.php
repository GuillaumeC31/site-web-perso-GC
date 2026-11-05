<?php

declare(strict_types=1);

namespace App\Service\Legacy;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

/**
 * Lit l'ancienne base (site v1, guillaume_c) en LECTURE SEULE : seulement des requêtes SELECT sur les
 * tables reprises (projets et catégories, réseaux sociaux, données de la calculatrice).
 *
 * La base est une copie locale de l'export de production,
 * désignée par LEGACY_DATABASE_URL dans .env.local ; elle n'est jamais modifiée.
 *
 * Tables jamais lues : user, recruiter, recruiter_request, chatbot_directive, changelog_entry, setting,
 * experience, skill (le contenu du CV vient de TEXTES_SITE.md), maintenance_subscriber, messenger_messages.
 *
 * Liens :
 * - Utilisé par : LegacyMigrator, LegacyMigrateCommand (app:legacy:migrate)
 * - Testé par : tests/Service/Legacy/LegacyMigratorTest (base SQLite en mémoire)
 */
final class LegacyDatabaseReader
{
    /**
     * Ouvre la connexion à l'ancienne base.
     *
     * Liens :
     * - Appelée par : LegacyMigrateCommand ; tests (connexion SQLite fournie)
     *
     * @param Connection $connection connexion à la copie locale de l'ancienne base
     */
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Crée un lecteur à partir d'une adresse de base (LEGACY_DATABASE_URL).
     *
     * Liens :
     * - Appelée par : LegacyMigrateCommand::execute()
     *
     * @throws \InvalidArgumentException si l'adresse est vide
     */
    public static function fromUrl(string $url): self
    {
        if ('' === trim($url)) {
            throw new \InvalidArgumentException('LEGACY_DATABASE_URL est vide.');
        }
        $parameters = (new DsnParser(['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql']))->parse($url);

        return new self(DriverManager::getConnection($parameters + ['charset' => 'utf8mb4']));
    }

    /**
     * Renvoie les projets de l'ancien site avec leurs catégories.
     *
     * Liens :
     * - Appelée par : LegacyMigrator::migrateProjects()
     *
     * @return list<array{id: int, title: string, slug: string, excerpt: ?string, content: ?string, cover_image: ?string, is_published: bool, repository_url: ?string, demo_url: ?string, categories: list<array{name: string, slug: string}>}>
     */
    public function getProjects(): array
    {
        $categories = [];
        foreach ($this->connection->fetchAllAssociative('SELECT pc.project_id, c.name, c.slug FROM project_category pc INNER JOIN category c ON c.id = pc.category_id ORDER BY c.name') as $row) {
            $categories[(int) $row['project_id']][] = ['name' => (string) $row['name'], 'slug' => (string) $row['slug']];
        }

        $projects = [];
        foreach ($this->connection->fetchAllAssociative('SELECT id, title, slug, excerpt, content, cover_image, is_published, repository_url, demo_url FROM project ORDER BY id') as $row) {
            $projects[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'slug' => (string) $row['slug'],
                'excerpt' => self::nullableString($row['excerpt']),
                'content' => self::nullableString($row['content']),
                'cover_image' => self::nullableString($row['cover_image']),
                'is_published' => (bool) $row['is_published'],
                'repository_url' => self::nullableString($row['repository_url']),
                'demo_url' => self::nullableString($row['demo_url']),
                'categories' => $categories[(int) $row['id']] ?? [],
            ];
        }

        return $projects;
    }

    /**
     * Renvoie les réseaux sociaux actifs de l'ancien site.
     *
     * Liens :
     * - Appelée par : LegacyMigrator::migrateSocialNetworks()
     *
     * @return list<array{name: string, url: string}>
     */
    public function getSocialNetworks(): array
    {
        return array_map(static fn (array $row): array => ['name' => (string) $row['name'], 'url' => (string) $row['url']],
            $this->connection->fetchAllAssociative('SELECT name, url FROM social_network WHERE is_active = 1 ORDER BY position'));
    }

    /**
     * Renvoie les fluides de l'outil « Chauffe d'un fluide ».
     *
     * @return list<array{name: string, state: string, density: float, specific_heat: float}>
     */
    public function getFluids(): array
    {
        return array_map(static fn (array $row): array => [
            'name' => (string) $row['name'], 'state' => (string) $row['state'],
            'density' => (float) $row['density'], 'specific_heat' => (float) $row['specific_heat_capacity'],
        ], $this->connection->fetchAllAssociative('SELECT name, state, density, specific_heat_capacity FROM fluid ORDER BY id'));
    }

    /**
     * Renvoie les imprimantes 3D de l'outil « Coût d'une impression 3D ».
     *
     * @return list<array{brand: string, model: string, power: int}>
     */
    public function getPrinters(): array
    {
        return array_map(static fn (array $row): array => [
            'brand' => (string) $row['brand'], 'model' => (string) $row['model'], 'power' => (int) $row['power_consumption'],
        ], $this->connection->fetchAllAssociative('SELECT brand, model, power_consumption FROM printer3d ORDER BY id'));
    }

    /**
     * Renvoie les offres d'électricité de l'outil « Coût d'une impression 3D ».
     *
     * @return list<array{name: string, plan: string, price_kwh: float}>
     */
    public function getEnergyProviders(): array
    {
        return array_map(static fn (array $row): array => [
            'name' => (string) $row['name'], 'plan' => (string) $row['plan_name'], 'price_kwh' => (float) $row['price_kwh'],
        ], $this->connection->fetchAllAssociative('SELECT name, plan_name, price_kwh FROM energy_provider ORDER BY id'));
    }

    /**
     * Renvoie les directives du chatbot de l'ancien site (table chatbot_directive : type, contenu, active).
     *
     * Liens :
     * - Appelée par : App\Service\Ai\AiDirectiveImporter (page « Cerveau de l'IA »)
     *
     * @return list<array{id: int, type: string, content: string, active: bool}>
     */
    public function getChatbotDirectives(): array
    {
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'type' => (string) $row['type'], 'content' => trim((string) $row['content']), 'active' => (bool) $row['is_active'],
        ], $this->connection->fetchAllAssociative('SELECT id, type, content, is_active FROM chatbot_directive ORDER BY id'));
    }

    /**
     * Renvoie le nombre de lignes de chaque table de l'ancienne base (inventaire, sans lire les données).
     *
     * Liens :
     * - Appelée par : LegacyMigrateCommand (inventaire affiché avant l'aperçu)
     *
     * @return array<string, int> table => nombre de lignes
     */
    public function countRows(): array
    {
        $counts = [];
        foreach ($this->connection->createSchemaManager()->listTableNames() as $table) {
            $counts[$table] = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.$this->connection->quoteSingleIdentifier($table));
        }
        ksort($counts);

        return $counts;
    }

    /**
     * Convertit une valeur en texte, ou null si elle est vide.
     */
    private static function nullableString(mixed $value): ?string
    {
        return null === $value || '' === trim((string) $value) ? null : (string) $value;
    }
}
