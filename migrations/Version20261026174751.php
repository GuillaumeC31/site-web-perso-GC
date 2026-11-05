<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Demande d'accès déjà en attente : date du dernier e-mail « Votre demande est déjà en cours d'examen »
 * (au plus un par 24 h et par adresse).
 *
 * Liens :
 * - Entité : App\Entity\Recruiter (pendingNoticeSentAt)
 */
final class Version20261026174751 extends AbstractMigration
{
    /**
     * Décrit la migration (affichée par doctrine:migrations:status).
     */
    public function getDescription(): string
    {
        return 'Recruteur : date du dernier e-mail « demande déjà en cours d\'examen »';
    }

    /**
     * Ajoute la colonne recruiter.pending_notice_sent_at.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recruiter ADD pending_notice_sent_at DATETIME DEFAULT NULL');
    }

    /**
     * Retire la colonne recruiter.pending_notice_sent_at.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recruiter DROP pending_notice_sent_at');
    }
}
