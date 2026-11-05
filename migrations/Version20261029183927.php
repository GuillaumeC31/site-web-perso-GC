<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Réseaux sociaux gérés dans l'admin (décision du 2026-10-29) : table social_link (liste de liens, ordre, visibilité,
 * endroits d'affichage) à la place du champ unique site_settings.linkedin_url. L'adresse LinkedIn actuelle est reprise,
 * visible partout (pied de page, maintenance, espace recruteur, données structurées).
 *
 * Liens :
 * - Entités : App\Entity\SocialLink, App\Entity\SiteSettings
 */
final class Version20261029183927 extends AbstractMigration
{
    /**
     * Décrit la migration (affichée par doctrine:migrations:status).
     */
    public function getDescription(): string
    {
        return 'Réseaux sociaux : table social_link, reprise de LinkedIn, suppression de site_settings.linkedin_url';
    }

    /**
     * Crée la table, reprend LinkedIn (visible partout), retire l'ancien champ.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE social_link (id INT AUTO_INCREMENT NOT NULL, network VARCHAR(20) NOT NULL, label VARCHAR(60) DEFAULT NULL, url VARCHAR(255) NOT NULL, visible TINYINT DEFAULT 0 NOT NULL, show_in_footer TINYINT DEFAULT 1 NOT NULL, show_on_maintenance TINYINT DEFAULT 1 NOT NULL, show_in_recruiter_area TINYINT DEFAULT 1 NOT NULL, show_in_structured_data TINYINT DEFAULT 1 NOT NULL, position INT DEFAULT 0 NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql("INSERT INTO social_link (network, label, url, visible, show_in_footer, show_on_maintenance, show_in_recruiter_area, show_in_structured_data, position) SELECT 'linkedin', NULL, linkedin_url, 1, 1, 1, 1, 1, 1 FROM site_settings WHERE linkedin_url IS NOT NULL AND linkedin_url <> '' LIMIT 1");
        $this->addSql('ALTER TABLE site_settings DROP linkedin_url');
    }

    /**
     * Remet le champ LinkedIn (premier lien LinkedIn) et supprime la table.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_settings ADD linkedin_url VARCHAR(255) DEFAULT NULL');
        $this->addSql("UPDATE site_settings SET linkedin_url = (SELECT url FROM social_link WHERE network = 'linkedin' ORDER BY position LIMIT 1)");
        $this->addSql('DROP TABLE social_link');
    }
}
