<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tableau de bord de l'admin : passages des tâches planifiées et évènements surveillés (heartbeat), état du
 * tableau de bord déjà vu par l'admin (récapitulatif seulement si quelque chose a changé).
 *
 * Liens :
 * - Entités : App\Entity\Heartbeat, App\Entity\User (dashboardSeen)
 */
final class Version20261026141156 extends AbstractMigration
{
    /**
     * Décrit la migration (affichée par doctrine:migrations:status).
     */
    public function getDescription(): string
    {
        return 'Tableau de bord : passages des tâches planifiées (heartbeat), état déjà vu par l\'admin';
    }

    /**
     * Crée la table heartbeat et la colonne app_user.dashboard_seen.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE heartbeat (name VARCHAR(40) NOT NULL, last_run_at DATETIME NOT NULL, success TINYINT NOT NULL, details JSON NOT NULL, PRIMARY KEY (name)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE app_user ADD dashboard_seen JSON DEFAULT NULL');
    }

    /**
     * Supprime la table heartbeat et la colonne app_user.dashboard_seen.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE heartbeat');
        $this->addSql('ALTER TABLE app_user DROP dashboard_seen');
    }
}
