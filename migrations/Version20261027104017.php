<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Validation à distance, code PIN sur Telegram : message des boutons et message qui demande le code PIN
 * (la réponse à ce message porte le code).
 *
 * Liens :
 * - Entité : App\Entity\RemoteDecisionToken (telegramMessageId, telegramPromptMessageId)
 */
final class Version20261027104017 extends AbstractMigration
{
    /**
     * Décrit la migration (affichée par doctrine:migrations:status).
     */
    public function getDescription(): string
    {
        return 'Validation à distance : messages Telegram du code PIN';
    }

    /**
     * Ajoute les colonnes remote_decision_token.telegram_message_id et telegram_prompt_message_id.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE remote_decision_token ADD telegram_message_id INT DEFAULT NULL, ADD telegram_prompt_message_id INT DEFAULT NULL');
    }

    /**
     * Retire les colonnes.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE remote_decision_token DROP telegram_message_id, DROP telegram_prompt_message_id');
    }
}
