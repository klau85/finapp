<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260701090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user-scoped portfolio, stock, and transaction journal entries.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE journal_entry (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, stock_id INT DEFAULT NULL, transaction_id INT DEFAULT NULL, target_type VARCHAR(20) NOT NULL, entry_type VARCHAR(30) NOT NULL, title VARCHAR(180) DEFAULT NULL, content LONGTEXT NOT NULL, entry_date DATE NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX idx_journal_user_date (user_id, entry_date), INDEX idx_journal_stock (stock_id), INDEX idx_journal_transaction (transaction_id), INDEX idx_journal_target_type (target_type), INDEX idx_journal_entry_type (entry_type), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE journal_entry ADD CONSTRAINT FK_JOURNAL_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE journal_entry ADD CONSTRAINT FK_JOURNAL_STOCK FOREIGN KEY (stock_id) REFERENCES stock (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE journal_entry ADD CONSTRAINT FK_JOURNAL_TRANSACTION FOREIGN KEY (transaction_id) REFERENCES transactions (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE journal_entry');
    }
}
