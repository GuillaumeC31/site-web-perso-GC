<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261023151918 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot 5e : fluides frigorigènes, règles F-Gas et modèles de panneaux isolants (chambre froide v2)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE fgas_rule (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(10) NOT NULL, kind VARCHAR(20) NOT NULL, equipment VARCHAR(255) NOT NULL, installation_types JSON NOT NULL, min_capacity_kw DOUBLE PRECISION DEFAULT NULL, min_charge_tonnes DOUBLE PRECISION DEFAULT NULL, gwp_threshold INT NOT NULL, since DATE NOT NULL, exception LONGTEXT DEFAULT NULL, article VARCHAR(80) NOT NULL, annex_point VARCHAR(80) DEFAULT NULL, source LONGTEXT NOT NULL, verified TINYINT NOT NULL, position INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_6EF656D677153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE panel_model (id INT AUTO_INCREMENT NOT NULL, brand VARCHAR(80) NOT NULL, product_range VARCHAR(120) NOT NULL, insulation VARCHAR(40) NOT NULL, thickness INT NOT NULL, u_value DOUBLE PRECISION DEFAULT NULL, lambda DOUBLE PRECISION DEFAULT NULL, source_document VARCHAR(255) NOT NULL, source_date DATE NOT NULL, position INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE refrigerant (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(20) NOT NULL, name VARCHAR(60) NOT NULL, family VARCHAR(30) NOT NULL, gwp INT NOT NULL, gwp_report VARCHAR(3) NOT NULL, status VARCHAR(40) NOT NULL, safety_class VARCHAR(4) NOT NULL, lfl DOUBLE PRECISION DEFAULT NULL, glide DOUBLE PRECISION DEFAULT NULL, oil VARCHAR(60) NOT NULL, replacement VARCHAR(120) NOT NULL, application VARCHAR(120) NOT NULL, default_choice TINYINT NOT NULL, verified TINYINT NOT NULL, position INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_45330FCC77153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE fgas_rule');
        $this->addSql('DROP TABLE panel_model');
        $this->addSql('DROP TABLE refrigerant');
    }
}
