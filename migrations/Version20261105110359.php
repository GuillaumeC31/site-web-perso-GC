<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * v2.0.2 : bloc « Pourquoi me recruter » de l'accueil, géré dans l'admin.
 *
 * - Table recruitment_argument (titre, texte d'une à deux phrases, interrupteur « Afficher », ordre).
 * - Interrupteur général site_settings.recruitment_block_enabled, COUPÉ (0).
 * - Contenu de départ : 6 arguments d'exemple, TOUS MASQUÉS. Chargés une seule fois, par cette
 *   migration : un argument supprimé ensuite dans l'admin ne revient jamais au déploiement suivant.
 *
 * Liens :
 * - Entités : App\Entity\RecruitmentArgument, App\Entity\SiteSettings (recruitmentBlockEnabled)
 */
final class Version20261105110359 extends AbstractMigration
{
    /** Contenu de départ (titre, texte), dans l'ordre d'affichage. */
    private const DEFAULT_ARGUMENTS = [
        ['Argument 1', 'Texte d’exemple de l’argument 1.'],
        ['Argument 2', 'Texte d’exemple de l’argument 2.'],
        ['Argument 3', 'Texte d’exemple de l’argument 3.'],
        ['Argument 4', 'Texte d’exemple de l’argument 4.'],
        ['Argument 5', 'Texte d’exemple de l’argument 5.'],
        ['Argument 6', 'Texte d’exemple de l’argument 6.'],
    ];

    /**
     * Décrit la migration (affichée par doctrine:migrations:status).
     */
    public function getDescription(): string
    {
        return 'Bloc « Pourquoi me recruter » : arguments (6, masqués) et interrupteur général (coupé)';
    }

    /**
     * Crée la table, ajoute l'interrupteur général et charge les 6 arguments masqués.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE recruitment_argument (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(120) NOT NULL, text VARCHAR(400) NOT NULL, visible TINYINT DEFAULT 0 NOT NULL, position INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE site_settings ADD recruitment_block_enabled TINYINT DEFAULT 0 NOT NULL');

        foreach (self::DEFAULT_ARGUMENTS as $index => [$title, $text]) {
            $this->addSql(
                'INSERT INTO recruitment_argument (title, text, visible, position, created_at, updated_at) VALUES (?, ?, 0, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                [$title, $text, $index + 1],
            );
        }
    }

    /**
     * Retire la table (et son contenu) et l'interrupteur général.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE recruitment_argument');
        $this->addSql('ALTER TABLE site_settings DROP recruitment_block_enabled');
    }
}
