<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Page « Communication » : adresses de réception par catégorie (Recrutement, Général du site, Calculatrice), nom
 * d'expéditeur, sélecteur DKIM, textes modifiables des e-mails et leur historique, historique des envois.
 *
 * Les adresses « Recrutement » et « Général du site » reprennent l'adresse de notification actuelle du compte
 * admin (décision du 2026-10-29), modifiable ensuite dans Communication › E-mails.
 *
 * Liens :
 * - Entités : App\Entity\SiteSettings, EmailText, EmailTextRevision, EmailLog
 */
final class Version20261029144833 extends AbstractMigration
{
    /**
     * Décrit la migration (affichée par doctrine:migrations:status).
     */
    public function getDescription(): string
    {
        return 'Communication : adresses par catégorie, textes des e-mails, historique des envois';
    }

    /**
     * Crée les tables email_log, email_text, email_text_revision et les colonnes de site_settings, puis reprend
     * l'adresse de notification du compte admin.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE email_log (id INT AUTO_INCREMENT NOT NULL, sent_at DATETIME NOT NULL, kind VARCHAR(40) NOT NULL, category VARCHAR(20) DEFAULT NULL, recipient VARCHAR(190) NOT NULL, success TINYINT NOT NULL, INDEX idx_email_log_sent_at (sent_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE email_text (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(40) NOT NULL, subject VARCHAR(150) DEFAULT NULL, intro LONGTEXT DEFAULT NULL, signature VARCHAR(300) DEFAULT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_email_text_type (type), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE email_text_revision (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(40) NOT NULL, action VARCHAR(10) NOT NULL, subject VARCHAR(150) DEFAULT NULL, intro LONGTEXT DEFAULT NULL, signature VARCHAR(300) DEFAULT NULL, saved_at DATETIME NOT NULL, INDEX idx_email_text_revision_type (type, saved_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE site_settings ADD recruitment_email VARCHAR(180) DEFAULT NULL, ADD general_email VARCHAR(180) DEFAULT NULL, ADD calculator_email VARCHAR(180) DEFAULT NULL, ADD mail_from_name VARCHAR(100) DEFAULT NULL, ADD dkim_selector VARCHAR(63) DEFAULT \'default\' NOT NULL');
        // Valeur initiale : adresse de notification du premier compte admin qui en a une
        $this->addSql(<<<'SQL'
            UPDATE site_settings SET
                recruitment_email = (SELECT u.notification_email FROM app_user u WHERE u.roles LIKE '%ROLE_ADMIN%' AND u.notification_email IS NOT NULL AND u.notification_email <> '' ORDER BY u.id LIMIT 1),
                general_email = (SELECT u.notification_email FROM app_user u WHERE u.roles LIKE '%ROLE_ADMIN%' AND u.notification_email IS NOT NULL AND u.notification_email <> '' ORDER BY u.id LIMIT 1)
            SQL);
    }

    /**
     * Supprime les tables et colonnes ajoutées.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE email_log');
        $this->addSql('DROP TABLE email_text');
        $this->addSql('DROP TABLE email_text_revision');
        $this->addSql('ALTER TABLE site_settings DROP recruitment_email, DROP general_email, DROP calculator_email, DROP mail_from_name, DROP dkim_selector');
    }
}
