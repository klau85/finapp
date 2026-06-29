<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260629090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Link transaction rows belonging to the same corporate action.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transactions ADD corporate_action_group VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_transaction_corporate_action_group ON transactions (corporate_action_group)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_transaction_corporate_action_group ON transactions');
        $this->addSql('ALTER TABLE transactions DROP corporate_action_group');
    }
}
