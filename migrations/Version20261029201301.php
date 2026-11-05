<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sécurité des envois (2026-10-29) : plafond quotidien des e-mails aux personnes non validées (30 par défaut) et date
 * de la dernière copie de sauvegarde téléchargée (rappel au tableau de bord après 30 jours).
 *
 * Liens :
 * - Entité : App\Entity\SiteSettings (unvalidatedEmailDailyCap, backupDownloadedAt)
 */
final class Version20261029201301 extends AbstractMigration
{
    /**
     * Décrit la migration (affichée par doctrine:migrations:status).
     */
    public function getDescription(): string
    {
        return 'Paramètres : plafond des e-mails aux personnes non validées, dernière copie de sauvegarde téléchargée';
    }

    /**
     * Ajoute les deux colonnes.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_settings ADD unvalidated_email_daily_cap INT DEFAULT 30 NOT NULL, ADD backup_downloaded_at DATETIME DEFAULT NULL');
    }

    /**
     * Retire les deux colonnes.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_settings DROP unvalidated_email_daily_cap, DROP backup_downloaded_at');
    }
}
