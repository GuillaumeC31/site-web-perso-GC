<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261023215142 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot 6 : espace recruteur (recruteurs, signaux), messages de contact, paramètres du site, prétentions affichables';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE contact_message (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, email VARCHAR(180) NOT NULL, company VARCHAR(120) DEFAULT NULL, subject VARCHAR(20) NOT NULL, message LONGTEXT NOT NULL, status VARCHAR(20) NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, consent_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE recruiter (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, full_name VARCHAR(120) NOT NULL, company VARCHAR(120) NOT NULL, message LONGTEXT DEFAULT NULL, status VARCHAR(20) NOT NULL, score INT NOT NULL, scoring_details JSON NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, consent_at DATETIME DEFAULT NULL, requested_at DATETIME NOT NULL, decided_at DATETIME DEFAULT NULL, access_expires_at DATETIME DEFAULT NULL, last_login_at DATETIME DEFAULT NULL, reminder_sent_at DATETIME DEFAULT NULL, extension_requested_at DATETIME DEFAULT NULL, last_activity_at DATETIME NOT NULL, created_manually TINYINT NOT NULL, UNIQUE INDEX UNIQ_DE8633D8E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE recruiter_event (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(20) NOT NULL, occurred_at DATETIME NOT NULL, notified_at DATETIME DEFAULT NULL, recruiter_id INT NOT NULL, INDEX IDX_E65F4BF24547430C (notified_at), INDEX IDX_E65F4BF2156BE243 (recruiter_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE site_settings (id INT AUTO_INCREMENT NOT NULL, response_delay VARCHAR(80) NOT NULL, notification_channels JSON NOT NULL, auto_approval_enabled TINYINT NOT NULL, auto_approval_threshold INT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE recruiter_event ADD CONSTRAINT FK_E65F4BF2156BE243 FOREIGN KEY (recruiter_id) REFERENCES recruiter (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE profile ADD show_salary_in_recruiter_area TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE recruiter_event DROP FOREIGN KEY FK_E65F4BF2156BE243');
        $this->addSql('DROP TABLE contact_message');
        $this->addSql('DROP TABLE recruiter');
        $this->addSql('DROP TABLE recruiter_event');
        $this->addSql('DROP TABLE site_settings');
        $this->addSql('ALTER TABLE profile DROP show_salary_in_recruiter_area');
    }
}
