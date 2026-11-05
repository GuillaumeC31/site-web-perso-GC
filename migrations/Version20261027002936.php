<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Page de maintenance : date et heure de réactivation (compte à rebours, Retry-After) et adresses IP
 * autorisées pendant la maintenance.
 *
 * Liens :
 * - Entité : App\Entity\SiteSettings (maintenanceUntil, maintenanceAllowedIps)
 */
final class Version20261027002936 extends AbstractMigration
{
    /**
     * Décrit la migration (affichée par doctrine:migrations:status).
     */
    public function getDescription(): string
    {
        return 'Maintenance : date de réactivation et adresses IP autorisées';
    }

    /**
     * Ajoute les colonnes site_settings.maintenance_until et maintenance_allowed_ips.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_settings ADD maintenance_until DATETIME DEFAULT NULL, ADD maintenance_allowed_ips VARCHAR(1000) DEFAULT NULL');
    }

    /**
     * Retire les colonnes.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_settings DROP maintenance_until, DROP maintenance_allowed_ips');
    }
}
