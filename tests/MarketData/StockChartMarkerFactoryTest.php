<?php

declare(strict_types=1);

namespace App\Tests\MarketData;

use App\Dto\OhlcDto;
use App\Entity\BrokerAccount;
use App\Entity\Transaction;
use App\MarketData\StockChartMarkerFactory;
use App\Twig\NumberFormatExtension;
use PHPUnit\Framework\TestCase;

final class StockChartMarkerFactoryTest extends TestCase
{
    public function testWeeklyMarkersGroupTransactionsInSameIsoWeek(): void
    {
        $factory = new StockChartMarkerFactory(new NumberFormatExtension());
        $account = (new BrokerAccount())->setDisplayName('XTB Account 1');

        $markers = $factory->create([
            $this->transaction('2026-06-17', 'BUY', '100.00000000', '142.50000000', $account),
            $this->transaction('2026-06-18', 'BUY', '25.00000000', '143.00000000', $account),
            $this->transaction('2026-06-19', 'SELL', '20.00000000', '150.00000000', $account),
        ], [
            new OhlcDto('NVDA', new \DateTimeImmutable('2026-06-15'), '100.00000000', '118.00000000', '98.00000000', '117.00000000', null, 'yahoo'),
        ], 'weekly');

        self::assertCount(2, $markers);
        self::assertSame('2026-06-15', $markers[0]['time']);
        self::assertSame('B2', $markers[0]['text']);
        self::assertContains("BUY 100 @ $142.5 - XTB Account 1", $markers[0]['details']);
        self::assertContains("BUY 25 @ $143 - XTB Account 1", $markers[0]['details']);
        self::assertSame('aboveBar', $markers[1]['position']);
        self::assertSame('S', $markers[1]['text']);
    }

    private function transaction(string $date, string $type, string $quantity, string $price, BrokerAccount $account): Transaction
    {
        return (new Transaction())
            ->setBrokerAccount($account)
            ->setTransactionDate(new \DateTimeImmutable($date, new \DateTimeZone('UTC')))
            ->setType($type)
            ->setQuantity($quantity)
            ->setPrice($price)
            ->setFees('1.00000000')
            ->setCurrency('USD');
    }
}
