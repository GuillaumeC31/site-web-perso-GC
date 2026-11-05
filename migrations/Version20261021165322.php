<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261021165322 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot 3 : dates d\'expérience en années seules, résumé, étiquette et icône des piliers de compétences';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE experience ADD dates_in_years TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE skill_pillar ADD summary LONGTEXT DEFAULT NULL, ADD badge VARCHAR(40) DEFAULT NULL, ADD icon VARCHAR(20) DEFAULT \'generic\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE experience DROP dates_in_years');
        $this->addSql('ALTER TABLE skill_pillar DROP summary, DROP badge, DROP icon');
    }
}
