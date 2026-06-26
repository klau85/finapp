<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\BrokerAccount;
use App\Service\CsvTransactionParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class CsvTransactionParserTest extends TestCase
{
    public function testXtbCsvMapsRowsToTransactionData(): void
    {
        $account = (new BrokerAccount())
            ->setBrokerType('xtb')
            ->setCurrency('USD');

        $rows = (new CsvTransactionParser())->parse($this->uploadedFile(<<<'CSV'
Type,Ticker,Time,Comment,Amount,Ignored
Stock purchase,NVDA.US,2026-02-12 9:15:00,OPEN BUY 1/1.18 @ 43.37,-43.37,abc
Stock sell,MSFT.US,2026-03-13 14:30:00,CLOSE BUY 0.14/0.34 @ 645.64,90.39,xyz
CSV), $account);

        self::assertCount(2, $rows);
        self::assertTrue($rows[0]->isValid());
        self::assertSame([
            'date' => '2026-02-12',
            'transactionDate' => '2026-02-12 09:15:00',
            'symbol' => 'NVDA',
            'type' => 'BUY',
            'quantity' => '1.00000000',
            'price' => '43.37000000',
            'currency' => 'USD',
            'fees' => '0.00000000',
            'brokerAmount' => '43.37000000',
            'brokerCurrency' => 'USD',
        ], $rows[0]->data);

        self::assertTrue($rows[1]->isValid());
        self::assertSame('MSFT', $rows[1]->data['symbol']);
        self::assertSame('SELL', $rows[1]->data['type']);
        self::assertSame('0.14000000', $rows[1]->data['quantity']);
        self::assertSame('645.64000000', $rows[1]->data['price']);
        self::assertSame('90.39000000', $rows[1]->data['brokerAmount']);
    }

    public function testXtbCsvRejectsUnsupportedTransactionTypes(): void
    {
        $account = (new BrokerAccount())
            ->setBrokerType('xtb')
            ->setCurrency('EUR');

        $rows = (new CsvTransactionParser())->parse($this->uploadedFile(<<<'CSV'
Type;Ticker;Time;Comment;Amount
Dividend;NVDA.US;2026-02-12 09:15:00;OPEN BUY 1 @ 43.37;-39,91
CSV), $account);

        self::assertCount(1, $rows);
        self::assertFalse($rows[0]->isValid());
        self::assertSame('USD', $rows[0]->data['currency']);
        self::assertSame('EUR', $rows[0]->data['brokerCurrency']);
        self::assertSame('39.91000000', $rows[0]->data['brokerAmount']);
        self::assertContains('type must be Stock purchase or Stock sell.', $rows[0]->errors);
    }

    public function testXtbCsvMapsEuSuffixesToEurTransactions(): void
    {
        $account = (new BrokerAccount())
            ->setBrokerType('xtb')
            ->setCurrency('USD');

        $rows = (new CsvTransactionParser())->parse($this->uploadedFile(<<<'CSV'
Type,Ticker,Time,Comment,Amount
Stock purchase,VOW.DE,2026-02-12 09:15:00,OPEN BUY 1 @ 43.37,-43.37
Stock sell,MC.PA,2026-03-12 09:15:00,CLOSE BUY 0.5 @ 612.80,306.40
CSV), $account);

        self::assertCount(2, $rows);
        self::assertTrue($rows[0]->isValid());
        self::assertSame('VOW.DE', $rows[0]->data['symbol']);
        self::assertSame('EUR', $rows[0]->data['currency']);
        self::assertSame('USD', $rows[0]->data['brokerCurrency']);
        self::assertTrue($rows[1]->isValid());
        self::assertSame('MC.PA', $rows[1]->data['symbol']);
        self::assertSame('EUR', $rows[1]->data['currency']);
    }

    public function testXtbCsvMapsTickersWithoutSuffixToUsdTransactions(): void
    {
        $account = (new BrokerAccount())
            ->setBrokerType('xtb')
            ->setCurrency('EUR');

        $rows = (new CsvTransactionParser())->parse($this->uploadedFile(<<<'CSV'
Type,Ticker,Time,Comment,Amount
Stock purchase,NVDA,2026-02-12 09:15:00,OPEN BUY 1 @ 43.37,-39.91
CSV), $account);

        self::assertCount(1, $rows);
        self::assertTrue($rows[0]->isValid());
        self::assertSame('NVDA', $rows[0]->data['symbol']);
        self::assertSame('USD', $rows[0]->data['currency']);
        self::assertSame('EUR', $rows[0]->data['brokerCurrency']);
    }

    public function testXtbCsvRejectsUnsupportedTickerSuffixes(): void
    {
        $account = (new BrokerAccount())
            ->setBrokerType('xtb')
            ->setCurrency('USD');

        $rows = (new CsvTransactionParser())->parse($this->uploadedFile(<<<'CSV'
Type,Ticker,Time,Comment,Amount
Stock purchase,VOD.L,2026-02-12 09:15:00,OPEN BUY 1 @ 43.37,-43.37
CSV), $account);

        self::assertCount(1, $rows);
        self::assertFalse($rows[0]->isValid());
        self::assertSame('VOD.L', $rows[0]->data['symbol']);
        self::assertContains('unsupported XTB ticker suffix. Supported suffixes are .US, .DE, .FR, .PA, .AS, or no suffix.', $rows[0]->errors);
    }

    public function testCustomCsvStillUsesOriginalFormat(): void
    {
        $account = (new BrokerAccount())
            ->setBrokerType('custom')
            ->setCurrency('USD');

        $rows = (new CsvTransactionParser())->parse($this->uploadedFile(<<<'CSV'
date,symbol,type,quantity,price,currency,fees
2026-02-12,nvda,buy,100,142.50,usd,1.00
CSV), $account);

        self::assertCount(1, $rows);
        self::assertTrue($rows[0]->isValid());
        self::assertSame('NVDA', $rows[0]->data['symbol']);
        self::assertSame('BUY', $rows[0]->data['type']);
        self::assertSame('1.00000000', $rows[0]->data['fees']);
    }

    public function testCustomCsvOptionalAmountIsStoredAsBrokerAmount(): void
    {
        $account = (new BrokerAccount())
            ->setBrokerType('custom')
            ->setCurrency('EUR');

        $rows = (new CsvTransactionParser())->parse($this->uploadedFile(<<<'CSV'
date;symbol;type;quantity;price;currency;fees;amount
2026-02-12;nvda;buy;100;142.50;usd;1.00;-13000,50
CSV), $account);

        self::assertCount(1, $rows);
        self::assertTrue($rows[0]->isValid());
        self::assertSame('13000.50000000', $rows[0]->data['brokerAmount']);
        self::assertSame('EUR', $rows[0]->data['brokerCurrency']);
    }

    public function testCustomCsvRejectsInvalidOptionalAmount(): void
    {
        $account = (new BrokerAccount())
            ->setBrokerType('custom')
            ->setCurrency('USD');

        $rows = (new CsvTransactionParser())->parse($this->uploadedFile(<<<'CSV'
date,symbol,type,quantity,price,currency,fees,amount
2026-02-12,nvda,buy,100,142.50,usd,1.00,not-a-number
CSV), $account);

        self::assertCount(1, $rows);
        self::assertFalse($rows[0]->isValid());
        self::assertArrayNotHasKey('brokerAmount', $rows[0]->data);
        self::assertContains('amount must be a decimal value.', $rows[0]->errors);
    }

    public function testCustomCsvRejectsNonUsdCurrency(): void
    {
        $account = (new BrokerAccount())
            ->setBrokerType('custom')
            ->setCurrency('EUR');

        $rows = (new CsvTransactionParser())->parse($this->uploadedFile(<<<'CSV'
date,symbol,type,quantity,price,currency,fees
2026-02-12,nvda,buy,100,142.50,eur,1.00
CSV), $account);

        self::assertCount(1, $rows);
        self::assertFalse($rows[0]->isValid());
        self::assertSame('EUR', $rows[0]->data['currency']);
        self::assertContains('custom CSV import supports USD currency only.', $rows[0]->errors);
    }

    public function testRevolutCsvMapsRowsToTransactionData(): void
    {
        $account = (new BrokerAccount())
            ->setBrokerType('revolut')
            ->setCurrency('USD');

        $rows = (new CsvTransactionParser())->parse($this->uploadedFile(<<<'CSV'
Date,Ticker,Type,Quantity,Price per share,Currency,Ignored
2021-10-14T10:28:43.503438Z,NVDA,BUY - MARKET,2,USD 50.56,USD,abc
2021-11-18T15:40:10.100000Z,AAPL,SELL - MARKET,1.25,USD 151.20,USD,xyz
CSV), $account);

        self::assertCount(2, $rows);
        self::assertTrue($rows[0]->isValid());
        self::assertSame([
            'date' => '2021-10-14',
            'transactionDate' => '2021-10-14 10:28:43',
            'symbol' => 'NVDA',
            'type' => 'BUY',
            'quantity' => '2.00000000',
            'price' => '50.56000000',
            'currency' => 'USD',
            'fees' => '0.00000000',
        ], $rows[0]->data);

        self::assertTrue($rows[1]->isValid());
        self::assertSame('AAPL', $rows[1]->data['symbol']);
        self::assertSame('SELL', $rows[1]->data['type']);
        self::assertSame('1.25000000', $rows[1]->data['quantity']);
        self::assertSame('151.20000000', $rows[1]->data['price']);
    }

    public function testRevolutCsvRejectsUnsupportedRows(): void
    {
        $account = (new BrokerAccount())
            ->setBrokerType('revolut')
            ->setCurrency('USD');

        $rows = (new CsvTransactionParser())->parse($this->uploadedFile(<<<'CSV'
Date,Ticker,Type,Quantity,Price per share,Currency
2021-10-14T10:28:43.503438Z,NVDA,DIVIDEND,2,USD 50.56,USD
2021-10-14T10:28:43.503438Z,,BUY - MARKET,2,USD 50.56,USD
2021-10-14T10:28:43.503438Z,AAPL,BUY - MARKET,2,EUR 50.56,USD
2021-10-14T10:28:43.503438Z,MSFT,BUY - MARKET,2,USD 50.56,EUR
CSV), $account);

        self::assertCount(4, $rows);
        self::assertFalse($rows[0]->isValid());
        self::assertContains('type must be BUY - MARKET, SELL - MARKET, or STOCK SPLIT.', $rows[0]->errors);

        self::assertFalse($rows[1]->isValid());
        self::assertContains('ticker is required.', $rows[1]->errors);

        self::assertFalse($rows[2]->isValid());
        self::assertContains('price per share currency must be USD.', $rows[2]->errors);

        self::assertFalse($rows[3]->isValid());
        self::assertContains('currency must be USD.', $rows[3]->errors);
    }

    public function testRevolutCsvMapsStockSplitRowsToCorporateActionTransactions(): void
    {
        $account = (new BrokerAccount())
            ->setBrokerType('revolut')
            ->setCurrency('USD');

        $rows = (new CsvTransactionParser())->parse($this->uploadedFile(<<<'CSV'
Date,Ticker,Type,Quantity,Price per share,Total Amount,Currency,FX Rate
2024-06-17T07:44:52.740377Z,SPCE,STOCK SPLIT,-5.70525065,,USD 0,USD,0.2156
CSV), $account);

        self::assertCount(1, $rows);
        self::assertTrue($rows[0]->isValid());
        self::assertSame([
            'date' => '2024-06-17',
            'transactionDate' => '2024-06-17 07:44:52',
            'symbol' => 'SPCE',
            'type' => 'STOCK_SPLIT',
            'quantity' => '-5.70525065',
            'price' => '0.00000000',
            'currency' => 'USD',
            'fees' => '0.00000000',
        ], $rows[0]->data);
    }

    private function uploadedFile(string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'csv-parser-');
        self::assertIsString($path);
        file_put_contents($path, $contents);

        return new UploadedFile($path, 'transactions.csv', 'text/csv', null, true);
    }
}
