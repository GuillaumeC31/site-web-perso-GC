<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Repère précis des lignes créées par les tests de bout en bout (colonne test_trace) : le nettoyage de fin de
 * suite ne supprime plus que ces lignes, jamais selon une plage horaire (décision du 2026-10-29).
 *
 * Liens :
 * - Entités : App\Entity\AiUsage, ChatMessage, EmailLog, EmailTextRevision, UnansweredQuestion (Trait\TestTraceTrait)
 */
final class Version20261029155507 extends AbstractMigration
{
    /**
     * Décrit la migration (affichée par doctrine:migrations:status).
     */
    public function getDescription(): string
    {
        return 'Repère test_trace des lignes créées par les tests de bout en bout';
    }

    /**
     * Ajoute la colonne test_trace (faux par défaut : les lignes existantes sont réelles).
     */
    public function up(Schema $schema): void
    {
        foreach (['ai_usage', 'chat_message', 'email_log', 'email_text_revision', 'unanswered_question'] as $table) {
            $this->addSql('ALTER TABLE '.$table.' ADD test_trace TINYINT DEFAULT 0 NOT NULL');
        }
    }

    /**
     * Retire la colonne test_trace.
     */
    public function down(Schema $schema): void
    {
        foreach (['ai_usage', 'chat_message', 'email_log', 'email_text_revision', 'unanswered_question'] as $table) {
            $this->addSql('ALTER TABLE '.$table.' DROP test_trace');
        }
    }
}
