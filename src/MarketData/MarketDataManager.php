<?php

declare(strict_types=1);

namespace App\MarketData;

use App\Dto\OhlcDto;
use App\Dto\QuoteDto;
use App\Entity\Stock;
use App\Entity\StockPrice;
use App\Entity\StockQuote;
use App\Exception\MarketDataProviderException;
use App\Exception\MarketDataUnavailableException;
use App\Repository\StockPriceRepository;
use App\Repository\StockQuoteRepository;
use App\Service\DecimalMath;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

final class MarketDataManager
{
    private const MAX_API_REQUESTS_PER_PAGE = 5;

    private int $apiRequestsThisPage = 0;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StockQuoteRepository $stockQuoteRepository,
        private readonly StockPriceRepository $stockPriceRepository,
        private readonly TwelveDataProvider $twelveDataProvider,
        private readonly YahooProvider $yahooProvider,
        private readonly MockMarketDataProvider $mockProvider,
        private readonly LoggerInterface $logger,
        private readonly RequestStack $requestStack,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        #[Autowire('%env(bool:MARKET_ALLOW_MOCK_PROVIDER)%')]
        private readonly bool $allowMockProvider,
    ) {
    }

    public function getCurrentQuote(Stock $stock): QuoteDto
    {
        $cached = $this->stockQuoteRepository->findLatestForStock($stock);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $freshAfter = $now->modify(sprintf('-%d minutes', $this->currentQuoteTtlMinutes($now)));

        if ($cached !== null && $cached->getFetchedAt() >= $freshAfter) {
            return $this->quoteFromEntity($cached);
        }

        $providerException = null;
        foreach ($this->quoteProviders($stock) as $provider) {
            if (!$this->reserveApiRequest($stock, $provider, 'quote')) {
                break;
            }

            try {
                $quote = $provider->getCurrentQuote($stock);
                $this->storeQuote($stock, $quote, $now);

                return $quote;
            } catch (MarketDataProviderException $exception) {
                $providerException = $exception;
                $this->logger->warning('Market quote provider failed.', [
                    'symbol' => $stock->getSymbol(),
                    'provider' => $provider::class,
                    'provider_error' => $exception->getMessage(),
                ]);
            }
        }

        if ($cached !== null) {
            return $this->quoteFromEntity($cached);
        }

        if ($this->canUseMockProvider() && $this->mockProvider->supports($stock)) {
            return $this->mockProvider->getCurrentQuote($stock);
        }

        throw new MarketDataUnavailableException('Market data is unavailable at this moment.', previous: $providerException);
    }

    /**
     * @return list<OhlcDto>
     */
    public function getOhlc(
        Stock $stock,
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        string $timeframe = 'daily',
    ): array {
        if (!in_array($timeframe, ['daily', 'weekly'], true)) {
            throw new \InvalidArgumentException('Unsupported market data timeframe.');
        }

        $daily = $this->getDailyOhlc($stock, $from, $to);

        return $timeframe === 'weekly' ? $this->aggregateWeekly($daily) : $daily;
    }

    /**
     * @return list<OhlcDto>
     */
    private function getDailyOhlc(Stock $stock, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $latestRequiredMarketDay = $this->latestRequiredOhlcDate($now);
        $latest = $this->stockPriceRepository->findLatestForStock($stock);

        if ($latest !== null && $latest->getDate() >= $latestRequiredMarketDay) {
            $cached = $this->stockPriceRepository->findForStockBetween($stock, $from, $to);

            return array_map($this->ohlcFromEntity(...), $cached);
        }

        $fetchFrom = $latest !== null ? $latest->getDate() : $from;
        $providerException = null;
        foreach ($this->ohlcProviders($stock) as $provider) {
            if (!$this->reserveApiRequest($stock, $provider, 'ohlc')) {
                break;
            }

            try {
                $candles = $provider->getDailyOhlc($stock, $fetchFrom, $latestRequiredMarketDay);
                $this->storeCandles($stock, $candles, $now);

                return array_map(
                    $this->ohlcFromEntity(...),
                    $this->stockPriceRepository->findForStockBetween($stock, $from, $to),
                );
            } catch (MarketDataProviderException $exception) {
                $providerException = $exception;
                $this->logger->warning('Market OHLC provider failed.', [
                    'symbol' => $stock->getSymbol(),
                    'provider' => $provider::class,
                    'provider_error' => $exception->getMessage(),
                ]);
            }
        }

        $cached = $this->stockPriceRepository->findForStockBetween($stock, $from, $to);
        if ($cached !== []) {
            return array_map($this->ohlcFromEntity(...), $cached);
        }

        if ($this->canUseMockProvider() && $this->mockProvider->supports($stock)) {
            return $this->mockProvider->getDailyOhlc($stock, $from, $to);
        }

        throw new MarketDataUnavailableException('Chart data is unavailable at this moment.', previous: $providerException);
    }

    /**
     * @param list<OhlcDto> $dailyCandles
     * @return list<OhlcDto>
     */
    public function aggregateWeekly(array $dailyCandles): array
    {
        $weeks = [];

        foreach ($dailyCandles as $candle) {
            $weekKey = $candle->date->format('o-\WW');
            $weeks[$weekKey] ??= [
                'symbol' => $candle->symbol,
                'date' => $candle->date,
                'open' => $candle->open,
                'high' => $candle->high,
                'low' => $candle->low,
                'close' => $candle->close,
                'volume' => 0,
                'hasVolume' => false,
                'provider' => $candle->provider,
            ];

            if (DecimalMath::cmp($candle->high, $weeks[$weekKey]['high']) > 0) {
                $weeks[$weekKey]['high'] = $candle->high;
            }
            if (DecimalMath::cmp($candle->low, $weeks[$weekKey]['low']) < 0) {
                $weeks[$weekKey]['low'] = $candle->low;
            }

            $weeks[$weekKey]['close'] = $candle->close;
            if ($candle->volume !== null) {
                $weeks[$weekKey]['hasVolume'] = true;
                $weeks[$weekKey]['volume'] += $candle->volume;
            }
        }

        return array_map(
            static fn (array $week): OhlcDto => new OhlcDto(
                (string) $week['symbol'],
                $week['date'],
                (string) $week['open'],
                (string) $week['high'],
                (string) $week['low'],
                (string) $week['close'],
                $week['hasVolume'] ? (int) $week['volume'] : null,
                (string) $week['provider'],
            ),
            array_values($weeks),
        );
    }

    private function currentQuoteTtlMinutes(\DateTimeImmutable $now): int
    {
        return $this->isUsMarketOpen($now) ? 15 : 60;
    }

    private function isUsMarketOpen(\DateTimeImmutable $now): bool
    {
        $marketTime = $now->setTimezone(new \DateTimeZone('America/New_York'));
        $dayOfWeek = (int) $marketTime->format('N');
        if ($dayOfWeek >= 6) {
            return false;
        }

        $minutes = ((int) $marketTime->format('H')) * 60 + (int) $marketTime->format('i');

        return $minutes >= (9 * 60 + 30) && $minutes < (16 * 60);
    }

    private function latestRequiredOhlcDate(\DateTimeImmutable $now): \DateTimeImmutable
    {
        $marketTime = $now->setTimezone(new \DateTimeZone('America/New_York'));
        $dayOfWeek = (int) $marketTime->format('N');
        $minutes = ((int) $marketTime->format('H')) * 60 + (int) $marketTime->format('i');

        if ($dayOfWeek === 6) {
            $marketTime = $marketTime->modify('-1 day');
        } elseif ($dayOfWeek === 7) {
            $marketTime = $marketTime->modify('-2 days');
        } elseif ($minutes < (9 * 60 + 30)) {
            $marketTime = $this->previousMarketWorkingDay($marketTime);
        }

        return $marketTime->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);
    }

    private function previousMarketWorkingDay(\DateTimeImmutable $marketTime): \DateTimeImmutable
    {
        do {
            $marketTime = $marketTime->modify('-1 day');
        } while ((int) $marketTime->format('N') >= 6);

        return $marketTime;
    }

    /**
     * @return list<MarketDataProviderInterface>
     */
    private function quoteProviders(Stock $stock): array
    {
        $providers = [];

        if ($this->yahooProvider->supports($stock)) {
            $providers[] = $this->yahooProvider;
        }

        if ($this->twelveDataProvider->supports($stock)) {
            $providers[] = $this->twelveDataProvider;
        }

        if ($providers === []) {
            throw new MarketDataProviderException(sprintf('No real market data provider supports %s.', $stock->getSymbol()));
        }

        return $providers;
    }

    /**
     * @return list<MarketDataProviderInterface>
     */
    private function ohlcProviders(Stock $stock): array
    {
        $providers = [];

        if ($this->twelveDataProvider->supports($stock)) {
            $providers[] = $this->twelveDataProvider;
        }

        if ($this->yahooProvider->supports($stock)) {
            $providers[] = $this->yahooProvider;
        }

        if ($providers === []) {
            throw new MarketDataProviderException(sprintf('No real market data provider supports %s.', $stock->getSymbol()));
        }

        return $providers;
    }

    private function canUseMockProvider(): bool
    {
        return $this->environment !== 'prod' && $this->allowMockProvider;
    }

    private function reserveApiRequest(Stock $stock, MarketDataProviderInterface $provider, string $purpose): bool
    {
        if ($this->requestStack->getCurrentRequest() === null) {
            return true;
        }

        if ($this->apiRequestsThisPage >= self::MAX_API_REQUESTS_PER_PAGE) {
            $this->logger->info('Market data API request limit reached for page render.', [
                'symbol' => $stock->getSymbol(),
                'provider' => $provider::class,
                'purpose' => $purpose,
                'limit' => self::MAX_API_REQUESTS_PER_PAGE,
            ]);

            return false;
        }

        ++$this->apiRequestsThisPage;

        return true;
    }

    private function storeQuote(Stock $stock, QuoteDto $quote, \DateTimeImmutable $now): void
    {
        $entity = (new StockQuote())
            ->setStock($stock)
            ->setPrice($quote->price)
            ->setChangeAmount($quote->change)
            ->setChangePercent($quote->changePercent)
            ->setCurrency($quote->currency)
            ->setMarketTime($quote->marketTime)
            ->setProvider($quote->provider)
            ->setFetchedAt($now)
            ->setUpdatedAt($now);

        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    /**
     * @param list<OhlcDto> $candles
     */
    private function storeCandles(Stock $stock, array $candles, \DateTimeImmutable $now): void
    {
        foreach ($candles as $candle) {
            $entity = $this->stockPriceRepository->findOneForStockAndDate($stock, $candle->date) ?? new StockPrice();
            $entity
                ->setStock($stock)
                ->setDate($candle->date)
                ->setOpen($candle->open)
                ->setHigh($candle->high)
                ->setLow($candle->low)
                ->setClose($candle->close)
                ->setVolume($candle->volume)
                ->setProvider($candle->provider)
                ->setFetchedAt($now)
                ->setUpdatedAt($now);

            $this->entityManager->persist($entity);
        }

        $this->entityManager->flush();
    }

    private function quoteFromEntity(StockQuote $quote): QuoteDto
    {
        $stock = $quote->getStock();
        \assert($stock !== null);

        return new QuoteDto(
            $stock->getSymbol(),
            $quote->getPrice(),
            $quote->getChangeAmount(),
            $quote->getChangePercent(),
            $quote->getCurrency(),
            $quote->getMarketTime(),
            $quote->getProvider(),
        );
    }

    private function ohlcFromEntity(StockPrice $price): OhlcDto
    {
        $stock = $price->getStock();
        \assert($stock !== null);

        return new OhlcDto(
            $stock->getSymbol(),
            $price->getDate(),
            $price->getOpen(),
            $price->getHigh(),
            $price->getLow(),
            $price->getClose(),
            $price->getVolume(),
            $price->getProvider(),
        );
    }
}
