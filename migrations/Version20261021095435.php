<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261021095435 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot 2 : comptes admin, clés WebAuthn, journal des connexions et contenu du CV (profil, expériences, compétences, formations, certifications, langues, chiffres clés)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE app_user (id INT AUTO_INCREMENT NOT NULL, username VARCHAR(64) NOT NULL, notification_email VARCHAR(180) DEFAULT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, webauthn_user_handle VARCHAR(64) NOT NULL, security_version INT DEFAULT 1 NOT NULL, enrollment_token_hash VARCHAR(64) DEFAULT NULL, enrollment_token_expires_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_88BDF3E9F85E0677 (username), UNIQUE INDEX UNIQ_88BDF3E9D13D977A (webauthn_user_handle), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE certification (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, type VARCHAR(20) NOT NULL, issuer VARCHAR(255) DEFAULT NULL, obtained_on DATE DEFAULT NULL, expires_on DATE DEFAULT NULL, position INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE cv_language (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(80) NOT NULL, proficiency VARCHAR(160) NOT NULL, position INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE education (id INT AUTO_INCREMENT NOT NULL, year SMALLINT NOT NULL, title VARCHAR(255) NOT NULL, institution VARCHAR(255) DEFAULT NULL, position INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE experience (id INT AUTO_INCREMENT NOT NULL, job_title VARCHAR(160) NOT NULL, company_name VARCHAR(160) NOT NULL, is_company_anonymized TINYINT DEFAULT 0 NOT NULL, public_company_label VARCHAR(160) DEFAULT NULL, company_details VARCHAR(255) DEFAULT NULL, sector VARCHAR(120) DEFAULT NULL, company_size VARCHAR(80) DEFAULT NULL, location VARCHAR(120) DEFAULT NULL, start_date DATE NOT NULL, end_date DATE DEFAULT NULL, summary LONGTEXT DEFAULT NULL, missions LONGTEXT DEFAULT NULL, achievements LONGTEXT DEFAULT NULL, tools LONGTEXT DEFAULT NULL, is_early_career TINYINT DEFAULT 0 NOT NULL, is_published TINYINT DEFAULT 1 NOT NULL, position INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE experience_skill (experience_id INT NOT NULL, skill_id INT NOT NULL, INDEX IDX_3D6F986146E90E27 (experience_id), INDEX IDX_3D6F98615585C142 (skill_id), PRIMARY KEY (experience_id, skill_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE key_figure (id INT AUTO_INCREMENT NOT NULL, value VARCHAR(40) NOT NULL, label VARCHAR(160) NOT NULL, position INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE profile (id INT AUTO_INCREMENT NOT NULL, full_name VARCHAR(120) NOT NULL, job_title VARCHAR(160) NOT NULL, tagline VARCHAR(255) DEFAULT NULL, pitch LONGTEXT DEFAULT NULL, summary LONGTEXT DEFAULT NULL, search_status VARCHAR(160) DEFAULT NULL, location VARCHAR(120) DEFAULT NULL, mobility VARCHAR(160) DEFAULT NULL, contract_types VARCHAR(160) DEFAULT NULL, availability VARCHAR(160) DEFAULT NULL, private_phone VARCHAR(30) DEFAULT NULL, private_email VARCHAR(180) DEFAULT NULL, private_postal_address LONGTEXT DEFAULT NULL, private_salary_expectation VARCHAR(160) DEFAULT NULL, photo_filename VARCHAR(100) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE security_event (id INT AUTO_INCREMENT NOT NULL, occurred_at DATETIME NOT NULL, type VARCHAR(40) NOT NULL, username VARCHAR(64) DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(255) DEFAULT NULL, INDEX idx_security_event_occurred_at (occurred_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE skill (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, position INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, pillar_id INT NOT NULL, INDEX IDX_5E3DE477164AAF93 (pillar_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE skill_pillar (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, position INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE webauthn_credential (public_key_credential_id LONGTEXT NOT NULL, type VARCHAR(255) NOT NULL, transports JSON NOT NULL, attestation_type VARCHAR(255) NOT NULL, trust_path JSON NOT NULL, aaguid TINYTEXT NOT NULL, credential_public_key LONGTEXT NOT NULL, user_handle VARCHAR(255) NOT NULL, counter INT NOT NULL, other_ui JSON DEFAULT NULL, backup_eligible TINYINT DEFAULT NULL, backup_status TINYINT DEFAULT NULL, uv_initialized TINYINT DEFAULT NULL, id VARCHAR(26) NOT NULL, label VARCHAR(80) NOT NULL, created_at DATETIME NOT NULL, last_used_at DATETIME DEFAULT NULL, INDEX idx_webauthn_credential_user_handle (user_handle), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE experience_skill ADD CONSTRAINT FK_3D6F986146E90E27 FOREIGN KEY (experience_id) REFERENCES experience (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE experience_skill ADD CONSTRAINT FK_3D6F98615585C142 FOREIGN KEY (skill_id) REFERENCES skill (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE skill ADD CONSTRAINT FK_5E3DE477164AAF93 FOREIGN KEY (pillar_id) REFERENCES skill_pillar (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE experience_skill DROP FOREIGN KEY FK_3D6F986146E90E27');
        $this->addSql('ALTER TABLE experience_skill DROP FOREIGN KEY FK_3D6F98615585C142');
        $this->addSql('ALTER TABLE skill DROP FOREIGN KEY FK_5E3DE477164AAF93');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE certification');
        $this->addSql('DROP TABLE cv_language');
        $this->addSql('DROP TABLE education');
        $this->addSql('DROP TABLE experience');
        $this->addSql('DROP TABLE experience_skill');
        $this->addSql('DROP TABLE key_figure');
        $this->addSql('DROP TABLE profile');
        $this->addSql('DROP TABLE security_event');
        $this->addSql('DROP TABLE skill');
        $this->addSql('DROP TABLE skill_pillar');
        $this->addSql('DROP TABLE webauthn_credential');
    }
}
