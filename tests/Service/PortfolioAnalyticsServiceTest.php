<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\BrokerAccount;
use App\Entity\Stock;
use App\Entity\Transaction;
use App\Entity\User;
use App\Repository\PositionLotRepository;
use App\Repository\RealizedTradeRepository;
use App\Repository\TransactionRepository;
use App\Service\DecimalMath;
use App\Service\PortfolioAnalyticsService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class PortfolioAnalyticsServiceTest extends TestCase
{
    public function testStockSplitAdjustsOpenLotsAndPreservesCostBasis(): void
    {
        $user = new User();
        $account = (new BrokerAccount())->setDisplayName('Revolut long USD');
        $stock = (new Stock())->setSymbol('SPCE')->setCurrency('USD');
        $service = new PortfolioAnalyticsService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(TransactionRepository::class),
            $this->createMock(PositionLotRepository::class),
            $this->createMock(RealizedTradeRepository::class),
        );

        $transactions = [
            $this->transaction($user, $account, $stock, '2022-01-18 14:31:30', Transaction::TYPE_BUY, '2.10843373', '9.96000000'),
            $this->transaction($user, $account, $stock, '2022-01-20 14:30:36', Transaction::TYPE_BUY, '1.93709327', '9.22000000'),
            $this->transaction($user, $account, $stock, '2023-08-25 15:14:07', Transaction::TYPE_BUY, '1.96000000', '2.49000000'),
            $this->transaction($user, $account, $stock, '2024-06-17 07:44:52', Transaction::TYPE_STOCK_SPLIT, '-5.70525065', DecimalMath::zero()),
        ];

        $method = new \ReflectionMethod(PortfolioAnalyticsService::class, 'calculateFifoGroup');
        [$lots] = $method->invoke($service, $user, $transactions);

        $shares = DecimalMath::zero();
        $costBasis = DecimalMath::zero();
        foreach ($lots as $lot) {
            $shares = DecimalMath::add($shares, $lot->getQuantityRemaining());
            $costBasis = DecimalMath::add($costBasis, DecimalMath::mul($lot->getQuantityRemaining(), $lot->getPrice()));
        }

        self::assertSame('0.30027635', $shares);
        self::assertSame('43.74039986', $costBasis);
    }

    private function transaction(
        User $user,
        BrokerAccount $account,
        Stock $stock,
        string $date,
        string $type,
        string $quantity,
        string $price,
    ): Transaction {
        return (new Transaction())
            ->setUser($user)
            ->setBrokerAccount($account)
            ->setStock($stock)
            ->setTransactionDate(new \DateTimeImmutable($date, new \DateTimeZone('UTC')))
            ->setType($type)
            ->setQuantity($quantity)
            ->setPrice($price)
            ->setFees(DecimalMath::zero())
            ->setCurrency('USD');
    }
}
