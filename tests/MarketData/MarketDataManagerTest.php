<?php

declare(strict_types=1);

namespace App\Tests\MarketData;

use App\Dto\OhlcDto;
use App\Entity\Stock;
use App\Entity\StockPrice;
use App\Entity\StockQuote;
use App\Exception\MarketDataUnavailableException;
use App\MarketData\MarketDataManager;
use App\MarketData\MockMarketDataProvider;
use App\MarketData\TwelveDataProvider;
use App\MarketData\YahooProvider;
use App\Repository\StockPriceRepository;
use App\Repository\StockQuoteRepository;
use App\Service\MockPriceService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Scheb\YahooFinanceApi\ApiClient;
use Scheb\YahooFinanceApi\Results\HistoricalData;
use Scheb\YahooFinanceApi\Results\Quote;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[AllowMockObjectsWithoutExpectations]
final class MarketDataManagerTest extends TestCase
{
    public function testQuoteCacheHitReturnsCachedQuote(): void
    {
        $stock = $this->stock();
        $quote = (new StockQuote())
            ->setStock($stock)
            ->setPrice('147.50000000')
            ->setProvider('yahoo')
            ->setFetchedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        $quoteRepository = $this->createMock(StockQuoteRepository::class);
        $quoteRepository->expects($this->once())->method('findLatestForStock')->willReturn($quote);

        $result = $this->manager($quoteRepository)->getCurrentQuote($stock);

        self::assertSame('147.50000000', $result->price);
        self::assertSame('yahoo', $result->provider);
    }

    public function testQuoteCacheMissFetchesAndStoresQuote(): void
    {
        $stock = $this->stock();
        $quoteRepository = $this->createMock(StockQuoteRepository::class);
        $quoteRepository->method('findLatestForStock')->willReturn(null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist')->with(self::isInstanceOf(StockQuote::class));
        $entityManager->expects($this->once())->method('flush');

        $manager = $this->manager(
            $quoteRepository,
            entityManager: $entityManager,
            twelveDataClient: $this->twelveDataClient('{"symbol":"NVDA","currency":"USD","close":"147.5","change":"3.4","percent_change":"2.3594"}'),
        );

        $quote = $manager->getCurrentQuote($stock);

        self::assertSame('147.50000000', $quote->price);
        self::assertSame('3.40000000', $quote->change);
        self::assertSame('2.3594', $quote->changePercent);
        self::assertSame('twelvedata', $quote->provider);
    }

    public function testQuoteFallsBackToYahooWhenTwelveDataFails(): void
    {
        $stock = $this->stock();
        $quoteRepository = $this->createMock(StockQuoteRepository::class);
        $quoteRepository->method('findLatestForStock')->willReturn(null);

        $quote = $this->manager(
            $quoteRepository,
            twelveDataClient: $this->twelveDataClient('{"status":"error","message":"rate limit","code":429}'),
            apiClient: $this->apiClient(quote: new Quote([
                'symbol' => 'NVDA',
                'currency' => 'USD',
                'regularMarketPrice' => 148.5,
            ])),
        )->getCurrentQuote($stock);

        self::assertSame('148.50000000', $quote->price);
        self::assertSame('yahoo', $quote->provider);
    }

    public function testOhlcCacheHitReturnsCachedCandles(): void
    {
        $stock = $this->stock();
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $price = $this->stockPrice($stock, $today->format('Y-m-d'), '147.50000000');
        $priceRepository = $this->createMock(StockPriceRepository::class);
        $priceRepository->method('findLatestForStock')->willReturn($price);
        $priceRepository->expects($this->once())->method('findForStockBetween')->willReturn([$price]);

        $candles = $this->manager(priceRepository: $priceRepository)->getOhlc(
            $stock,
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-19'),
        );

        self::assertCount(1, $candles);
        self::assertSame('147.50000000', $candles[0]->close);
    }

    public function testOhlcCacheMissFetchesAndStoresCandles(): void
    {
        $stock = $this->stock();
        $price = $this->stockPrice($stock, '2026-06-19', '147.50000000');
        $priceRepository = $this->createMock(StockPriceRepository::class);
        $priceRepository->method('findLatestForStock')->willReturn(null);
        $priceRepository->expects($this->once())
            ->method('findForStockBetween')
            ->willReturn([$price]);
        $priceRepository->method('findOneForStockAndDate')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->exactly(2))->method('persist')->with(self::isInstanceOf(StockPrice::class));
        $entityManager->expects($this->once())->method('flush');

        $candles = $this->manager(
            priceRepository: $priceRepository,
            entityManager: $entityManager,
            twelveDataClient: $this->twelveDataClient('{"values":[{"datetime":"2026-06-18","open":"142.5","high":"145","low":"141.25","close":"144.1","volume":"123456"},{"datetime":"2026-06-19","open":"144.1","high":"148.25","low":"143","close":"147.5","volume":"234567"}],"status":"ok"}'),
        )->getOhlc($stock, new \DateTimeImmutable('2026-06-01'), new \DateTimeImmutable('2026-06-19'));

        self::assertCount(1, $candles);
        self::assertSame('147.50000000', $candles[0]->close);
    }

