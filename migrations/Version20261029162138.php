<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Page Messages (filtres, décision du 2026-10-29) : origine du message (formulaire de contact ou question d'un
 * recruteur), dates « répondu le » et « archivé le », filtres mémorisés du compte admin.
 *
 * Reprise des données :
 * - l'ancien statut « Traité » devient « Lu » + « Répondu », daté de la réception du message (choix de Guillaume) ;
 * - origine « question d'un recruteur » : messages sans adresse IP (la question n'en garde jamais), d'objet
 *   « recrutement », dont l'adresse est celle d'un recruteur connu. Un vieux message du formulaire dont l'IP a été
 *   effacée (30 jours) et l'adresse est celle d'un recruteur pourrait être classé « question » : sans conséquence
 *   autre que son type affiché.
 *
 * Liens :
 * - Entités : App\Entity\ContactMessage (source, repliedAt, archivedAt), App\Entity\User (messageFilters)
 */
final class Version20261029162138 extends AbstractMigration
{
    /**
     * Décrit la migration (affichée par doctrine:migrations:status).
     */
    public function getDescription(): string
    {
        return 'Messages : origine, répondu le, archivé le, filtres mémorisés ; « Traité » devient « Répondu »';
    }

    /**
     * Ajoute les colonnes et reprend les données existantes.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD message_filters JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE contact_message ADD source VARCHAR(30) DEFAULT \'contact_form\' NOT NULL, ADD replied_at DATETIME DEFAULT NULL, ADD archived_at DATETIME DEFAULT NULL');
        $this->addSql("UPDATE contact_message SET status = 'read', replied_at = created_at WHERE status = 'handled'");
        $this->addSql("UPDATE contact_message SET source = 'recruiter_question' WHERE ip_address IS NULL AND subject = 'recruitment' AND email IN (SELECT email FROM recruiter)");
    }

    /**
     * Retire les colonnes ; un message répondu redevient « Traité ».
     */
    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE contact_message SET status = 'handled' WHERE replied_at IS NOT NULL");
        $this->addSql('ALTER TABLE app_user DROP message_filters');
        $this->addSql('ALTER TABLE contact_message DROP source, DROP replied_at, DROP archived_at');
    }
}
