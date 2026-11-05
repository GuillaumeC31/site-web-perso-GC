<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * v2.0.1 : génération des cookies « appareil de confiance » (lien vers l'admin sur le cœur du pied de page). Le
 * bouton « Révoquer tous les appareils de confiance » l'incrémente, ce qui invalide tous les cookies déjà posés.
 *
 * Liens :
 * - Entité : App\Entity\SiteSettings (trustedDeviceGeneration)
 * - Utilisée par : App\Security\TrustedDeviceCookie
 */
final class Version20261105000000 extends AbstractMigration
{
    /**
     * Décrit la migration (affichée par doctrine:migrations:status).
     */
    public function getDescription(): string
    {
        return 'Paramètres : génération des cookies « appareil de confiance »';
    }

    /**
     * Ajoute la colonne (génération 1 pour la ligne existante).
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_settings ADD trusted_device_generation INT DEFAULT 1 NOT NULL');
    }

    /**
     * Retire la colonne.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_settings DROP trusted_device_generation');
    }
}