    public function testOhlcFetchesIncrementallyFromLatestSavedDay(): void
    {
        $stock = $this->stock();
        $latest = $this->stockPrice($stock, '2026-06-18', '144.10000000');
        $price = $this->stockPrice($stock, '2026-06-22', '150.00000000');
        $priceRepository = $this->createMock(StockPriceRepository::class);
        $priceRepository->method('findLatestForStock')->willReturn($latest);
        $priceRepository->method('findOneForStockAndDate')->willReturn(null);
        $priceRepository->expects($this->once())
            ->method('findForStockBetween')
            ->willReturn([$latest, $price]);

        $capturedUrl = null;
        $client = new MockHttpClient(function (string $method, string $url) use (&$capturedUrl): MockResponse {
            $capturedUrl = $url;

            return new MockResponse('{"values":[{"datetime":"2026-06-18","open":"144.1","high":"145","low":"143","close":"144.1"},{"datetime":"2026-06-22","open":"149","high":"151","low":"148","close":"150"}],"status":"ok"}');
        });

        $candles = $this->manager(
            priceRepository: $priceRepository,
            twelveDataClient: $client,
        )->getOhlc($stock, new \DateTimeImmutable('2026-06-01'), new \DateTimeImmutable('2026-06-22'));

        self::assertCount(2, $candles);
        self::assertIsString($capturedUrl);
        parse_str(parse_url($capturedUrl, PHP_URL_QUERY) ?: '', $query);
        self::assertSame('2026-06-18', $query['start_date'] ?? null);
    }

    public function testOhlcFallsBackToYahooWhenTwelveDataFails(): void
    {
        $stock = $this->stock();
        $price = $this->stockPrice($stock, '2026-06-19', '147.50000000');
        $priceRepository = $this->createMock(StockPriceRepository::class);
        $priceRepository->method('findLatestForStock')->willReturn(null);
        $priceRepository->method('findOneForStockAndDate')->willReturn(null);
        $priceRepository->expects($this->once())
            ->method('findForStockBetween')
            ->willReturn([$price]);

        $candles = $this->manager(
            priceRepository: $priceRepository,
            twelveDataClient: $this->twelveDataClient('{"status":"error","message":"rate limit","code":429}'),
            apiClient: $this->apiClient(candles: [
                new HistoricalData(new \DateTime('2026-06-19', new \DateTimeZone('UTC')), 144.1, 148.25, 143.0, 147.5, 147.5, 234567),
            ]),
        )->getOhlc($stock, new \DateTimeImmutable('2026-06-01'), new \DateTimeImmutable('2026-06-19'));

        self::assertCount(1, $candles);
        self::assertSame('147.50000000', $candles[0]->close);
    }

    public function testWeeklyAggregationUsesIsoWeeks(): void
    {
        $manager = $this->manager();
        $weekly = $manager->aggregateWeekly([
            new OhlcDto('NVDA', new \DateTimeImmutable('2026-06-15'), '100.00000000', '110.00000000', '98.00000000', '108.00000000', 10, 'yahoo'),
            new OhlcDto('NVDA', new \DateTimeImmutable('2026-06-16'), '108.00000000', '115.00000000', '105.00000000', '112.00000000', 20, 'yahoo'),
            new OhlcDto('NVDA', new \DateTimeImmutable('2026-06-17'), '112.00000000', '118.00000000', '111.00000000', '117.00000000', 30, 'yahoo'),
        ]);

        self::assertCount(1, $weekly);
        self::assertSame('100.00000000', $weekly[0]->open);
        self::assertSame('118.00000000', $weekly[0]->high);
        self::assertSame('98.00000000', $weekly[0]->low);
        self::assertSame('117.00000000', $weekly[0]->close);
        self::assertSame(60, $weekly[0]->volume);
    }

    public function testProductionDoesNotUseMockProvider(): void
    {
        $this->expectException(MarketDataUnavailableException::class);

        $this->manager(environment: 'prod', allowMock: true)->getCurrentQuote(
            (new Stock())->setSymbol('BMW')->setCurrency('EUR'),
        );
    }

    public function testDevelopmentMayUseMockProvider(): void
    {
        $quote = $this->manager(environment: 'dev', allowMock: true)->getCurrentQuote(
            (new Stock())->setSymbol('BMW')->setCurrency('EUR'),
        );

        self::assertSame('mock', $quote->provider);
    }

    private function manager(
        ?StockQuoteRepository $quoteRepository = null,
        ?StockPriceRepository $priceRepository = null,
        ?EntityManagerInterface $entityManager = null,
        ?MockHttpClient $twelveDataClient = null,
        ?ApiClient $apiClient = null,
        string $environment = 'test',
        bool $allowMock = false,
    ): MarketDataManager {
        return new MarketDataManager(
            $entityManager ?? $this->createMock(EntityManagerInterface::class),
            $quoteRepository ?? $this->createMock(StockQuoteRepository::class),
            $priceRepository ?? $this->createMock(StockPriceRepository::class),
            new TwelveDataProvider(
                $twelveDataClient ?? $this->twelveDataClient('{"status":"error","message":"no data","code":400}'),
                'test-key',
                'https://twelvedata.test',
            ),
            new YahooProvider($apiClient ?? $this->apiClient()),
            new MockMarketDataProvider(new MockPriceService()),
            new NullLogger(),
            $environment,
            60,
            $allowMock,
        );
    }

    private function stock(): Stock
    {
        return (new Stock())->setSymbol('NVDA')->setCurrency('USD');
    }

    private function stockPrice(Stock $stock, string $date, string $close): StockPrice
    {
        return (new StockPrice())
            ->setStock($stock)
            ->setDate(new \DateTimeImmutable($date))
            ->setOpen('100.00000000')
            ->setHigh('150.00000000')
            ->setLow('90.00000000')
            ->setClose($close)
            ->setProvider('yahoo')
            ->setFetchedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    /**
     * @param list<HistoricalData> $candles
     */
    private function apiClient(?Quote $quote = null, array $candles = []): ApiClient
    {
        $client = $this->createMock(ApiClient::class);
        $client->method('getQuote')->willReturn($quote);
        $client->method('getHistoricalQuoteData')->willReturn($candles);

        return $client;
    }

    private function twelveDataClient(string $body): MockHttpClient
    {
        return new MockHttpClient([new MockResponse($body)]);
    }
}
