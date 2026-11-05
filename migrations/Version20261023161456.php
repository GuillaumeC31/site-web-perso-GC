<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261023161456 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enceinte climatique v2 : profils de consigne (ClimaticProfile)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE climatic_profile (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(40) NOT NULL, sector VARCHAR(60) NOT NULL, name VARCHAR(80) NOT NULL, temperature DOUBLE PRECISION NOT NULL, humidity DOUBLE PRECISION DEFAULT NULL, temperature_tolerance DOUBLE PRECISION NOT NULL, humidity_tolerance DOUBLE PRECISION DEFAULT NULL, source VARCHAR(255) NOT NULL, default_choice TINYINT NOT NULL, verified TINYINT NOT NULL, position INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_B58053B877153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE climatic_profile');
    }
}
