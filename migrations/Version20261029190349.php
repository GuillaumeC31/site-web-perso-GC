<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * RGPD (2026-10-29) : le recruteur peut refuser les rappels d'échéance (lien « Ne plus recevoir ces rappels » de
 * l'e-mail de rappel), avec effet immédiat.
 *
 * Liens :
 * - Entité : App\Entity\Recruiter (remindersStoppedAt)
 */
final class Version20261029190349 extends AbstractMigration
{
    /**
     * Décrit la migration (affichée par doctrine:migrations:status).
     */
    public function getDescription(): string
    {
        return 'Recruteurs : date de refus des rappels d\'échéance';
    }

    /**
     * Ajoute la colonne (null : rappels acceptés).
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recruiter ADD reminders_stopped_at DATETIME DEFAULT NULL');
    }

    /**
     * Retire la colonne.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recruiter DROP reminders_stopped_at');
    }
}
