<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261021225102 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot 4 : projets (études de cas et Labo), catégories et galeries d\'images';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE project (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(20) NOT NULL, title VARCHAR(160) NOT NULL, slug VARCHAR(180) NOT NULL, summary LONGTEXT DEFAULT NULL, client_label VARCHAR(160) DEFAULT NULL, context LONGTEXT DEFAULT NULL, problem LONGTEXT DEFAULT NULL, solution LONGTEXT DEFAULT NULL, result LONGTEXT DEFAULT NULL, competences LONGTEXT DEFAULT NULL, content LONGTEXT DEFAULT NULL, technologies LONGTEXT DEFAULT NULL, project_date DATE DEFAULT NULL, cover_image VARCHAR(64) DEFAULT NULL, cover_alt VARCHAR(160) DEFAULT NULL, repository_url VARCHAR(255) DEFAULT NULL, demo_url VARCHAR(255) DEFAULT NULL, is_published TINYINT DEFAULT 0 NOT NULL, visibility VARCHAR(20) DEFAULT \'public\' NOT NULL, is_featured TINYINT DEFAULT 0 NOT NULL, position INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_2FB3D0EE989D9B62 (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE project_project_category (project_id INT NOT NULL, project_category_id INT NOT NULL, INDEX IDX_86875173166D1F9C (project_id), INDEX IDX_86875173DA896A19 (project_category_id), PRIMARY KEY (project_id, project_category_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE project_category (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(80) NOT NULL, slug VARCHAR(90) NOT NULL, position INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_3B02921A989D9B62 (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE project_image (id INT AUTO_INCREMENT NOT NULL, filename VARCHAR(64) NOT NULL, width INT NOT NULL, height INT NOT NULL, alt VARCHAR(160) DEFAULT NULL, position INT DEFAULT 0 NOT NULL, project_id INT NOT NULL, INDEX IDX_D6680DC1166D1F9C (project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE project_project_category ADD CONSTRAINT FK_86875173166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_project_category ADD CONSTRAINT FK_86875173DA896A19 FOREIGN KEY (project_category_id) REFERENCES project_category (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_image ADD CONSTRAINT FK_D6680DC1166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE project_project_category DROP FOREIGN KEY FK_86875173166D1F9C');
        $this->addSql('ALTER TABLE project_project_category DROP FOREIGN KEY FK_86875173DA896A19');
        $this->addSql('ALTER TABLE project_image DROP FOREIGN KEY FK_D6680DC1166D1F9C');
        $this->addSql('DROP TABLE project');
        $this->addSql('DROP TABLE project_project_category');
        $this->addSql('DROP TABLE project_category');
        $this->addSql('DROP TABLE project_image');
    }
}
