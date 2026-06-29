<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260619113500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create portfolio tracker users, broker accounts, transactions, FIFO lot, realized trade, and price tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE users (id INT AUTO_INCREMENT NOT NULL, username VARCHAR(80) NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_1483A5E9F85E0677 (username), UNIQUE INDEX UNIQ_1483A5E9E7927C74 (email), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE broker_account (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, broker_type VARCHAR(20) NOT NULL, display_name VARCHAR(120) NOT NULL, account_identifier VARCHAR(120) DEFAULT NULL, currency VARCHAR(3) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX idx_broker_account_user (user_id), INDEX idx_broker_account_broker_type (broker_type), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE stock (id INT AUTO_INCREMENT NOT NULL, symbol VARCHAR(32) NOT NULL, company_name VARCHAR(180) DEFAULT NULL, exchange VARCHAR(32) DEFAULT NULL, currency VARCHAR(3) NOT NULL, sector VARCHAR(120) DEFAULT NULL, country VARCHAR(80) DEFAULT NULL, INDEX idx_stock_symbol (symbol), INDEX idx_stock_exchange (exchange), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE import_file (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, broker_account_id INT NOT NULL, original_file_name VARCHAR(255) NOT NULL, status VARCHAR(20) NOT NULL, error_message LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, INDEX idx_import_file_user (user_id), INDEX idx_import_file_broker_account (broker_account_id), INDEX idx_import_file_status (status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE transactions (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, broker_account_id INT NOT NULL, import_file_id INT DEFAULT NULL, stock_id INT NOT NULL, transaction_date DATETIME NOT NULL, type VARCHAR(4) NOT NULL, quantity NUMERIC(20, 8) NOT NULL, price NUMERIC(20, 8) NOT NULL, fees NUMERIC(20, 8) NOT NULL, currency VARCHAR(3) NOT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, INDEX idx_transaction_user (user_id), INDEX idx_transaction_broker_account (broker_account_id), INDEX idx_transaction_stock (stock_id), INDEX idx_transaction_date (transaction_date), INDEX idx_transaction_type (type), INDEX IDX_EAA81A4C80DBD080 (import_file_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE position_lot (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, broker_account_id INT NOT NULL, stock_id INT NOT NULL, buy_transaction_id INT NOT NULL, quantity_original NUMERIC(20, 8) NOT NULL, quantity_remaining NUMERIC(20, 8) NOT NULL, price NUMERIC(20, 8) NOT NULL, fees_allocated NUMERIC(20, 8) NOT NULL, opened_at DATETIME NOT NULL, created_at DATETIME NOT NULL, INDEX idx_position_lot_user (user_id), INDEX idx_position_lot_broker_account (broker_account_id), INDEX idx_position_lot_stock (stock_id), INDEX IDX_FF372103336966C1 (buy_transaction_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE realized_trade (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, broker_account_id INT NOT NULL, stock_id INT NOT NULL, buy_transaction_id INT NOT NULL, sell_transaction_id INT NOT NULL, quantity NUMERIC(20, 8) NOT NULL, buy_price NUMERIC(20, 8) NOT NULL, sell_price NUMERIC(20, 8) NOT NULL, fees_allocated NUMERIC(20, 8) NOT NULL, profit NUMERIC(20, 8) NOT NULL, profit_percent NUMERIC(10, 4) NOT NULL, holding_days INT NOT NULL, opened_at DATETIME NOT NULL, closed_at DATETIME NOT NULL, created_at DATETIME NOT NULL, INDEX idx_realized_trade_user (user_id), INDEX idx_realized_trade_broker_account (broker_account_id), INDEX idx_realized_trade_stock (stock_id), INDEX IDX_CA32ED7B336966C1 (buy_transaction_id), INDEX IDX_CA32ED7B5E55B330 (sell_transaction_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE stock_price (id INT AUTO_INCREMENT NOT NULL, stock_id INT NOT NULL, date DATE NOT NULL, open NUMERIC(20, 8) NOT NULL, high NUMERIC(20, 8) NOT NULL, low NUMERIC(20, 8) NOT NULL, close NUMERIC(20, 8) NOT NULL, volume INT DEFAULT NULL, INDEX idx_stock_price_stock (stock_id), INDEX idx_stock_price_date (date), UNIQUE INDEX uniq_stock_price_stock_date (stock_id, date), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE broker_account ADD CONSTRAINT FK_BROKER_ACCOUNT_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE import_file ADD CONSTRAINT FK_IMPORT_FILE_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE import_file ADD CONSTRAINT FK_IMPORT_FILE_BROKER_ACCOUNT FOREIGN KEY (broker_account_id) REFERENCES broker_account (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE transactions ADD CONSTRAINT FK_TRANSACTIONS_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE transactions ADD CONSTRAINT FK_TRANSACTIONS_BROKER_ACCOUNT FOREIGN KEY (broker_account_id) REFERENCES broker_account (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE transactions ADD CONSTRAINT FK_TRANSACTIONS_IMPORT_FILE FOREIGN KEY (import_file_id) REFERENCES import_file (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE transactions ADD CONSTRAINT FK_TRANSACTIONS_STOCK FOREIGN KEY (stock_id) REFERENCES stock (id)');
        $this->addSql('ALTER TABLE position_lot ADD CONSTRAINT FK_POSITION_LOT_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE position_lot ADD CONSTRAINT FK_POSITION_LOT_BROKER_ACCOUNT FOREIGN KEY (broker_account_id) REFERENCES broker_account (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE position_lot ADD CONSTRAINT FK_POSITION_LOT_STOCK FOREIGN KEY (stock_id) REFERENCES stock (id)');
        $this->addSql('ALTER TABLE position_lot ADD CONSTRAINT FK_POSITION_LOT_BUY_TRANSACTION FOREIGN KEY (buy_transaction_id) REFERENCES transactions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE realized_trade ADD CONSTRAINT FK_REALIZED_TRADE_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE realized_trade ADD CONSTRAINT FK_REALIZED_TRADE_BROKER_ACCOUNT FOREIGN KEY (broker_account_id) REFERENCES broker_account (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE realized_trade ADD CONSTRAINT FK_REALIZED_TRADE_STOCK FOREIGN KEY (stock_id) REFERENCES stock (id)');
        $this->addSql('ALTER TABLE realized_trade ADD CONSTRAINT FK_REALIZED_TRADE_BUY_TRANSACTION FOREIGN KEY (buy_transaction_id) REFERENCES transactions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE realized_trade ADD CONSTRAINT FK_REALIZED_TRADE_SELL_TRANSACTION FOREIGN KEY (sell_transaction_id) REFERENCES transactions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE stock_price ADD CONSTRAINT FK_STOCK_PRICE_STOCK FOREIGN KEY (stock_id) REFERENCES stock (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_price DROP FOREIGN KEY FK_STOCK_PRICE_STOCK');
        $this->addSql('ALTER TABLE realized_trade DROP FOREIGN KEY FK_REALIZED_TRADE_SELL_TRANSACTION');
        $this->addSql('ALTER TABLE realized_trade DROP FOREIGN KEY FK_REALIZED_TRADE_BUY_TRANSACTION');
        $this->addSql('ALTER TABLE realized_trade DROP FOREIGN KEY FK_REALIZED_TRADE_STOCK');
        $this->addSql('ALTER TABLE realized_trade DROP FOREIGN KEY FK_REALIZED_TRADE_BROKER_ACCOUNT');
        $this->addSql('ALTER TABLE realized_trade DROP FOREIGN KEY FK_REALIZED_TRADE_USER');
        $this->addSql('ALTER TABLE position_lot DROP FOREIGN KEY FK_POSITION_LOT_BUY_TRANSACTION');
        $this->addSql('ALTER TABLE position_lot DROP FOREIGN KEY FK_POSITION_LOT_STOCK');
        $this->addSql('ALTER TABLE position_lot DROP FOREIGN KEY FK_POSITION_LOT_BROKER_ACCOUNT');
        $this->addSql('ALTER TABLE position_lot DROP FOREIGN KEY FK_POSITION_LOT_USER');
        $this->addSql('ALTER TABLE transactions DROP FOREIGN KEY FK_TRANSACTIONS_STOCK');
        $this->addSql('ALTER TABLE transactions DROP FOREIGN KEY FK_TRANSACTIONS_IMPORT_FILE');
        $this->addSql('ALTER TABLE transactions DROP FOREIGN KEY FK_TRANSACTIONS_BROKER_ACCOUNT');
        $this->addSql('ALTER TABLE transactions DROP FOREIGN KEY FK_TRANSACTIONS_USER');
        $this->addSql('ALTER TABLE import_file DROP FOREIGN KEY FK_IMPORT_FILE_BROKER_ACCOUNT');
        $this->addSql('ALTER TABLE import_file DROP FOREIGN KEY FK_IMPORT_FILE_USER');
        $this->addSql('ALTER TABLE broker_account DROP FOREIGN KEY FK_BROKER_ACCOUNT_USER');
        $this->addSql('DROP TABLE stock_price');
        $this->addSql('DROP TABLE realized_trade');
        $this->addSql('DROP TABLE position_lot');
        $this->addSql('DROP TABLE transactions');
        $this->addSql('DROP TABLE import_file');
        $this->addSql('DROP TABLE stock');
        $this->addSql('DROP TABLE broker_account');
        $this->addSql('DROP TABLE users');
    }
}
