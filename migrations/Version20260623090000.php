<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260623090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user number formatting preference.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE users ADD number_format VARCHAR(20) NOT NULL DEFAULT 'comma_dot'");
        $this->addSql('ALTER TABLE users CHANGE number_format number_format VARCHAR(20) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP number_format');
    }
}
