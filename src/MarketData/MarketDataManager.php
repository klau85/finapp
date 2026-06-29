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
    private const MAX_BATCH_QUOTE_STOCKS = 5;

    /**
     * @var array<class-string<MarketDataProviderInterface>, true>
     */
    private array $apiProvidersUsedThisPage = [];

    private ?int $trackedRequestId = null;

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

        if (
            $cached !== null
            && $cached->getFetchedAt() >= $freshAfter
            && $this->hasResolvedCompanyName($stock)
        ) {
            return $this->quoteFromEntity($cached);
        }

        $providerException = null;
        foreach ($this->quoteProviders($stock) as $provider) {
            if (!$this->reserveApiRequest($stock, $provider, 'quote')) {
                continue;
            }

            try {
                $quote = $provider->getCurrentQuote($stock);
                $this->storeQuote($stock, $quote, $now);

                return $quote;
            } catch (MarketDataProviderException $exception) {
                $providerException = $exception;
                $this->logProviderFailure('quote', $stock, $provider, $exception, [
                    'cache_available' => $cached !== null,
                    'cached_fetched_at' => $cached?->getFetchedAt()->format(\DateTimeInterface::ATOM),
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
     * @param list<Stock> $stocks
     * @return array<string, QuoteDto> Quotes keyed by internal stock symbol.
     */
    public function getCurrentQuotes(array $stocks): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $freshAfter = $now->modify(sprintf('-%d minutes', $this->currentQuoteTtlMinutes($now)));
        $quotes = [];
        $pending = [];
        $staleCache = [];

        foreach ($this->uniqueStocksBySymbol($stocks) as $symbol => $stock) {
            $cached = $this->stockQuoteRepository->findLatestForStock($stock);
            if ($cached !== null && $cached->getFetchedAt() >= $freshAfter && $this->hasResolvedCompanyName($stock)) {
                $quotes[$symbol] = $this->quoteFromEntity($cached);
                continue;
            }

            $pending[$symbol] = $stock;
            if ($cached !== null) {
                $staleCache[$symbol] = $cached;
            }
        }

        if ($pending === []) {
            return $quotes;
        }

        $uncachedPending = array_diff_key($pending, $staleCache);
        $cachedPending = array_intersect_key($pending, $staleCache);
        $refreshCandidates = array_slice(
            $uncachedPending + $cachedPending,
            0,
            self::MAX_BATCH_QUOTE_STOCKS,
            true,
        );

        $yahooStocks = array_values(array_filter(
            $refreshCandidates,
            fn (Stock $stock): bool => $this->yahooProvider->supports($stock),
        ));

        if ($yahooStocks !== [] && $this->reserveApiRequest($yahooStocks[0], $this->yahooProvider, 'quote-batch')) {
            try {
                $batchQuotes = $this->yahooProvider->getCurrentQuotes($yahooStocks);
                $this->storeQuotes($this->stocksForQuotes($yahooStocks), $batchQuotes, $now);

                foreach ($batchQuotes as $symbol => $quote) {
                    $quotes[$symbol] = $quote;
                    unset($pending[$symbol]);
                }
            } catch (MarketDataProviderException $exception) {
                $this->logProviderFailure('quote-batch', $yahooStocks[0], $this->yahooProvider, $exception, [
                    'symbols' => array_map(static fn (Stock $stock): string => $stock->getSymbol(), $yahooStocks),
                    'cache_available' => $staleCache !== [],
                ]);
            }
        }

        $twelveDataStock = array_find(
            $refreshCandidates,
            fn (Stock $stock): bool => isset($pending[$stock->getSymbol()]) && $this->twelveDataProvider->supports($stock),
        );

        if ($twelveDataStock !== null && $this->reserveApiRequest($twelveDataStock, $this->twelveDataProvider, 'quote')) {
            try {
                $quote = $this->twelveDataProvider->getCurrentQuote($twelveDataStock);
                $this->storeQuote($twelveDataStock, $quote, $now);
                $quotes[$twelveDataStock->getSymbol()] = $quote;
                unset($pending[$twelveDataStock->getSymbol()]);
            } catch (MarketDataProviderException $exception) {
                $this->logProviderFailure('quote', $twelveDataStock, $this->twelveDataProvider, $exception, [
                    'cache_available' => isset($staleCache[$twelveDataStock->getSymbol()]),
                ]);
            }
        }

        foreach ($pending as $symbol => $stock) {
            if (isset($staleCache[$symbol])) {
                $quotes[$symbol] = $this->quoteFromEntity($staleCache[$symbol]);
                continue;
            }

            if ($this->canUseMockProvider() && $this->mockProvider->supports($stock)) {
                $quotes[$symbol] = $this->mockProvider->getCurrentQuote($stock);
            }
        }

        return $quotes;
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
                continue;
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
                $this->logProviderFailure('ohlc', $stock, $provider, $exception, [
                    'requested_from' => \DateTimeImmutable::createFromInterface($from)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'),
                    'requested_to' => \DateTimeImmutable::createFromInterface($to)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'),
                    'fetch_from' => \DateTimeImmutable::createFromInterface($fetchFrom)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'),
                    'latest_required_market_day' => $latestRequiredMarketDay->format('Y-m-d'),
                    'cache_available' => $latest !== null,
                    'latest_cached_date' => $latest?->getDate()->format('Y-m-d'),
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
        return $this->isUsMarketOpen($now) ? 30 : 120;
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

    /**
     * @param array<string, mixed> $context
     */
    private function logProviderFailure(
        string $operation,
        Stock $stock,
        MarketDataProviderInterface $provider,
        MarketDataProviderException $exception,
        array $context = [],
    ): void {
        $previous = $exception->getPrevious();
        $context += [
            'operation' => $operation,
            'symbol' => $stock->getSymbol(),
            'stock_currency' => $stock->getCurrency(),
            'stock_id' => $stock->getId(),
            'provider' => $provider::class,
            'provider_error' => $exception->getMessage(),
            'exception_class' => $exception::class,
            'previous_exception_class' => $previous !== null ? $previous::class : null,
            'previous_exception_message' => $previous !== null ? $previous->getMessage() : null,
        ];

        if ($this->isRateLimitFailure($exception)) {
            $this->logger->info('Stock market data API rate limit reached.', $context);

            return;
        }

        $this->logger->warning('Stock market data API request failed.', $context);
    }

    private function isRateLimitFailure(MarketDataProviderException $exception): bool
    {
        $messages = [$exception->getMessage()];
        if ($exception->getPrevious() !== null) {
            $messages[] = $exception->getPrevious()->getMessage();
        }

        foreach ($messages as $message) {
            if (str_contains($message, '429') || stripos($message, 'Too Many Requests') !== false || stripos($message, 'rate limit') !== false) {
                return true;
            }
        }

        return false;
    }

    private function reserveApiRequest(Stock $stock, MarketDataProviderInterface $provider, string $purpose): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return true;
        }

        $requestId = spl_object_id($request);
        if ($this->trackedRequestId !== $requestId) {
            $this->trackedRequestId = $requestId;
            $this->apiProvidersUsedThisPage = [];
        }

        $providerKey = $provider::class;
        if (isset($this->apiProvidersUsedThisPage[$providerKey])) {
            $this->logger->info('Market data API provider request limit reached for page render.', [
                'symbol' => $stock->getSymbol(),
                'provider' => $providerKey,
                'purpose' => $purpose,
            ]);

            return false;
        }

        $this->apiProvidersUsedThisPage[$providerKey] = true;

        return true;
    }

    private function storeQuote(Stock $stock, QuoteDto $quote, \DateTimeImmutable $now): void
    {
        $this->updateCompanyName($stock, $quote);

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
     * @param array<string, Stock> $stocksBySymbol
     * @param array<string, QuoteDto> $quotes
     */
    private function storeQuotes(array $stocksBySymbol, array $quotes, \DateTimeImmutable $now): void
    {
        foreach ($quotes as $symbol => $quote) {
            $stock = $stocksBySymbol[$symbol] ?? null;
            if ($stock === null) {
                continue;
            }

            $this->updateCompanyName($stock, $quote);

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
        }

        $this->entityManager->flush();
    }

    /**
     * @param list<Stock> $stocks
     * @return array<string, Stock>
     */
    private function stocksForQuotes(array $stocks): array
    {
        $stocksBySymbol = [];
        foreach ($stocks as $stock) {
            $stocksBySymbol[$stock->getSymbol()] = $stock;
        }

        return $stocksBySymbol;
    }

    /**
     * @param list<Stock> $stocks
     * @return array<string, Stock>
     */
    private function uniqueStocksBySymbol(array $stocks): array
    {
        $unique = [];
        foreach ($stocks as $stock) {
            $symbol = $stock->getSymbol();
            if ($symbol === '') {
                continue;
            }

            $unique[$symbol] ??= $stock;
        }

        return $unique;
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
            $stock->getCompanyName(),
        );
    }

    private function updateCompanyName(Stock $stock, QuoteDto $quote): void
    {
        $companyName = trim($quote->companyName ?? '');
        if ($companyName !== '' && !$this->hasResolvedCompanyName($stock)) {
            $stock->setCompanyName($companyName);
        }
    }

    private function hasResolvedCompanyName(Stock $stock): bool
    {
        $companyName = trim($stock->getCompanyName() ?? '');

        return $companyName !== '' && strcasecmp($companyName, $stock->getSymbol()) !== 0;
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
