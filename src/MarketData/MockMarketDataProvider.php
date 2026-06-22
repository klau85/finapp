<?php

declare(strict_types=1);

namespace App\MarketData;

use App\Dto\OhlcDto;
use App\Dto\QuoteDto;
use App\Entity\Stock;
use App\Service\DecimalMath;
use App\Service\MockPriceService;

final readonly class MockMarketDataProvider implements MarketDataProviderInterface
{
    public function __construct(private MockPriceService $mockPriceService)
    {
    }

    public function supports(Stock $stock): bool
    {
        return true;
    }

    public function getCurrentQuote(Stock $stock): QuoteDto
    {
        $prices = $this->mockPriceService->getCurrentPrices([$stock->getSymbol()]);

        return new QuoteDto(
            $stock->getSymbol(),
            DecimalMath::normalize($prices[$stock->getSymbol()] ?? '0'),
            null,
            null,
            $stock->getCurrency(),
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            'mock',
        );
    }

    public function getDailyOhlc(Stock $stock, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $dates = [];
        $cursor = \DateTimeImmutable::createFromInterface($from)->setTimezone(new \DateTimeZone('UTC'));
        $end = \DateTimeImmutable::createFromInterface($to)->setTimezone(new \DateTimeZone('UTC'));
        while ($cursor <= $end) {
            if ((int) $cursor->format('N') <= 5) {
                $dates[] = $cursor->format('Y-m-d');
            }
            $cursor = $cursor->modify('+1 day');
        }

        $candles = [];
        foreach ($this->mockPriceService->getHistoricalCandles($stock->getSymbol(), $dates) as $candle) {
            $candles[] = new OhlcDto(
                $stock->getSymbol(),
                new \DateTimeImmutable((string) $candle['time'], new \DateTimeZone('UTC')),
                DecimalMath::normalize((string) $candle['open']),
                DecimalMath::normalize((string) $candle['high']),
                DecimalMath::normalize((string) $candle['low']),
                DecimalMath::normalize((string) $candle['close']),
                null,
                'mock',
            );
        }

        return $candles;
    }
}
