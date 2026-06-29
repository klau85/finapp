<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BrokerAccount;
use App\Entity\PositionLot;
use App\Entity\RealizedTrade;
use App\Entity\Stock;
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

            $transactionGroups = $this->groupTransactionsForFifo(
                $this->transactionRepository->findForFifoRecalculation($user),
            );

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
    public function getAggregatedPortfolio(User $user, array $currentPrices = [], ?BrokerAccount $brokerAccount = null): array
    {
        $realizedByStock = $this->realizedTradeRepository->getRealizedGainByStockForUser($user, $brokerAccount);
        $realizedByAccountStock = $this->realizedTradeRepository->getRealizedGainByBrokerAccountAndStockForUser($user, $brokerAccount);
        $byStock = [];

        foreach ($this->getOpenPositionSummaries($user, $currentPrices, $brokerAccount) as $position) {
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
    public function getOpenPositionSummaries(User $user, array $currentPrices = [], ?BrokerAccount $brokerAccount = null): array
    {
        $positions = [];

        foreach ($this->positionLotRepository->findOpenForUser($user, $brokerAccount) as $lot) {
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

            $remainingRatio = DecimalMath::div($lot->getQuantityRemaining(), $lot->getQuantityOriginal());
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
        $mergerTransactions = [];
        foreach ($transactions as $transaction) {
            $group = $transaction->getCorporateActionGroup();
            if ($group !== null && $this->isMergerTransaction($transaction)) {
                $mergerTransactions[$group][] = $transaction;
            }
        }
        $processedMergers = [];

        foreach ($transactions as $transaction) {
            $brokerAccount = $transaction->getBrokerAccount();
            $stock = $transaction->getStock();
            if ($brokerAccount === null || $stock === null) {
                continue;
            }

            if ($transaction->getType() === Transaction::TYPE_BUY) {
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

            if ($transaction->getType() === Transaction::TYPE_STOCK_SPLIT) {
                $this->processStockSplit($transaction, $lots);

                continue;
            }

            if ($this->isMergerTransaction($transaction)) {
                $group = $transaction->getCorporateActionGroup();
                if ($group === null || isset($processedMergers[$group])) {
                    continue;
                }

                $this->processMerger($user, $mergerTransactions[$group] ?? [], $lots);
                $processedMergers[$group] = true;

                continue;
            }

            $this->processSell($user, $transaction, $lots, $realizedTrades);
        }

        return [$lots, $realizedTrades];
    }

    /**
     * @param list<PositionLot> $lots
     */
    private function processStockSplit(Transaction $stockSplitTransaction, array &$lots): void
    {
        $brokerAccount = $stockSplitTransaction->getBrokerAccount();
        $stock = $stockSplitTransaction->getStock();
        if ($brokerAccount === null || $stock === null) {
            return;
        }

        $currentShares = DecimalMath::zero();
        foreach ($lots as $lot) {
            if ($this->sameStock($lot->getStock(), $stock)
                && DecimalMath::cmp($lot->getQuantityRemaining(), DecimalMath::zero()) > 0) {
                $currentShares = DecimalMath::add($currentShares, $lot->getQuantityRemaining());
            }
        }

        $newShares = DecimalMath::add($currentShares, $stockSplitTransaction->getQuantity());
        if (DecimalMath::cmp($currentShares, DecimalMath::zero()) <= 0 || DecimalMath::cmp($newShares, DecimalMath::zero()) <= 0) {
            throw new InsufficientSharesForSellException(sprintf(
                '%s stock split could not be calculated in %s because the resulting share quantity is invalid.',
                $stock->getSymbol(),
                $brokerAccount->getDisplayName(),
            ));
        }

        $factor = DecimalMath::div($newShares, $currentShares);
        $openLotIndexes = array_keys(array_filter(
            $lots,
            fn (PositionLot $lot): bool => $this->sameStock($lot->getStock(), $stock)
                && DecimalMath::cmp($lot->getQuantityRemaining(), DecimalMath::zero()) > 0,
        ));
        $lastOpenLotIndex = $openLotIndexes[array_key_last($openLotIndexes)] ?? null;
        $adjustedShares = DecimalMath::zero();

        foreach ($lots as $index => $lot) {
            if (!$this->sameStock($lot->getStock(), $stock)
                || DecimalMath::cmp($lot->getQuantityRemaining(), DecimalMath::zero()) <= 0) {
                continue;
            }

            $oldRemainingQuantity = $lot->getQuantityRemaining();
            $oldRemainingCostBasis = DecimalMath::mul($oldRemainingQuantity, $lot->getPrice());
            $newRemainingQuantity = $index === $lastOpenLotIndex
                ? DecimalMath::sub($newShares, $adjustedShares)
                : DecimalMath::mul($oldRemainingQuantity, $factor);
            $adjustedShares = DecimalMath::add($adjustedShares, $newRemainingQuantity);

            $lot
                ->setQuantityOriginal(DecimalMath::mul($lot->getQuantityOriginal(), $factor))
                ->setQuantityRemaining($newRemainingQuantity)
                ->setPrice(DecimalMath::div($oldRemainingCostBasis, $newRemainingQuantity));
        }
    }

    /**
     * @param list<Transaction> $transactions
     * @param list<PositionLot> $lots
     */
    private function processMerger(User $user, array $transactions, array &$lots): void
    {
        $stockInTransaction = null;
        $stockOutTransaction = null;

        foreach ($transactions as $transaction) {
            if ($transaction->getType() === Transaction::TYPE_MERGER_IN) {
                $stockInTransaction = $transaction;
            } elseif ($transaction->getType() === Transaction::TYPE_MERGER_OUT) {
                $stockOutTransaction = $transaction;
            }
        }

        $brokerAccount = $stockOutTransaction?->getBrokerAccount();
        $sourceStock = $stockOutTransaction?->getStock();
        $targetStock = $stockInTransaction?->getStock();
        if ($stockInTransaction === null || $stockOutTransaction === null
            || $brokerAccount === null || $sourceStock === null || $targetStock === null
            || $stockInTransaction->getBrokerAccount() !== $brokerAccount) {
            throw new InsufficientSharesForSellException('A stock merger could not be calculated because its linked transactions are incomplete.');
        }

        $sourceQuantity = ltrim($stockOutTransaction->getQuantity(), '-');
        $targetQuantity = $stockInTransaction->getQuantity();
        if (DecimalMath::cmp($sourceQuantity, DecimalMath::zero()) <= 0
            || DecimalMath::cmp($targetQuantity, DecimalMath::zero()) <= 0) {
            throw new InsufficientSharesForSellException(sprintf(
                '%s to %s merger could not be calculated because its share quantities are invalid.',
                $sourceStock->getSymbol(),
                $targetStock->getSymbol(),
            ));
        }

        $availableShares = DecimalMath::zero();
        foreach ($lots as $lot) {
            if ($this->sameStock($lot->getStock(), $sourceStock)
                && DecimalMath::cmp($lot->getQuantityRemaining(), DecimalMath::zero()) > 0) {
                $availableShares = DecimalMath::add($availableShares, $lot->getQuantityRemaining());
            }
        }

        if (DecimalMath::cmp($availableShares, $sourceQuantity) < 0) {
            throw new InsufficientSharesForSellException(sprintf(
                '%s to %s merger could not be calculated in %s because %s source shares are missing.',
                $sourceStock->getSymbol(),
                $targetStock->getSymbol(),
                $brokerAccount->getDisplayName(),
                DecimalMath::sub($sourceQuantity, $availableShares),
            ));
        }

        $remainingSourceQuantity = $sourceQuantity;
        $allocatedTargetQuantity = DecimalMath::zero();
        $newLots = [];

        foreach ($lots as $lot) {
            if (DecimalMath::cmp($remainingSourceQuantity, DecimalMath::zero()) <= 0) {
                break;
            }

            if (!$this->sameStock($lot->getStock(), $sourceStock)
                || DecimalMath::cmp($lot->getQuantityRemaining(), DecimalMath::zero()) <= 0) {
                continue;
            }

            $oldRemainingQuantity = $lot->getQuantityRemaining();
            $transferredSourceQuantity = DecimalMath::cmp($oldRemainingQuantity, $remainingSourceQuantity) <= 0
                ? $oldRemainingQuantity
                : $remainingSourceQuantity;
            $isLastTransfer = DecimalMath::cmp($transferredSourceQuantity, $remainingSourceQuantity) === 0;
            $transferredTargetQuantity = $isLastTransfer
                ? DecimalMath::sub($targetQuantity, $allocatedTargetQuantity)
                : DecimalMath::mul($targetQuantity, DecimalMath::div($transferredSourceQuantity, $sourceQuantity));
            $transferredCostBasis = DecimalMath::mul($transferredSourceQuantity, $lot->getPrice());
            $transferredFees = DecimalMath::mul(
                $lot->getFeesAllocated(),
                DecimalMath::div($transferredSourceQuantity, $oldRemainingQuantity),
            );

            $newLots[] = (new PositionLot())
                ->setUser($user)
                ->setBrokerAccount($brokerAccount)
                ->setStock($targetStock)
                ->setBuyTransaction($stockInTransaction)
                ->setQuantityOriginal($transferredTargetQuantity)
                ->setQuantityRemaining($transferredTargetQuantity)
                ->setPrice(DecimalMath::div($transferredCostBasis, $transferredTargetQuantity))
                ->setFeesAllocated($transferredFees)
                ->setOpenedAt($lot->getOpenedAt());

            $lot
                ->setQuantityRemaining(DecimalMath::sub($oldRemainingQuantity, $transferredSourceQuantity))
                ->setFeesAllocated(DecimalMath::sub($lot->getFeesAllocated(), $transferredFees));
            $remainingSourceQuantity = DecimalMath::sub($remainingSourceQuantity, $transferredSourceQuantity);
            $allocatedTargetQuantity = DecimalMath::add($allocatedTargetQuantity, $transferredTargetQuantity);
        }

        array_push($lots, ...$newLots);
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

            if (!$this->sameStock($lot->getStock(), $stock)
                || DecimalMath::cmp($lot->getQuantityRemaining(), DecimalMath::zero()) <= 0) {
                continue;
            }

            $matchedQuantity = DecimalMath::cmp($lot->getQuantityRemaining(), $remainingSellQuantity) >= 0
                ? $remainingSellQuantity
                : $lot->getQuantityRemaining();

            $buyTransaction = $lot->getBuyTransaction();
            if ($buyTransaction === null) {
                continue;
            }

            $buyFeePart = DecimalMath::mul(
                $lot->getFeesAllocated(),
                DecimalMath::div($matchedQuantity, $lot->getQuantityRemaining())
            );
            $realizedTrades[] = $this->createRealizedTrade($user, $lot, $buyTransaction, $sellTransaction, $matchedQuantity, $buyFeePart);

            $lot->setQuantityRemaining(DecimalMath::sub($lot->getQuantityRemaining(), $matchedQuantity));
            $lot->setFeesAllocated(DecimalMath::sub($lot->getFeesAllocated(), $buyFeePart));
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
        PositionLot $lot,
        Transaction $buyTransaction,
        Transaction $sellTransaction,
        string $matchedQuantity,
        string $buyFeePart,
    ): RealizedTrade {
        $brokerAccount = $sellTransaction->getBrokerAccount();
        $stock = $sellTransaction->getStock();
        \assert($brokerAccount !== null && $stock !== null);

        $sellFeePart = DecimalMath::mul(
            $sellTransaction->getFees(),
            DecimalMath::div($matchedQuantity, $sellTransaction->getQuantity())
        );
        $allocatedFees = DecimalMath::add($buyFeePart, $sellFeePart);
        $profit = DecimalMath::sub(
            DecimalMath::mul(DecimalMath::sub($sellTransaction->getPrice(), $lot->getPrice()), $matchedQuantity),
            $allocatedFees
        );
        $profitBase = DecimalMath::add(DecimalMath::mul($matchedQuantity, $lot->getPrice()), $buyFeePart);
        $profitPercent = DecimalMath::mul(DecimalMath::div($profit, $profitBase, 10), '100', 4);
        $holdingDays = (int) $lot->getOpenedAt()
            ->diff($sellTransaction->getTransactionDate())
            ->format('%r%a');

        return (new RealizedTrade())
            ->setUser($user)
            ->setBrokerAccount($brokerAccount)
            ->setStock($stock)
            ->setBuyTransaction($buyTransaction)
            ->setSellTransaction($sellTransaction)
            ->setQuantity($matchedQuantity)
            ->setBuyPrice($lot->getPrice())
            ->setSellPrice($sellTransaction->getPrice())
            ->setFeesAllocated($allocatedFees)
            ->setProfit($profit)
            ->setProfitPercent($profitPercent)
            ->setHoldingDays($holdingDays)
            ->setOpenedAt($lot->getOpenedAt())
            ->setClosedAt($sellTransaction->getTransactionDate());
    }

    private function isMergerTransaction(Transaction $transaction): bool
    {
        return in_array($transaction->getType(), [
            Transaction::TYPE_MERGER_IN,
            Transaction::TYPE_MERGER_OUT,
            Transaction::TYPE_MERGER_CASH,
        ], true);
    }

    private function sameStock(?Stock $left, Stock $right): bool
    {
        if ($left === null) {
            return false;
        }

        if ($left === $right) {
            return true;
        }

        return $left->getId() !== null && $left->getId() === $right->getId();
    }

    /**
     * @param list<Transaction> $transactions
     * @return array<string, list<Transaction>>
     */
    private function groupTransactionsForFifo(array $transactions): array
    {
        $parents = [];
        foreach ($transactions as $transaction) {
            $key = $this->fifoKey($transaction);
            $parents[$key] = $key;
        }

        $find = function (string $key) use (&$parents, &$find): string {
            if ($parents[$key] !== $key) {
                $parents[$key] = $find($parents[$key]);
            }

            return $parents[$key];
        };
        $union = function (string $left, string $right) use (&$parents, $find): void {
            $leftRoot = $find($left);
            $rightRoot = $find($right);
            if ($leftRoot !== $rightRoot) {
                $parents[$rightRoot] = $leftRoot;
            }
        };

        $firstStockByCorporateAction = [];
        foreach ($transactions as $transaction) {
            $group = $transaction->getCorporateActionGroup();
            if ($group === null || !$this->isMergerTransaction($transaction)) {
                continue;
            }

            $corporateActionKey = ($transaction->getBrokerAccount()?->getId() ?? 0).':'.$group;
            $stockKey = $this->fifoKey($transaction);
            if (isset($firstStockByCorporateAction[$corporateActionKey])) {
                $union($firstStockByCorporateAction[$corporateActionKey], $stockKey);
            } else {
                $firstStockByCorporateAction[$corporateActionKey] = $stockKey;
            }
        }

        $groups = [];
        foreach ($transactions as $transaction) {
            $groups[$find($this->fifoKey($transaction))][] = $transaction;
        }

        return $groups;
    }

    private function fifoKey(Transaction $transaction): string
    {
        $brokerAccount = $transaction->getBrokerAccount();
        $stock = $transaction->getStock();
        $brokerAccountKey = $brokerAccount?->getId() ?? ($brokerAccount !== null ? 'new-'.spl_object_id($brokerAccount) : 'missing');
        $stockKey = $stock?->getId() ?? ($stock !== null ? 'new-'.spl_object_id($stock) : 'missing');

        return $brokerAccountKey.':'.$stockKey;
    }
}
