<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\MarketData\MarketDataManager;
use App\Repository\BrokerAccountRepository;
use App\Repository\PositionLotRepository;
use App\Repository\StockRepository;
use App\Repository\TransactionRepository;
use App\Service\DecimalMath;
use App\Service\PortfolioAnalyticsService;
use App\Twig\NumberFormatExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function home(): Response
    {
        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/dashboard', name: 'app_dashboard')]
    public function dashboard(
        BrokerAccountRepository $brokerAccounts,
        TransactionRepository $transactionRepository,
        PortfolioAnalyticsService $portfolioAnalytics,
        MarketDataManager $marketDataManager,
        StockRepository $stockRepository,
        PositionLotRepository $positionLotRepository,
        NumberFormatExtension $formatter,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        $transactionCount = $transactionRepository->countForUser($user);
        $symbols = $transactionRepository->findSymbolsForUser($user);
        [$currentPrices, $unavailableSymbols] = $this->loadCurrentPrices($symbols, $stockRepository, $marketDataManager);
        $marketDataAvailable = $unavailableSymbols === [];
        $positions = $portfolioAnalytics->getAggregatedPortfolio($user, $currentPrices);
        foreach ($positions as &$position) {
            $available = !in_array($position['symbol'], $unavailableSymbols, true);
            $position['marketDataAvailable'] = $available;
        }
        unset($position);
        $recentTransactions = $transactionRepository->findRecentForUser($user, 5);

        $portfolioValue = $marketDataAvailable ? array_reduce(
            $positions,
            static fn (string $carry, array $position): string => DecimalMath::add($carry, $position['marketValue']),
            DecimalMath::zero()
        ) : null;
        $unrealizedPl = $marketDataAvailable ? array_reduce(
            $positions,
            static fn (string $carry, array $position): string => DecimalMath::add($carry, $position['unrealizedGain']),
            DecimalMath::zero()
        ) : null;

        $brokerAllocation = $marketDataAvailable ? $this->buildBrokerAllocation($positions) : [];
        $currencyExposure = $marketDataAvailable ? $this->buildCurrencyExposure($positions) : [];
        $investedCapital = $positionLotRepository->getInvestedCapitalByCurrencyForUser($user);

        return $this->render('dashboard/index.html.twig', [
            'brokerAccounts' => $brokerAccounts->findForUser($user),
            'hasTransactions' => $transactionCount > 0,
            'marketDataAvailable' => $marketDataAvailable,
            'positions' => $positions,
            'recentActivities' => $this->buildRecentActivity($recentTransactions, $formatter),
            'biggestWinners' => $marketDataAvailable ? array_slice($this->positivePositions($this->sortByGain($positions, descending: true)), 0, 5) : [],
            'biggestLosers' => $marketDataAvailable ? array_slice($this->negativePositions($this->sortByGain($positions, descending: false)), 0, 5) : [],
            'brokerAllocation' => $brokerAllocation,
            'currencyExposure' => $currencyExposure,
            'metrics' => [
                'portfolioValue' => $portfolioValue,
                'unrealizedPl' => $unrealizedPl,
                'investedCapital' => $investedCapital,
            ],
        ]);
    }

    /**
     * @param list<array<string, mixed>> $positions
     * @return list<array{brokerAccount: string, marketValue: string}>
     */
    private function buildBrokerAllocation(array $positions): array
    {
        $allocation = [];

        foreach ($positions as $position) {
            foreach ($position['breakdown'] ?? [] as $breakdown) {
                $brokerAccount = (string) $breakdown['brokerAccount'];
                $allocation[$brokerAccount] ??= DecimalMath::zero();
                $allocation[$brokerAccount] = DecimalMath::add($allocation[$brokerAccount], (string) $breakdown['marketValue']);
            }
        }

        return array_map(
            static fn (string $brokerAccount, string $marketValue): array => [
                'brokerAccount' => $brokerAccount,
                'marketValue' => $marketValue,
            ],
            array_keys($allocation),
            array_values($allocation)
        );
    }

    /**
     * @param list<array<string, mixed>> $positions
     * @return list<array{currency: string, marketValue: string}>
     */
    private function buildCurrencyExposure(array $positions): array
    {
        $exposure = [];

        foreach ($positions as $position) {
            $currency = (string) $position['currency'];
            $exposure[$currency] ??= DecimalMath::zero();
            $exposure[$currency] = DecimalMath::add($exposure[$currency], (string) $position['marketValue']);
        }

        return array_map(
            static fn (string $currency, string $marketValue): array => [
                'currency' => $currency,
                'marketValue' => $marketValue,
            ],
            array_keys($exposure),
            array_values($exposure)
        );
    }

    /**
     * @param list<\App\Entity\Transaction> $transactions
     * @return list<array{sentence: string, date: string, type: string, currency: string}>
     */
    private function buildRecentActivity(array $transactions, NumberFormatExtension $formatter): array
    {
        $activities = [];

        foreach ($transactions as $transaction) {
            $stock = $transaction->getStock();
            $brokerAccount = $transaction->getBrokerAccount();
            if ($stock === null || $brokerAccount === null) {
                continue;
            }

            $sentence = match ($transaction->getType()) {
                'BUY' => sprintf(
                    'You bought %s %s @ %s in %s on %s.',
                    $formatter->trimNumber($transaction->getQuantity()),
                    $stock->getSymbol(),
                    $formatter->moneySymbol($transaction->getPrice(), $transaction->getCurrency()),
                    $brokerAccount->getDisplayName(),
                    $transaction->getTransactionDate()->format('Y-m-d'),
                ),
                'SELL' => sprintf(
                    'You sold %s %s @ %s in %s on %s.',
                    $formatter->trimNumber($transaction->getQuantity()),
                    $stock->getSymbol(),
                    $formatter->moneySymbol($transaction->getPrice(), $transaction->getCurrency()),
                    $brokerAccount->getDisplayName(),
                    $transaction->getTransactionDate()->format('Y-m-d'),
                ),
                'STOCK_SPLIT' => sprintf(
                    'A stock split adjusted %s %s in %s on %s.',
                    $formatter->trimNumber($transaction->getQuantity()),
                    $stock->getSymbol(),
                    $brokerAccount->getDisplayName(),
                    $transaction->getTransactionDate()->format('Y-m-d'),
                ),
                default => sprintf(
                    '%s %s %s in %s on %s.',
                    str_replace('_', ' ', $transaction->getType()),
                    $formatter->trimNumber($transaction->getQuantity()),
                    $stock->getSymbol(),
                    $brokerAccount->getDisplayName(),
                    $transaction->getTransactionDate()->format('Y-m-d'),
                ),
            };

            $activities[] = [
                'sentence' => $sentence,
                'date' => $transaction->getTransactionDate()->format('Y-m-d'),
                'type' => $transaction->getType(),
                'currency' => $transaction->getCurrency(),
            ];
        }

        return $activities;
    }

    /**
     * @param list<array<string, mixed>> $positions
     * @return list<array<string, mixed>>
     */
    private function sortByGain(array $positions, bool $descending): array
    {
        usort($positions, static function (array $left, array $right) use ($descending): int {
            $result = DecimalMath::cmp((string) $left['totalGain'], (string) $right['totalGain']);

            return $descending ? -$result : $result;
        });

        return $positions;
    }

    /**
     * @param list<array<string, mixed>> $positions
     * @return list<array<string, mixed>>
     */
    private function negativePositions(array $positions): array
    {
        return array_values(array_filter(
            $positions,
            static fn (array $position): bool => DecimalMath::cmp((string) $position['totalGain'], DecimalMath::zero()) < 0,
        ));
    }

    /**
     * @param list<array<string, mixed>> $positions
     * @return list<array<string, mixed>>
     */
    private function positivePositions(array $positions): array
    {
        return array_values(array_filter(
            $positions,
            static fn (array $position): bool => DecimalMath::cmp((string) $position['totalGain'], DecimalMath::zero()) > 0,
        ));
    }

    /**
     * @param list<string> $symbols
     * @return array{0: array<string, string>, 1: list<string>}
     */
    private function loadCurrentPrices(array $symbols, StockRepository $stockRepository, MarketDataManager $marketDataManager): array
    {
        $prices = [];
        $unavailable = [];
        $stocks = [];

        foreach (array_unique($symbols) as $symbol) {
            $stock = $stockRepository->findOneBySymbol($symbol);
            if ($stock === null) {
                $unavailable[] = $symbol;
                continue;
            }

            $stocks[$symbol] = $stock;
        }

        $quotes = $marketDataManager->getCurrentQuotes(array_values($stocks));
        foreach (array_keys($stocks) as $symbol) {
            if (!isset($quotes[$symbol])) {
                $unavailable[] = $symbol;
                continue;
            }

            $prices[$symbol] = $quotes[$symbol]->price;
        }

        return [$prices, $unavailable];
    }
}
