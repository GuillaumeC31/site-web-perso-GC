<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « Cerveau de l'IA » : fiches de connaissances de l'assistant et leur historique, questions sans réponse,
 * réponses bloquées et essais de l'admin dans le journal, plafond de taille des fiches.
 *
 * Liens :
 * - Entités : AiKnowledgeEntry, AiKnowledgeRevision, UnansweredQuestion, ChatMessage, SiteSettings
 */
final class Version20261026164221 extends AbstractMigration
{
    /**
     * Décrit la migration (affichée par doctrine:migrations:status).
     */
    public function getDescription(): string
    {
        return 'Cerveau de l\'IA : fiches, historique, questions sans réponse, réponses bloquées, essais admin';
    }

    /**
     * Crée les tables et colonnes.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ai_knowledge_entry (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(120) NOT NULL, content LONGTEXT NOT NULL, category VARCHAR(30) NOT NULL, active TINYINT NOT NULL, position INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE ai_knowledge_revision (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(120) NOT NULL, content LONGTEXT NOT NULL, category VARCHAR(30) NOT NULL, active TINYINT NOT NULL, changed_at DATETIME NOT NULL, entry_id INT NOT NULL, INDEX IDX_9A7127BA364942 (entry_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE unanswered_question (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, question LONGTEXT NOT NULL, reason VARCHAR(20) NOT NULL, blocked_element VARCHAR(120) DEFAULT NULL, INDEX idx_unanswered_question_status (status), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE ai_knowledge_revision ADD CONSTRAINT FK_9A7127BA364942 FOREIGN KEY (entry_id) REFERENCES ai_knowledge_entry (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE chat_message ADD blocked_element VARCHAR(120) DEFAULT NULL, ADD admin_test TINYINT DEFAULT 0 NOT NULL, CHANGE recruiter_id recruiter_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE site_settings ADD ai_knowledge_token_limit INT DEFAULT 6000 NOT NULL');
    }

    /**
     * Supprime les tables et colonnes (les essais de l'admin, sans recruteur, doivent être supprimés avant).
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_knowledge_revision DROP FOREIGN KEY FK_9A7127BA364942');
        $this->addSql('DROP TABLE ai_knowledge_entry');
        $this->addSql('DROP TABLE ai_knowledge_revision');
        $this->addSql('DROP TABLE unanswered_question');
        $this->addSql('ALTER TABLE chat_message DROP blocked_element, DROP admin_test, CHANGE recruiter_id recruiter_id INT NOT NULL');
        $this->addSql('ALTER TABLE site_settings DROP ai_knowledge_token_limit');
    }
}
