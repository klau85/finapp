<?php

declare(strict_types=1);

namespace App\MarketData;

use App\Dto\OhlcDto;
use App\Dto\QuoteDto;
use App\Entity\Stock;
use App\Exception\MarketDataProviderException;
use App\Service\DecimalMath;
use Scheb\YahooFinanceApi\ApiClient;
use Scheb\YahooFinanceApi\Results\HistoricalData;
use Scheb\YahooFinanceApi\Results\Quote;

final readonly class YahooProvider implements MarketDataProviderInterface, BatchQuoteProviderInterface
{
    private const PROVIDER = 'yahoo';

    public function __construct(private ApiClient $apiClient)
    {
    }

    public function supports(Stock $stock): bool
    {
        return trim($stock->getSymbol()) !== '';
    }

    public function getCurrentQuote(Stock $stock): QuoteDto
    {
        $this->assertSupported($stock);

        try {
            $quote = $this->apiClient->getQuote($this->yahooSymbol($stock));
        } catch (\Throwable $exception) {
            throw new MarketDataProviderException('Yahoo Finance quote request failed.', previous: $exception);
        }

        if ($quote === null || $quote->getRegularMarketPrice() === null || $quote->getRegularMarketPrice() <= 0.0) {
            throw new MarketDataProviderException('Yahoo Finance returned no quote data for this symbol.');
        }

        return $this->quoteDto($stock, $quote);
    }

    /**
     * @param list<Stock> $stocks
     * @return array<string, QuoteDto>
     */
    public function getCurrentQuotes(array $stocks): array
    {
        $stocksByYahooSymbol = [];
        foreach ($stocks as $stock) {
            $this->assertSupported($stock);
            $stocksByYahooSymbol[$this->yahooSymbol($stock)] = $stock;
        }

        if ($stocksByYahooSymbol === []) {
            return [];
        }

        try {
            $quotes = $this->apiClient->getQuotes(array_keys($stocksByYahooSymbol));
        } catch (\Throwable $exception) {
            throw new MarketDataProviderException('Yahoo Finance batch quote request failed.', previous: $exception);
        }

        $result = [];
        foreach ($quotes as $quote) {
            if (!$quote instanceof Quote || $quote->getSymbol() === null) {
                continue;
            }

            $stock = $stocksByYahooSymbol[strtoupper($quote->getSymbol())] ?? null;
            if ($stock === null || $quote->getRegularMarketPrice() === null || $quote->getRegularMarketPrice() <= 0.0) {
                continue;
            }

            $result[$stock->getSymbol()] = $this->quoteDto($stock, $quote);
        }

        if ($result === []) {
            throw new MarketDataProviderException('Yahoo Finance returned no quote data for these symbols.');
        }

        return $result;
    }

    /**
     * @return list<OhlcDto>
     */
    public function getDailyOhlc(Stock $stock, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $this->assertSupported($stock);

        try {
            $rows = $this->apiClient->getHistoricalQuoteData(
                $this->yahooSymbol($stock),
                ApiClient::INTERVAL_1_DAY,
                \DateTimeImmutable::createFromInterface($from)->setTimezone(new \DateTimeZone('UTC')),
                \DateTimeImmutable::createFromInterface($to)->setTimezone(new \DateTimeZone('UTC')),
            );
        } catch (\Throwable $exception) {
            throw new MarketDataProviderException('Yahoo Finance OHLC request failed.', previous: $exception);
        }

        $candles = [];
        foreach ($rows as $row) {
            if (!$row instanceof HistoricalData) {
                throw new MarketDataProviderException('Yahoo Finance returned an invalid OHLC response.');
            }

            if ($row->getOpen() === null || $row->getHigh() === null || $row->getLow() === null || $row->getClose() === null) {
                continue;
            }

            $candles[] = new OhlcDto(
                $stock->getSymbol(),
                \DateTimeImmutable::createFromInterface($row->getDate())->setTimezone(new \DateTimeZone('UTC')),
                $this->decimal($row->getOpen()),
                $this->decimal($row->getHigh()),
                $this->decimal($row->getLow()),
                $this->decimal($row->getClose()),
                $row->getVolume(),
                self::PROVIDER,
            );
        }

        if ($candles === []) {
            throw new MarketDataProviderException('Yahoo Finance returned no candle data for this symbol and date range.');
        }

        usort($candles, static fn (OhlcDto $left, OhlcDto $right): int => $left->date <=> $right->date);

        return $candles;
    }

    private function assertSupported(Stock $stock): void
    {
        if (!$this->supports($stock)) {
            throw new MarketDataProviderException('Yahoo Finance does not support an empty symbol.');
        }
    }

    private function yahooSymbol(Stock $stock): string
    {
        $symbol = strtoupper(trim($stock->getSymbol()));

        if (str_ends_with($symbol, '.US')) {
            return substr($symbol, 0, -3);
        }

        if (str_ends_with($symbol, '.FR')) {
            return substr($symbol, 0, -3).'.PA';
        }

        return $symbol;
    }

    private function quoteDto(Stock $stock, Quote $quote): QuoteDto
    {
        $marketTime = $quote->getRegularMarketTime();
        $price = $quote->getRegularMarketPrice();
        \assert($price !== null);

        return new QuoteDto(
            $stock->getSymbol(),
            $this->decimal($price),
            $quote->getRegularMarketChange() !== null ? $this->decimal($quote->getRegularMarketChange()) : null,
            $quote->getRegularMarketChangePercent() !== null ? $this->decimal($quote->getRegularMarketChangePercent(), 4) : null,
            $quote->getCurrency() ?? $stock->getCurrency(),
            $marketTime !== null ? \DateTimeImmutable::createFromInterface($marketTime)->setTimezone(new \DateTimeZone('UTC')) : null,
            self::PROVIDER,
        );
    }

    private function decimal(float $value, int $scale = DecimalMath::SCALE): string
    {
        return number_format($value, $scale, '.', '');
    }
}
