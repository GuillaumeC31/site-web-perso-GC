<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot 6-bis : structure du CV de référence.
 *
 * - rubriques « liste » du CV (cv_entry) ;
 * - expériences : rubrique du parcours (le bloc « Début de carrière » devient « Parcours terrain ») et
 *   note privée ;
 * - profil : modalités de travail, postes et structures visés ; formation : lieu.
 */
final class Version20261023232243 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot 6-bis : rubriques du CV, rubrique du parcours et note privée des expériences, profil et formation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cv_entry (id INT AUTO_INCREMENT NOT NULL, section VARCHAR(20) NOT NULL, title VARCHAR(120) DEFAULT NULL, content LONGTEXT NOT NULL, position INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE education ADD location VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE experience ADD career_section VARCHAR(20) DEFAULT \'professional\' NOT NULL, ADD private_note VARCHAR(255) DEFAULT NULL');
        // Conserve le classement existant avant de supprimer l'ancienne colonne
        $this->addSql('UPDATE experience SET career_section = \'field\' WHERE is_early_career = 1');
        $this->addSql('ALTER TABLE experience DROP is_early_career');
        $this->addSql('ALTER TABLE profile ADD work_modes VARCHAR(160) DEFAULT NULL, ADD target_roles VARCHAR(500) DEFAULT NULL, ADD target_companies VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cv_entry');
        $this->addSql('ALTER TABLE education DROP location');
        $this->addSql('ALTER TABLE experience ADD is_early_career TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('UPDATE experience SET is_early_career = 1 WHERE career_section = \'field\'');
        $this->addSql('ALTER TABLE experience DROP career_section, DROP private_note');
        $this->addSql('ALTER TABLE profile DROP work_modes, DROP target_roles, DROP target_companies');
    }
}
