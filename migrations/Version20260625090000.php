<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260625090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow corporate action transaction types.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transactions CHANGE type type VARCHAR(32) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transactions CHANGE type type VARCHAR(4) NOT NULL');
    }
}
