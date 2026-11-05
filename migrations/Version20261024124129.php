<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot 8 : paramètres de référencement de l'accueil, profil LinkedIn (rempli avec l'adresse donnée par
 * Guillaume) et mode maintenance.
 */
final class Version20261024124129 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot 8 : title et description de l\'accueil, LinkedIn, mode maintenance (site_settings)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_settings ADD seo_title VARCHAR(70) DEFAULT NULL, ADD seo_description VARCHAR(170) DEFAULT NULL, ADD linkedin_url VARCHAR(255) DEFAULT NULL, ADD maintenance_enabled TINYINT DEFAULT 0 NOT NULL, ADD maintenance_message VARCHAR(300) DEFAULT NULL');
        $this->addSql("UPDATE site_settings SET linkedin_url = 'https://www.linkedin.com/in/exemple' WHERE linkedin_url IS NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_settings DROP seo_title, DROP seo_description, DROP linkedin_url, DROP maintenance_enabled, DROP maintenance_message');
    }
}
