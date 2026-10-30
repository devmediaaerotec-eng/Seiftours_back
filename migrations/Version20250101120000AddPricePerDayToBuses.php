<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250101120000AddPricePerDayToBuses extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la colonne price_per_day à la table buses';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE buses ADD price_per_day NUMERIC(10, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE buses DROP price_per_day');
    }
}