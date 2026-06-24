<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260624090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add broker account currency transaction amount fields.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transactions ADD broker_amount NUMERIC(20, 8) DEFAULT NULL, ADD broker_currency VARCHAR(3) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transactions DROP broker_amount, DROP broker_currency');
    }
}
