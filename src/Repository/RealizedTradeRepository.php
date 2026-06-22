<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RealizedTrade;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<RealizedTrade> */
class RealizedTradeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RealizedTrade::class);
    }

    public function deleteForUser(User $user): int
    {
        return $this->createQueryBuilder('realizedTrade')
            ->delete()
            ->andWhere('realizedTrade.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    public function getRealizedGainForUser(User $user): string
    {
        $value = $this->createQueryBuilder('realizedTrade')
            ->select('COALESCE(SUM(realizedTrade.profit), 0)')
            ->andWhere('realizedTrade.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return (string) $value;
    }

    public function getRealizedGainForUserAndSymbol(User $user, string $symbol): string
    {
        $value = $this->createQueryBuilder('realizedTrade')
            ->select('COALESCE(SUM(realizedTrade.profit), 0)')
            ->join('realizedTrade.stock', 'stock')
            ->andWhere('realizedTrade.user = :user')
            ->andWhere('stock.symbol = :symbol')
            ->setParameter('user', $user)
            ->setParameter('symbol', strtoupper($symbol))
            ->getQuery()
            ->getSingleScalarResult();

        return (string) $value;
    }

    /**
     * @return array<string, string>
     */
    public function getRealizedGainByStockForUser(User $user): array
    {
        $rows = $this->createQueryBuilder('realizedTrade')
            ->select('IDENTITY(realizedTrade.stock) AS stock_id, COALESCE(SUM(realizedTrade.profit), 0) AS profit')
            ->andWhere('realizedTrade.user = :user')
            ->setParameter('user', $user)
            ->groupBy('realizedTrade.stock')
            ->getQuery()
            ->getArrayResult();

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string) $row['stock_id']] = (string) $row['profit'];
        }

        return $indexed;
    }

    /**
     * @return array<string, string>
     */
    public function getRealizedGainByBrokerAccountAndStockForUser(User $user): array
    {
        $rows = $this->createQueryBuilder('realizedTrade')
            ->select('IDENTITY(realizedTrade.brokerAccount) AS broker_account_id, IDENTITY(realizedTrade.stock) AS stock_id, COALESCE(SUM(realizedTrade.profit), 0) AS profit')
            ->andWhere('realizedTrade.user = :user')
            ->setParameter('user', $user)
            ->groupBy('realizedTrade.brokerAccount')
            ->addGroupBy('realizedTrade.stock')
            ->getQuery()
            ->getArrayResult();

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row['broker_account_id'].':'.$row['stock_id']] = (string) $row['profit'];
        }

        return $indexed;
    }
}
