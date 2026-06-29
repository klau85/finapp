<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260629130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove the discontinued saved-symbol feature.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS watchlist_item');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The removed feature and its data cannot be restored.');
    }
}
