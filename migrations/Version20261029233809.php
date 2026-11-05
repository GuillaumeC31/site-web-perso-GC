<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CV public allégé (correction de recette n° 1, 2026-10-30) : puces d'expérience « Visible en public » et résumé
 * public des rubriques Outils techniques, Expertises métiers et Logiciels.
 *
 * Sans puce cochée, une expérience n'affiche en public que son en-tête : la synchronisation de TEXTES_SITE.md
 * (repères 🌐) remplit ces champs.
 *
 * Liens :
 * - Entités : App\Entity\Experience (publicItems), App\Entity\CvEntry (publicSummary)
 */
final class Version20261029233809 extends AbstractMigration
{
    /**
     * Décrit la migration (affichée par doctrine:migrations:status).
     */
    public function getDescription(): string
    {
        return 'CV public allégé : puces publiques des expériences, résumé public des rubriques';
    }

    /**
     * Ajoute les deux colonnes.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cv_entry ADD public_summary VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE experience ADD public_items JSON DEFAULT \'[]\' NOT NULL');
    }

    /**
     * Retire les deux colonnes.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cv_entry DROP public_summary');
        $this->addSql('ALTER TABLE experience DROP public_items');
    }
}
