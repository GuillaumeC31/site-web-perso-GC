<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot 8 : informations de l'hébergeur (mentions légales) réglables dans l'admin, valeurs de l’hébergeur par défaut.
 */
final class Version20261024152558 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot 8 : hébergeur des mentions légales dans site_settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_settings ADD host_name VARCHAR(200) DEFAULT \'Hébergeur Exemple SAS, RCS Exemple, SIREN 000 000 000\' NOT NULL, ADD host_address VARCHAR(200) DEFAULT \'1 rue de l’Exemple, 75000 Paris, France\' NOT NULL, ADD host_phone VARCHAR(40) DEFAULT \'01 23 45 67 89\' NOT NULL, ADD host_website VARCHAR(255) DEFAULT \'https://hebergeur.example.com\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_settings DROP host_name, DROP host_address, DROP host_phone, DROP host_website');
    }
}
