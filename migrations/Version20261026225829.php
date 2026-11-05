<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Validation à distance des demandes recruteurs : jetons à usage unique (empreinte seule), interrupteur et
 * empreinte du code PIN dans les paramètres ; fonction et site web facultatifs de la demande d'accès.
 *
 * Liens :
 * - Entités : App\Entity\RemoteDecisionToken, SiteSettings (remoteValidationEnabled, remotePinHash),
 *   Recruiter (jobTitle, companyWebsite)
 */
final class Version20261026225829 extends AbstractMigration
{
    /**
     * Décrit la migration (affichée par doctrine:migrations:status).
     */
    public function getDescription(): string
    {
        return 'Validation à distance (jetons, interrupteur, code PIN) ; fonction et site web de la demande d\'accès';
    }

    /**
     * Crée la table remote_decision_token et ajoute les colonnes.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE remote_decision_token (id INT AUTO_INCREMENT NOT NULL, blocked_at DATETIME DEFAULT NULL, used_at DATETIME DEFAULT NULL, confirm_requested_at DATETIME DEFAULT NULL, failed_attempts INT DEFAULT 0 NOT NULL, decision VARCHAR(10) NOT NULL, channel VARCHAR(10) NOT NULL, token_hash VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, recruiter_id INT NOT NULL, UNIQUE INDEX UNIQ_E3EC797DB3BC57DA (token_hash), INDEX IDX_E3EC797D156BE243 (recruiter_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE remote_decision_token ADD CONSTRAINT FK_E3EC797D156BE243 FOREIGN KEY (recruiter_id) REFERENCES recruiter (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE recruiter ADD job_title VARCHAR(120) DEFAULT NULL, ADD company_website VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE site_settings ADD remote_validation_enabled TINYINT DEFAULT 0 NOT NULL, ADD remote_pin_hash VARCHAR(255) DEFAULT NULL');
    }

    /**
     * Retire la table et les colonnes.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE remote_decision_token DROP FOREIGN KEY FK_E3EC797D156BE243');
        $this->addSql('DROP TABLE remote_decision_token');
        $this->addSql('ALTER TABLE recruiter DROP job_title, DROP company_website');
        $this->addSql('ALTER TABLE site_settings DROP remote_validation_enabled, DROP remote_pin_hash');
    }
}
