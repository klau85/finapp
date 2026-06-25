<?php

declare(strict_types=1);

namespace App\MarketData;

use App\Entity\Transaction;
use App\Twig\NumberFormatExtension;

final readonly class StockChartMarkerFactory
{
    public function __construct(private NumberFormatExtension $formatter)
    {
    }

    /**
     * @param list<Transaction> $transactions
     * @param list<\App\Dto\OhlcDto> $candles
     * @return list<array{time: string, position: string, color: string, shape: string, text: string, type: string, details: list<string>}>
     */
    public function create(array $transactions, array $candles, string $timeframe): array
    {
        return $timeframe === 'weekly'
            ? $this->createWeeklyMarkers($transactions, $candles)
            : $this->createDailyMarkers($transactions);
    }

    /**
     * @param list<Transaction> $transactions
     * @return list<array{time: string, position: string, color: string, shape: string, text: string, type: string, details: list<string>}>
     */
    private function createDailyMarkers(array $transactions): array
    {
        $grouped = [];
        foreach ($this->chronological($transactions) as $transaction) {
            $date = $transaction->getTransactionDate()->format('Y-m-d');
            $type = $transaction->getType();
            $key = $date.':'.$type;
            $grouped[$key] ??= [
                'time' => $date,
                'type' => $type,
                'details' => [],
            ];
            $grouped[$key]['details'][] = $this->transactionText($transaction);
        }

        return array_map(
            fn (array $group): array => $this->marker(
                (string) $group['time'],
                (string) $group['type'],
                $group['details'],
            ),
            array_values($grouped),
        );
    }

    /**
     * @param list<Transaction> $transactions
     * @param list<\App\Dto\OhlcDto> $candles
     * @return list<array{time: string, position: string, color: string, shape: string, text: string, type: string, details: list<string>}>
     */
    private function createWeeklyMarkers(array $transactions, array $candles): array
    {
        $weekDates = [];
        foreach ($candles as $candle) {
            $weekDates[$candle->date->format('o-\WW')] = $candle->date->format('Y-m-d');
        }

        $grouped = [];
        foreach ($this->chronological($transactions) as $transaction) {
            $weekKey = $transaction->getTransactionDate()->format('o-\WW');
            if (!isset($weekDates[$weekKey])) {
                continue;
            }

            $type = $transaction->getType();
            $key = $weekDates[$weekKey].':'.$type;
            $grouped[$key] ??= [
                'time' => $weekDates[$weekKey],
                'type' => $type,
                'details' => [],
            ];
            $grouped[$key]['details'][] = $this->transactionText($transaction);
        }

        return array_map(
            fn (array $group): array => $this->marker(
                (string) $group['time'],
                (string) $group['type'],
                $group['details'],
            ),
            array_values($grouped),
        );
    }

    /**
     * @param list<string> $details
     * @return array{time: string, position: string, color: string, shape: string, text: string, type: string, details: list<string>}
     */
    private function marker(string $time, string $type, array $details): array
    {
        $buy = $type === 'BUY';
        $split = $type === 'STOCK_SPLIT';
        $label = $split ? 'SP' : ($buy ? 'B' : 'S');
        if (count($details) > 1) {
            $label .= (string) count($details);
        }

        return [
            'time' => $time,
            'position' => $buy ? 'belowBar' : 'aboveBar',
            'color' => $split ? '#6b7280' : ($buy ? '#16a34a' : '#dc2626'),
            'shape' => $split ? 'circle' : ($buy ? 'arrowUp' : 'arrowDown'),
            'text' => $label,
            'type' => $type,
            'details' => $details,
        ];
    }

    private function transactionText(Transaction $transaction): string
    {
        $brokerAccount = $transaction->getBrokerAccount();

        return sprintf(
            '%s %s%s%s',
            str_replace('_', ' ', $transaction->getType()),
            $this->formatter->trimNumber($transaction->getQuantity()),
            $transaction->getType() !== 'STOCK_SPLIT' ? ' @ '.$this->formatter->moneySymbol($transaction->getPrice(), $transaction->getCurrency()) : '',
            $brokerAccount !== null ? ' - '.$brokerAccount->getDisplayName() : '',
        );
    }

    /**
     * @param list<Transaction> $transactions
     * @return list<Transaction>
     */
    private function chronological(array $transactions): array
    {
        usort(
            $transactions,
            static fn (Transaction $left, Transaction $right): int => $left->getTransactionDate() <=> $right->getTransactionDate(),
        );

        return $transactions;
    }
}
