<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PositionLot;
use App\Entity\RealizedTrade;
use App\Entity\Transaction;
use App\Entity\User;
use App\Exception\InsufficientSharesForSellException;
use App\Repository\PositionLotRepository;
use App\Repository\RealizedTradeRepository;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PortfolioAnalyticsService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TransactionRepository $transactionRepository,
        private PositionLotRepository $positionLotRepository,
        private RealizedTradeRepository $realizedTradeRepository,
    ) {
    }

    /**
     * @return list<string> FIFO warning messages for broker-account/stock groups that could not be calculated.
     */
    public function recalculateForUser(User $user): array
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $this->realizedTradeRepository->deleteForUser($user);
            $this->positionLotRepository->deleteForUser($user);
            $this->entityManager->flush();

            /** @var array<string, list<Transaction>> $transactionGroups */
            $transactionGroups = [];
            foreach ($this->transactionRepository->findForFifoRecalculation($user) as $transaction) {
                $brokerAccount = $transaction->getBrokerAccount();
                $stock = $transaction->getStock();
                if ($brokerAccount === null || $stock === null) {
                    continue;
                }

                $transactionGroups[$this->fifoKey($transaction)][] = $transaction;
            }

            $warnings = [];
            foreach ($transactionGroups as $transactions) {
                try {
                    [$lots, $realizedTrades] = $this->calculateFifoGroup($user, $transactions);
                } catch (InsufficientSharesForSellException $exception) {
                    $warnings[] = $exception->getMessage();
                    continue;
                }

                foreach ($lots as $lot) {
                    $this->entityManager->persist($lot);
                }

                foreach ($realizedTrades as $realizedTrade) {
                    $this->entityManager->persist($realizedTrade);
                }
            }

            $this->entityManager->flush();
            $connection->commit();

            return $warnings;
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            $this->entityManager->clear();

            throw $exception;
        }
    }

    /**
     * @param array<string, string> $currentPrices keyed by stock symbol
     * @return array{
     *     realizedGain: string,
     *     unrealizedGain: string,
     *     totalGain: string,
     *     totalMarketValue: string,
     *     positions: list<array{
     *         brokerAccountId: int|null,
     *         brokerAccount: string,
     *         stockId: int|null,
     *         symbol: string,
     *         currentShares: string,
     *         averageCost: string,
     *         currentPrice: string,
     *         unrealizedGain: string
     *     }>
     * }
     */
    public function getPortfolioSummary(User $user, array $currentPrices = []): array
    {
        $positions = [];
        $unrealizedGain = DecimalMath::zero();

        foreach ($this->getOpenPositionSummaries($user, $currentPrices) as $position) {
            $positions[] = $position;
            $unrealizedGain = DecimalMath::add($unrealizedGain, $position['unrealizedGain']);
        }

        $realizedGain = DecimalMath::normalize($this->realizedTradeRepository->getRealizedGainForUser($user));

        return [
            'realizedGain' => $realizedGain,
            'unrealizedGain' => $unrealizedGain,
            'totalGain' => DecimalMath::add($realizedGain, $unrealizedGain),
            'totalMarketValue' => array_reduce(
                $positions,
                static fn (string $carry, array $position): string => DecimalMath::add($carry, $position['marketValue'] ?? DecimalMath::zero()),
                DecimalMath::zero()
            ),
            'positions' => $positions,
        ];
    }

    /**
     * @param array<string, string> $currentPrices keyed by stock symbol
     * @return list<array{
     *     stockId: int|null,
     *     symbol: string,
     *     companyName: string|null,
     *     currency: string,
     *     totalShares: string,
     *     averageCost: string,
     *     currentPrice: string,
     *     marketValue: string,
     *     realizedGain: string,
     *     unrealizedGain: string,
     *     totalGain: string,
     *     totalGainPercent: string,
     *     breakdown: list<array{
     *         brokerAccountId: int|null,
     *         brokerAccount: string,
     *         shares: string,
     *         averageCost: string,
     *         currentPrice: string,
     *         marketValue: string,
     *         realizedGain: string,
     *         unrealizedGain: string,
     *         totalGain: string
     *     }>
     * }>
     */
    public function getAggregatedPortfolio(User $user, array $currentPrices = []): array
    {
        $realizedByStock = $this->realizedTradeRepository->getRealizedGainByStockForUser($user);
        $realizedByAccountStock = $this->realizedTradeRepository->getRealizedGainByBrokerAccountAndStockForUser($user);
        $byStock = [];

        foreach ($this->getOpenPositionSummaries($user, $currentPrices) as $position) {
            $stockId = (string) ($position['stockId'] ?? 0);
            $accountStockKey = ($position['brokerAccountId'] ?? 0).':'.$stockId;
            $realizedGain = DecimalMath::normalize($realizedByAccountStock[$accountStockKey] ?? DecimalMath::zero());
            $marketValue = DecimalMath::mul($position['currentShares'], $position['currentPrice']);
            $totalGain = DecimalMath::add($realizedGain, $position['unrealizedGain']);

            $byStock[$stockId] ??= [
                'stockId' => $position['stockId'],
                'symbol' => $position['symbol'],
                'companyName' => $position['companyName'],
                'currency' => $position['currency'],
                'totalShares' => DecimalMath::zero(),
                'remainingCostBasis' => DecimalMath::zero(),
                'marketValue' => DecimalMath::zero(),
                'realizedGain' => DecimalMath::normalize($realizedByStock[$stockId] ?? DecimalMath::zero()),
                'unrealizedGain' => DecimalMath::zero(),
                'breakdown' => [],
            ];

            $byStock[$stockId]['totalShares'] = DecimalMath::add($byStock[$stockId]['totalShares'], $position['currentShares']);
            $byStock[$stockId]['remainingCostBasis'] = DecimalMath::add(
                $byStock[$stockId]['remainingCostBasis'],
                $position['remainingCostBasis']
            );
            $byStock[$stockId]['marketValue'] = DecimalMath::add($byStock[$stockId]['marketValue'], $marketValue);
            $byStock[$stockId]['unrealizedGain'] = DecimalMath::add($byStock[$stockId]['unrealizedGain'], $position['unrealizedGain']);
            $byStock[$stockId]['breakdown'][] = [
                'brokerAccountId' => $position['brokerAccountId'],
                'brokerAccount' => $position['brokerAccount'],
                'shares' => $position['currentShares'],
                'averageCost' => $position['averageCost'],
                'currentPrice' => $position['currentPrice'],
                'marketValue' => $marketValue,
                'realizedGain' => $realizedGain,
                'unrealizedGain' => $position['unrealizedGain'],
                'totalGain' => $totalGain,
            ];
        }

        $rows = [];
        foreach ($byStock as $position) {
            $averageCost = DecimalMath::div($position['remainingCostBasis'], $position['totalShares']);
            $currentPrice = DecimalMath::div($position['marketValue'], $position['totalShares']);
            $totalGain = DecimalMath::add($position['realizedGain'], $position['unrealizedGain']);
            $gainBase = $position['remainingCostBasis'];

            $rows[] = [
                'stockId' => $position['stockId'],
                'symbol' => $position['symbol'],
                'companyName' => $position['companyName'],
                'currency' => $position['currency'],
                'totalShares' => $position['totalShares'],
                'averageCost' => $averageCost,
                'currentPrice' => $currentPrice,
                'marketValue' => $position['marketValue'],
                'realizedGain' => $position['realizedGain'],
                'unrealizedGain' => $position['unrealizedGain'],
                'totalGain' => $totalGain,
                'totalGainPercent' => DecimalMath::mul(DecimalMath::div($totalGain, $gainBase, 10), '100', 4),
                'breakdown' => $position['breakdown'],
            ];
        }

        usort($rows, static fn (array $left, array $right): int => strcmp($left['symbol'], $right['symbol']));

        return $rows;
    }

    /**
     * @param array<string, string> $currentPrices keyed by stock symbol
     * @return list<array{
     *     brokerAccountId: int|null,
     *     brokerAccount: string,
     *     stockId: int|null,
     *     symbol: string,
     *     currentShares: string,
     *     averageCost: string,
     *     currentPrice: string,
     *     marketValue: string,
     *     remainingCostBasis: string,
     *     unrealizedGain: string
     * }>
     */
    public function getOpenPositionSummaries(User $user, array $currentPrices = []): array
    {
        $positions = [];

        foreach ($this->positionLotRepository->findOpenForUser($user) as $lot) {
            $brokerAccount = $lot->getBrokerAccount();
            $stock = $lot->getStock();
            $buyTransaction = $lot->getBuyTransaction();
            if ($brokerAccount === null || $stock === null || $buyTransaction === null) {
                continue;
            }

            $key = ($brokerAccount->getId() ?? 0).':'.($stock->getId() ?? 0);
            $positions[$key] ??= [
                'brokerAccountId' => $brokerAccount->getId(),
                'brokerAccount' => $brokerAccount->getDisplayName(),
                'stockId' => $stock->getId(),
                'symbol' => $stock->getSymbol(),
                'companyName' => $stock->getCompanyName(),
                'currency' => $stock->getCurrency(),
                'currentShares' => DecimalMath::zero(),
                'remainingCostBasis' => DecimalMath::zero(),
            ];

            $remainingRatio = DecimalMath::div($lot->getQuantityRemaining(), $buyTransaction->getQuantity());
            $remainingBuyCost = DecimalMath::mul($lot->getQuantityRemaining(), $lot->getPrice());
            $remainingFees = DecimalMath::mul($lot->getFeesAllocated(), $remainingRatio);

            $positions[$key]['currentShares'] = DecimalMath::add(
                $positions[$key]['currentShares'],
                $lot->getQuantityRemaining()
            );
            $positions[$key]['remainingCostBasis'] = DecimalMath::add(
                $positions[$key]['remainingCostBasis'],
                DecimalMath::add($remainingBuyCost, $remainingFees)
            );
        }

        $summaries = [];
        foreach ($positions as $position) {
            $averageCost = DecimalMath::div($position['remainingCostBasis'], $position['currentShares']);
            $currentPrice = DecimalMath::normalize($currentPrices[$position['symbol']] ?? $averageCost);
            $unrealizedGain = DecimalMath::mul(
                DecimalMath::sub($currentPrice, $averageCost),
                $position['currentShares']
            );
            $marketValue = DecimalMath::mul($position['currentShares'], $currentPrice);

            $summaries[] = [
                'brokerAccountId' => $position['brokerAccountId'],
                'brokerAccount' => $position['brokerAccount'],
                'stockId' => $position['stockId'],
                'symbol' => $position['symbol'],
                'companyName' => $position['companyName'],
                'currency' => $position['currency'],
                'currentShares' => $position['currentShares'],
                'remainingCostBasis' => $position['remainingCostBasis'],
                'averageCost' => $averageCost,
                'currentPrice' => $currentPrice,
                'marketValue' => $marketValue,
                'unrealizedGain' => $unrealizedGain,
            ];
        }

        return $summaries;
    }

    /**
     * @param list<Transaction> $transactions
     * @return array{0: list<PositionLot>, 1: list<RealizedTrade>}
     */
    private function calculateFifoGroup(User $user, array $transactions): array
    {
        $lots = [];
        $realizedTrades = [];

        foreach ($transactions as $transaction) {
            $brokerAccount = $transaction->getBrokerAccount();
            $stock = $transaction->getStock();
            if ($brokerAccount === null || $stock === null) {
                continue;
            }

            if ($transaction->getType() === 'BUY') {
                $lots[] = (new PositionLot())
                    ->setUser($user)
                    ->setBrokerAccount($brokerAccount)
                    ->setStock($stock)
                    ->setBuyTransaction($transaction)
                    ->setQuantityOriginal($transaction->getQuantity())
                    ->setQuantityRemaining($transaction->getQuantity())
                    ->setPrice($transaction->getPrice())
                    ->setFeesAllocated($transaction->getFees())
                    ->setOpenedAt($transaction->getTransactionDate());

                continue;
            }

            $this->processSell($user, $transaction, $lots, $realizedTrades);
        }

        return [$lots, $realizedTrades];
    }

    /**
     * @param list<PositionLot> $lots
     * @param list<RealizedTrade> $realizedTrades
     */
    private function processSell(User $user, Transaction $sellTransaction, array &$lots, array &$realizedTrades): void
    {
        $brokerAccount = $sellTransaction->getBrokerAccount();
        $stock = $sellTransaction->getStock();
        if ($brokerAccount === null || $stock === null) {
            return;
        }

        $remainingSellQuantity = $sellTransaction->getQuantity();

        foreach ($lots as $lot) {
            if (DecimalMath::cmp($remainingSellQuantity, DecimalMath::zero()) <= 0) {
                break;
            }

            if (DecimalMath::cmp($lot->getQuantityRemaining(), DecimalMath::zero()) <= 0) {
                continue;
            }

            $matchedQuantity = DecimalMath::cmp($lot->getQuantityRemaining(), $remainingSellQuantity) >= 0
                ? $remainingSellQuantity
                : $lot->getQuantityRemaining();

            $buyTransaction = $lot->getBuyTransaction();
            if ($buyTransaction === null) {
                continue;
            }

            $realizedTrades[] = $this->createRealizedTrade($user, $buyTransaction, $sellTransaction, $matchedQuantity);

            $lot->setQuantityRemaining(DecimalMath::sub($lot->getQuantityRemaining(), $matchedQuantity));
            $remainingSellQuantity = DecimalMath::sub($remainingSellQuantity, $matchedQuantity);
        }

        if (DecimalMath::cmp($remainingSellQuantity, DecimalMath::zero()) > 0) {
            throw new InsufficientSharesForSellException(sprintf(
                '%s could not be calculated because sells exceed buys in %s by %s shares.',
                $stock->getSymbol(),
                $brokerAccount->getDisplayName(),
                $remainingSellQuantity
            ));
        }
    }

    private function createRealizedTrade(
        User $user,
        Transaction $buyTransaction,
        Transaction $sellTransaction,
        string $matchedQuantity,
    ): RealizedTrade {
        $brokerAccount = $sellTransaction->getBrokerAccount();
        $stock = $sellTransaction->getStock();
        \assert($brokerAccount !== null && $stock !== null);

        $buyFeePart = DecimalMath::mul(
            $buyTransaction->getFees(),
            DecimalMath::div($matchedQuantity, $buyTransaction->getQuantity())
        );
        $sellFeePart = DecimalMath::mul(
            $sellTransaction->getFees(),
            DecimalMath::div($matchedQuantity, $sellTransaction->getQuantity())
        );
        $allocatedFees = DecimalMath::add($buyFeePart, $sellFeePart);
        $profit = DecimalMath::sub(
            DecimalMath::mul(DecimalMath::sub($sellTransaction->getPrice(), $buyTransaction->getPrice()), $matchedQuantity),
            $allocatedFees
        );
        $profitBase = DecimalMath::add(DecimalMath::mul($matchedQuantity, $buyTransaction->getPrice()), $buyFeePart);
        $profitPercent = DecimalMath::mul(DecimalMath::div($profit, $profitBase, 10), '100', 4);
        $holdingDays = (int) $buyTransaction->getTransactionDate()
            ->diff($sellTransaction->getTransactionDate())
            ->format('%r%a');

        return (new RealizedTrade())
            ->setUser($user)
            ->setBrokerAccount($brokerAccount)
            ->setStock($stock)
            ->setBuyTransaction($buyTransaction)
            ->setSellTransaction($sellTransaction)
            ->setQuantity($matchedQuantity)
            ->setBuyPrice($buyTransaction->getPrice())
            ->setSellPrice($sellTransaction->getPrice())
            ->setFeesAllocated($allocatedFees)
            ->setProfit($profit)
            ->setProfitPercent($profitPercent)
            ->setHoldingDays($holdingDays)
            ->setOpenedAt($buyTransaction->getTransactionDate())
            ->setClosedAt($sellTransaction->getTransactionDate());
    }

    private function fifoKey(Transaction $transaction): string
    {
        return ($transaction->getBrokerAccount()?->getId() ?? 0).':'.($transaction->getStock()?->getId() ?? 0);
    }
}
