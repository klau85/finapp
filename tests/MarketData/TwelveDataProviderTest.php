<?php

declare(strict_types=1);

namespace App\Tests\MarketData;

use App\Entity\Stock;
use App\Exception\MarketDataProviderException;
use App\MarketData\TwelveDataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TwelveDataProviderTest extends TestCase
{
    public function testCurrentQuoteUsesTwelveDataQuoteEndpoint(): void
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            self::assertSame('GET', $method);
            $parts = parse_url($url);
            parse_str($parts['query'] ?? '', $query);
            self::assertSame('/quote', $parts['path'] ?? null);
            self::assertSame('NVDA', $query['symbol'] ?? null);
            self::assertSame('test-key', $query['apikey'] ?? null);

            return new MockResponse('{"symbol":"NVDA","currency":"USD","close":"147.50","change":"3.40","percent_change":"2.3594","timestamp":1781827200}');
        });

        $quote = $this->provider($client)->getCurrentQuote($this->stock());

        self::assertSame('147.50000000', $quote->price);
        self::assertSame('3.40000000', $quote->change);
        self::assertSame('2.3594', $quote->changePercent);
        self::assertSame('USD', $quote->currency);
        self::assertSame('twelvedata', $quote->provider);
    }

    public function testDailyOhlcUsesTwelveDataTimeSeriesEndpoint(): void
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            self::assertSame('GET', $method);
            $parts = parse_url($url);
            parse_str($parts['query'] ?? '', $query);
            self::assertSame('/time_series', $parts['path'] ?? null);
            self::assertSame('NVDA', $query['symbol'] ?? null);
            self::assertSame('1day', $query['interval'] ?? null);
            self::assertSame('2024-01-01', $query['start_date'] ?? null);
            self::assertSame('2024-01-03', $query['end_date'] ?? null);
            self::assertSame('ASC', $query['order'] ?? null);

            return new MockResponse('{"values":[{"datetime":"2024-01-02","open":"20","high":"21","low":"19","close":"20.5","volume":"200"}],"status":"ok"}');
        });

        $candles = $this->provider($client)->getDailyOhlc(
            $this->stock(),
            new \DateTimeImmutable('2024-01-01', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('2024-01-03', new \DateTimeZone('UTC')),
        );

        self::assertCount(1, $candles);
        self::assertSame('2024-01-02', $candles[0]->date->format('Y-m-d'));
        self::assertSame('20.50000000', $candles[0]->close);
        self::assertSame('twelvedata', $candles[0]->provider);
    }

    public function testProviderRequiresApiKey(): void
    {
        $this->expectException(MarketDataProviderException::class);

        $this->provider(new MockHttpClient(), apiKey: '')->getCurrentQuote($this->stock());
    }

    private function provider(MockHttpClient $client, string $apiKey = 'test-key'): TwelveDataProvider
    {
        return new TwelveDataProvider($client, $apiKey, 'https://twelvedata.test');
    }

    private function stock(): Stock
    {
        return (new Stock())->setSymbol('NVDA')->setCurrency('USD');
    }
}
