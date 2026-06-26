<?php

declare(strict_types=1);

namespace App\Tests\MarketData;

use App\Entity\Stock;
use App\Exception\MarketDataProviderException;
use App\MarketData\YahooProvider;
use PHPUnit\Framework\TestCase;
use Scheb\YahooFinanceApi\ApiClient;
use Scheb\YahooFinanceApi\Results\HistoricalData;
use Scheb\YahooFinanceApi\Results\Quote;

final class YahooProviderTest extends TestCase
{
    public function testCurrentQuoteUsesYahooQuoteData(): void
    {
        $client = $this->createMock(ApiClient::class);
        $client->expects($this->once())
            ->method('getQuote')
            ->with('NVDA')
            ->willReturn(new Quote([
                'symbol' => 'NVDA',
                'currency' => 'USD',
                'regularMarketPrice' => 147.5,
                'regularMarketChange' => 3.4,
                'regularMarketChangePercent' => 2.3594,
                'regularMarketTime' => new \DateTimeImmutable('2026-06-19 20:00:00', new \DateTimeZone('UTC')),
            ]));

        $quote = (new YahooProvider($client))->getCurrentQuote($this->stock());

        self::assertSame('147.50000000', $quote->price);
        self::assertSame('3.40000000', $quote->change);
        self::assertSame('2.3594', $quote->changePercent);
        self::assertSame('USD', $quote->currency);
        self::assertSame('yahoo', $quote->provider);
    }

    public function testCurrentQuotesUsesYahooBatchQuoteData(): void
    {
        $client = $this->createMock(ApiClient::class);
        $client->expects($this->once())
            ->method('getQuotes')
            ->with(['NVDA', 'MSFT'])
            ->willReturn([
                new Quote([
                    'symbol' => 'NVDA',
                    'currency' => 'USD',
                    'regularMarketPrice' => 147.5,
                ]),
                new Quote([
                    'symbol' => 'MSFT',
                    'currency' => 'USD',
                    'regularMarketPrice' => 510.25,
                ]),
            ]);

        $quotes = (new YahooProvider($client))->getCurrentQuotes([
            (new Stock())->setSymbol('NVDA')->setCurrency('USD'),
            (new Stock())->setSymbol('MSFT')->setCurrency('USD'),
        ]);

        self::assertSame(['NVDA', 'MSFT'], array_keys($quotes));
        self::assertSame('147.50000000', $quotes['NVDA']->price);
        self::assertSame('510.25000000', $quotes['MSFT']->price);
        self::assertSame('yahoo', $quotes['MSFT']->provider);
    }

    public function testDailyOhlcUsesYahooHistoricalData(): void
    {
        $from = new \DateTimeImmutable('2024-01-01', new \DateTimeZone('UTC'));
        $to = new \DateTimeImmutable('2024-01-03', new \DateTimeZone('UTC'));
        $client = $this->createMock(ApiClient::class);
        $client->expects($this->once())
            ->method('getHistoricalQuoteData')
            ->with('NVDA', ApiClient::INTERVAL_1_DAY, $from, $to)
            ->willReturn([
                new HistoricalData(new \DateTime('2024-01-02', new \DateTimeZone('UTC')), 20.0, 21.0, 19.0, 20.5, 20.5, 200),
            ]);

        $candles = (new YahooProvider($client))->getDailyOhlc($this->stock(), $from, $to);

        self::assertCount(1, $candles);
        self::assertSame('2024-01-02', $candles[0]->date->format('Y-m-d'));
        self::assertSame('20.50000000', $candles[0]->close);
        self::assertSame('yahoo', $candles[0]->provider);
    }

    public function testProviderRequiresSymbol(): void
    {
        $this->expectException(MarketDataProviderException::class);

        (new YahooProvider($this->createStub(ApiClient::class)))->getCurrentQuote(new Stock());
    }

    private function stock(): Stock
    {
        return (new Stock())->setSymbol('NVDA')->setCurrency('USD');
    }
}
