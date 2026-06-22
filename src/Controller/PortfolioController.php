<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\MarketDataUnavailableException;
use App\MarketData\MarketDataManager;
use App\MarketData\StockChartMarkerFactory;
use App\Repository\RealizedTradeRepository;
use App\Repository\StockRepository;
use App\Repository\TransactionRepository;
use App\Service\DecimalMath;
use App\Service\PortfolioAnalyticsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PortfolioController extends AbstractController
{
    #[Route('/portfolio', name: 'app_portfolio')]
    public function index(
        PortfolioAnalyticsService $portfolioAnalytics,
        MarketDataManager $marketDataManager,
        StockRepository $stockRepository,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        $positionsWithoutPrices = $portfolioAnalytics->getOpenPositionSummaries($user);
        [$currentPrices, $unavailableSymbols] = $this->loadCurrentPrices(
            array_map(static fn (array $position): string => $position['symbol'], $positionsWithoutPrices),
            $stockRepository,
            $marketDataManager,
        );
        $positions = $portfolioAnalytics->getAggregatedPortfolio($user, $currentPrices);
        $positions = $this->markMarketAvailability($positions, $unavailableSymbols);
        $marketDataAvailable = $unavailableSymbols === [];

        return $this->render('portfolio/index.html.twig', [
            'positions' => $positions,
            'marketDataAvailable' => $marketDataAvailable,
            'exposure' => $this->buildExposure($positions),
            'totals' => [
                'marketValue' => $marketDataAvailable ? array_reduce(
                    $positions,
                    static fn (string $carry, array $position): string => DecimalMath::add($carry, $position['marketValue']),
                    DecimalMath::zero()
                ) : null,
                'realizedGain' => array_reduce(
                    $positions,
                    static fn (string $carry, array $position): string => DecimalMath::add($carry, $position['realizedGain']),
                    DecimalMath::zero()
                ),
                'unrealizedGain' => $marketDataAvailable ? array_reduce(
                    $positions,
                    static fn (string $carry, array $position): string => DecimalMath::add($carry, $position['unrealizedGain']),
                    DecimalMath::zero()
                ) : null,
                'totalGain' => $marketDataAvailable ? array_reduce(
                    $positions,
                    static fn (string $carry, array $position): string => DecimalMath::add($carry, $position['totalGain']),
                    DecimalMath::zero()
                ) : null,
            ],
        ]);
    }

    #[Route('/stocks/{symbol}', name: 'app_stock_show')]
    public function stock(
        string $symbol,
        Request $request,
        TransactionRepository $transactionRepository,
        RealizedTradeRepository $realizedTradeRepository,
        PortfolioAnalyticsService $portfolioAnalytics,
        MarketDataManager $marketDataManager,
        StockChartMarkerFactory $markerFactory,
    ): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        $symbol = strtoupper($symbol);
        $timeframe = $request->query->get('timeframe', 'daily');
        $timeframe = in_array($timeframe, ['daily', 'weekly'], true) ? $timeframe : 'daily';
        $transactions = $transactionRepository->findForUserAndSymbol($user, $symbol);

        if ($transactions === []) {
            throw $this->createNotFoundException('No transactions found for this stock.');
        }

        $stock = $transactions[0]->getStock();
        \assert($stock !== null);
        $currentPrices = [];
        $marketDataAvailable = true;
        try {
            $currentPrices[$symbol] = $marketDataManager->getCurrentQuote($stock)->price;
        } catch (MarketDataUnavailableException) {
            $marketDataAvailable = false;
        }

        $portfolioRows = $portfolioAnalytics->getAggregatedPortfolio($user, $currentPrices);
        $position = null;
        foreach ($portfolioRows as $row) {
            if ($row['symbol'] === $symbol) {
                $position = $row;
                break;
            }
        }

        if ($position === null) {
            $currency = $stock?->getCurrency() ?? 'USD';
            $position = [
                'symbol' => $symbol,
                'companyName' => $stock?->getCompanyName(),
                'currency' => $currency,
                'totalShares' => DecimalMath::zero(),
                'averageCost' => DecimalMath::zero(),
                'currentPrice' => $currentPrices[$symbol] ?? null,
                'marketValue' => DecimalMath::zero(),
                'realizedGain' => DecimalMath::normalize($realizedTradeRepository->getRealizedGainForUserAndSymbol($user, $symbol)),
                'unrealizedGain' => DecimalMath::zero(),
                'totalGain' => DecimalMath::normalize($realizedTradeRepository->getRealizedGainForUserAndSymbol($user, $symbol)),
                'totalGainPercent' => DecimalMath::zero(4),
                'breakdown' => [],
            ];
        }
        $position['marketDataAvailable'] = $marketDataAvailable;
        foreach ($position['breakdown'] as &$breakdown) {
            $breakdown['marketDataAvailable'] = $marketDataAvailable;
        }
        unset($breakdown);

        $history = [];

        foreach (array_reverse($transactions) as $transaction) {
            $brokerAccount = $transaction->getBrokerAccount();
            $stock = $transaction->getStock();
            if ($brokerAccount === null || $stock === null) {
                continue;
            }

            $history[] = [
                'date' => $transaction->getTransactionDate()->format('Y-m-d'),
                'brokerAccount' => $brokerAccount->getDisplayName(),
                'symbol' => $stock->getSymbol(),
                'type' => $transaction->getType(),
                'quantity' => $transaction->getQuantity(),
                'price' => $transaction->getPrice(),
                'fees' => $transaction->getFees(),
                'currency' => $transaction->getCurrency(),
                'totalAmount' => DecimalMath::add(
                    DecimalMath::mul($transaction->getQuantity(), $transaction->getPrice()),
                    $transaction->getFees()
                ),
            ];
        }

        usort($history, static fn (array $left, array $right): int => strcmp($right['date'], $left['date']));

        $candles = [];
        $chartUnavailable = false;
        try {
            $to = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
            $from = $timeframe === 'weekly' ? $to->modify('-10 years') : $to->modify('-9 years');
            $ohlc = $marketDataManager->getOhlc($stock, $from, $to, $timeframe);
            $candles = $this->candlesForChart($ohlc);
            $markers = $markerFactory->create($transactions, $ohlc, $timeframe);
        } catch (MarketDataUnavailableException) {
            $chartUnavailable = true;
            $markers = [];
        }

        return $this->render('stock/show.html.twig', [
            'symbol' => $symbol,
            'companyName' => $transactions[0]->getStock()?->getCompanyName(),
            'position' => $position,
            'candles' => $candles,
            'markers' => $markers,
            'transactions' => $history,
            'timeframe' => $timeframe,
            'chartUnavailable' => $chartUnavailable,
        ]);
    }

    /**
     * @param list<string> $symbols
     * @return array{0: array<string, string>, 1: list<string>}
     */
    private function loadCurrentPrices(array $symbols, StockRepository $stockRepository, MarketDataManager $marketDataManager): array
    {
        $prices = [];
        $unavailable = [];

        foreach (array_unique($symbols) as $symbol) {
            $stock = $stockRepository->findOneBySymbol($symbol);
            if ($stock === null) {
                $unavailable[] = $symbol;
                continue;
            }

            try {
                $prices[$symbol] = $marketDataManager->getCurrentQuote($stock)->price;
            } catch (MarketDataUnavailableException) {
                $unavailable[] = $symbol;
            }
        }

        return [$prices, $unavailable];
    }

    /**
     * @param list<array<string, mixed>> $positions
     * @param list<string> $unavailableSymbols
     * @return list<array<string, mixed>>
     */
    private function markMarketAvailability(array $positions, array $unavailableSymbols): array
    {
        foreach ($positions as &$position) {
            $available = !in_array($position['symbol'], $unavailableSymbols, true);
            $position['marketDataAvailable'] = $available;
            foreach ($position['breakdown'] as &$breakdown) {
                $breakdown['marketDataAvailable'] = $available;
            }
            unset($breakdown);
        }
        unset($position);

        return $positions;
    }

    /**
     * @param list<array<string, mixed>> $positions
     * @return list<array{symbol: string, companyName: ?string, currency: string, marketValue: string, marketValueFloat: float, percent: float}>
     */
    private function buildExposure(array $positions): array
    {
        $rows = [];
        $total = DecimalMath::zero();

        foreach ($positions as $position) {
            if (($position['marketDataAvailable'] ?? false) !== true) {
                continue;
            }

            $marketValue = (string) ($position['marketValue'] ?? DecimalMath::zero());
            if (DecimalMath::cmp($marketValue, DecimalMath::zero()) <= 0) {
                continue;
            }

            $rows[] = [
                'symbol' => (string) $position['symbol'],
                'companyName' => $position['companyName'] !== null ? (string) $position['companyName'] : null,
                'currency' => (string) $position['currency'],
                'marketValue' => $marketValue,
                'marketValueFloat' => (float) $marketValue,
                'percent' => 0.0,
            ];
            $total = DecimalMath::add($total, $marketValue);
        }

        if (DecimalMath::cmp($total, DecimalMath::zero()) <= 0) {
            return [];
        }

        foreach ($rows as &$row) {
            $row['percent'] = round(((float) $row['marketValue'] / (float) $total) * 100, 2);
        }
        unset($row);

        usort($rows, static fn (array $left, array $right): int => $right['marketValueFloat'] <=> $left['marketValueFloat']);

        return $rows;
    }

    /**
     * @param list<\App\Dto\OhlcDto> $ohlc
     * @return list<array{time: string, open: float, high: float, low: float, close: float}>
     */
    private function candlesForChart(array $ohlc): array
    {
        return array_map(
            static fn ($candle): array => [
                'time' => $candle->date->format('Y-m-d'),
                'open' => (float) $candle->open,
                'high' => (float) $candle->high,
                'low' => (float) $candle->low,
                'close' => (float) $candle->close,
            ],
            $ohlc,
        );
    }
}
