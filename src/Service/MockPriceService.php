<?php

declare(strict_types=1);

namespace App\Service;

final class MockPriceService
{
    /**
     * @param list<string> $symbols
     * @return array<string, string>
     */
    public function getCurrentPrices(array $symbols): array
    {
        $prices = [];

        foreach (array_unique($symbols) as $symbol) {
            $prices[$symbol] = match (strtoupper($symbol)) {
                'AAPL' => '210.120',
                'CRWD' => '520.00000000',
                'GOOGL' => '180.00000000',
                'MSFT' => '480.00000000',
                'NVDA' => '190.420',
                'TSLA' => '220.00000000',
                default => $this->deterministicPrice($symbol),
            };
        }

        return $prices;
    }

    /**
     * @param list<string> $dateStrings
     * @return list<array{time: string, open: float, high: float, low: float, close: float}>
     */
    public function getHistoricalCandles(string $symbol, array $dateStrings): array
    {
        $dates = array_values(array_unique($dateStrings));
        sort($dates);

        $start = $dates !== []
            ? new \DateTimeImmutable($dates[0].' -20 days', new \DateTimeZone('UTC'))
            : new \DateTimeImmutable('-90 days', new \DateTimeZone('UTC'));
        $end = $dates !== []
            ? new \DateTimeImmutable(end($dates).' +20 days', new \DateTimeZone('UTC'))
            : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $seed = abs(crc32(strtoupper($symbol)));
        $price = 80 + ($seed % 180);
        $candles = [];
        $transactionDateSet = array_fill_keys($dates, true);

        for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
            $day = (int) $date->format('N');
            if ($day >= 6 && !isset($transactionDateSet[$date->format('Y-m-d')])) {
                continue;
            }

            $step = ((($seed + (int) $date->format('z')) % 13) - 6) / 10;
            $open = max(1, $price);
            $close = max(1, $open + $step);
            $high = max($open, $close) + 1.25;
            $low = max(0.5, min($open, $close) - 1.1);

            $candles[] = [
                'time' => $date->format('Y-m-d'),
                'open' => round($open, 4),
                'high' => round($high, 4),
                'low' => round($low, 4),
                'close' => round($close, 4),
            ];

            $price = $close;
        }

        return $candles;
    }

    private function deterministicPrice(string $symbol): string
    {
        $hash = abs(crc32(strtoupper($symbol)));
        $whole = 50 + ($hash % 450);
        $cents = $hash % 100;

        return sprintf('%d.%02d000000', $whole, $cents);
    }
}
