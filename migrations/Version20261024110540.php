<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot 7 : assistant IA de l'espace recruteur (Mistral AI) : journal des conversations (chat_message),
 * consommation (ai_usage), consentement et synthèse d'entreprise du recruteur, plafonds dans site_settings.
 */
final class Version20261024110540 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot 7 : assistant IA (journal, consommation, consentement, synthèse, plafonds)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ai_usage (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, purpose VARCHAR(30) NOT NULL, model VARCHAR(80) NOT NULL, prompt_tokens INT NOT NULL, completion_tokens INT NOT NULL, error VARCHAR(40) DEFAULT NULL, INDEX idx_ai_usage_created_at (created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE chat_message (id INT AUTO_INCREMENT NOT NULL, model VARCHAR(80) DEFAULT NULL, prompt_tokens INT NOT NULL, completion_tokens INT NOT NULL, duration_ms INT DEFAULT NULL, error VARCHAR(255) DEFAULT NULL, suspicious_reason VARCHAR(120) DEFAULT NULL, created_at DATETIME NOT NULL, question LONGTEXT NOT NULL, answer LONGTEXT NOT NULL, outcome VARCHAR(30) NOT NULL, recruiter_id INT NOT NULL, INDEX idx_chat_message_created_at (created_at), INDEX IDX_FAB3FC16156BE243 (recruiter_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE chat_message ADD CONSTRAINT FK_FAB3FC16156BE243 FOREIGN KEY (recruiter_id) REFERENCES recruiter (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE recruiter ADD ai_consent_at DATETIME DEFAULT NULL, ADD company_synthesis LONGTEXT DEFAULT NULL, ADD company_synthesis_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE site_settings ADD ai_assistant_enabled TINYINT DEFAULT 1 NOT NULL, ADD ai_recruiter_daily_limit INT DEFAULT 20 NOT NULL, ADD ai_site_daily_limit INT DEFAULT 100 NOT NULL, ADD ai_monthly_token_limit INT DEFAULT 5000000 NOT NULL, ADD ai_requests_per_second INT DEFAULT 1 NOT NULL, ADD ai_alert_percent INT DEFAULT 80 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chat_message DROP FOREIGN KEY FK_FAB3FC16156BE243');
        $this->addSql('DROP TABLE ai_usage');
        $this->addSql('DROP TABLE chat_message');
        $this->addSql('ALTER TABLE recruiter DROP ai_consent_at, DROP company_synthesis, DROP company_synthesis_at');
        $this->addSql('ALTER TABLE site_settings DROP ai_assistant_enabled, DROP ai_recruiter_daily_limit, DROP ai_site_daily_limit, DROP ai_monthly_token_limit, DROP ai_requests_per_second, DROP ai_alert_percent');
    }
}
