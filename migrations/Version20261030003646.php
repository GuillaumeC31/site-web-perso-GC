<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CV public (correction de recette n° 2, 2026-10-30) : visibilité publique des rubriques « Langues » et « Formations et
 * certifications ». Visibles par défaut (comportement actuel) ; la synchronisation de TEXTES_SITE.md (repère 🔒 du
 * titre des § 9 et 10) les réserve à l'espace recruteur.
 *
 * Liens :
 * - Entité : App\Entity\Profile (languagesPublic, educationsPublic)
 */
final class Version20261030003646 extends AbstractMigration
{
    /**
     * Décrit la migration (affichée par doctrine:migrations:status).
     */
    public function getDescription(): string
    {
        return 'Profil : visibilité publique des rubriques Langues et Formations';
    }

    /**
     * Ajoute les deux colonnes.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE profile ADD languages_public TINYINT DEFAULT 1 NOT NULL, ADD educations_public TINYINT DEFAULT 1 NOT NULL');
    }

    /**
     * Retire les deux colonnes.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE profile DROP languages_public, DROP educations_public');
    }
}
