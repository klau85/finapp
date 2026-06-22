<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260619235000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add market data quote cache and OHLC provider metadata.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE stock_quote (id INT AUTO_INCREMENT NOT NULL, stock_id INT NOT NULL, price NUMERIC(20, 8) NOT NULL, change_amount NUMERIC(20, 8) DEFAULT NULL, change_percent NUMERIC(10, 4) DEFAULT NULL, currency VARCHAR(3) DEFAULT NULL, market_time DATETIME DEFAULT NULL, provider VARCHAR(40) NOT NULL, fetched_at DATETIME NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX idx_stock_quote_stock (stock_id), INDEX idx_stock_quote_fetched_at (fetched_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE stock_quote ADD CONSTRAINT FK_STOCK_QUOTE_STOCK FOREIGN KEY (stock_id) REFERENCES stock (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE stock_price ADD provider VARCHAR(40) DEFAULT NULL, ADD fetched_at DATETIME DEFAULT NULL, ADD created_at DATETIME DEFAULT NULL, ADD updated_at DATETIME DEFAULT NULL, CHANGE volume volume BIGINT DEFAULT NULL');
        $this->addSql("UPDATE stock_price SET provider = 'stooq', fetched_at = NOW(), created_at = NOW(), updated_at = NOW() WHERE provider IS NULL");
        $this->addSql('ALTER TABLE stock_price MODIFY provider VARCHAR(40) NOT NULL, MODIFY fetched_at DATETIME NOT NULL, MODIFY created_at DATETIME NOT NULL, MODIFY updated_at DATETIME NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_quote DROP FOREIGN KEY FK_STOCK_QUOTE_STOCK');
        $this->addSql('DROP TABLE stock_quote');
        $this->addSql('ALTER TABLE stock_price DROP provider, DROP fetched_at, DROP created_at, DROP updated_at, CHANGE volume volume INT DEFAULT NULL');
    }
}
