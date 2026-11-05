<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Validation à distance : jeton sans demande possible (jetons d'essai de la notification de test).
 *
 * Liens :
 * - Entité : App\Entity\RemoteDecisionToken (recruiter facultatif)
 */
final class Version20261027012922 extends AbstractMigration
{
    /**
     * Décrit la migration (affichée par doctrine:migrations:status).
     */
    public function getDescription(): string
    {
        return 'Validation à distance : jetons d\'essai (sans demande)';
    }

    /**
     * Rend la colonne remote_decision_token.recruiter_id facultative.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE remote_decision_token CHANGE recruiter_id recruiter_id INT DEFAULT NULL');
    }

    /**
     * Supprime les jetons d'essai, puis rend la colonne obligatoire.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM remote_decision_token WHERE recruiter_id IS NULL');
        $this->addSql('ALTER TABLE remote_decision_token CHANGE recruiter_id recruiter_id INT NOT NULL');
    }
}
