<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261022000615 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot 5a : réglages des outils de la calculatrice et données de référence (fluides, imprimantes 3D, offres d\'électricité)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE calculator_tool_setting (id INT AUTO_INCREMENT NOT NULL, tool_key VARCHAR(120) NOT NULL, enabled TINYINT DEFAULT 1 NOT NULL, help_text LONGTEXT DEFAULT NULL, reference VARCHAR(255) DEFAULT NULL, reference_verified TINYINT DEFAULT 0 NOT NULL, position INT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_3F69B4573193683 (tool_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE energy_provider (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(80) NOT NULL, plan_name VARCHAR(120) NOT NULL, price_kwh DOUBLE PRECISION NOT NULL, position INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_energy_provider_name_plan (name, plan_name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE fluid (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, state VARCHAR(20) NOT NULL, density DOUBLE PRECISION NOT NULL, specific_heat_capacity DOUBLE PRECISION NOT NULL, position INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_D7AE25F5E237E06 (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE printer3d (id INT AUTO_INCREMENT NOT NULL, brand VARCHAR(80) NOT NULL, model VARCHAR(120) NOT NULL, power_consumption INT NOT NULL, position INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_printer3d_brand_model (brand, model), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE calculator_tool_setting');
        $this->addSql('DROP TABLE energy_provider');
        $this->addSql('DROP TABLE fluid');
        $this->addSql('DROP TABLE printer3d');
    }
}
