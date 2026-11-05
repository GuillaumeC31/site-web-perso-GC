<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * E-mails non envoyés (serveur SMTP injoignable), gardés pour un renvoi depuis le tableau de bord de l'admin.
 *
 * Liens :
 * - Entité : App\Entity\FailedEmail
 */
final class Version20261026105143 extends AbstractMigration
{
    /**
     * Décrit la migration (affichée par doctrine:migrations:status).
     */
    public function getDescription(): string
    {
        return 'E-mails non envoyés, gardés pour un renvoi (failed_email)';
    }

    /**
     * Crée la table failed_email.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE failed_email (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, kind VARCHAR(40) NOT NULL, recipient VARCHAR(180) NOT NULL, subject VARCHAR(255) NOT NULL, payload LONGTEXT NOT NULL, recruiter_id INT DEFAULT NULL, attempts INT NOT NULL, last_error VARCHAR(255) NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
    }

    /**
     * Supprime la table failed_email.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE failed_email');
    }
}
