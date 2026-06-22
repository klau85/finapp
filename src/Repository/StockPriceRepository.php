<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Stock;
use App\Entity\StockPrice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<StockPrice> */
class StockPriceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StockPrice::class);
    }

    /**
     * @return list<StockPrice>
     */
    public function findForStockBetween(Stock $stock, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->createQueryBuilder('price')
            ->andWhere('price.stock = :stock')
            ->andWhere('price.date >= :from')
            ->andWhere('price.date <= :to')
            ->setParameter('stock', $stock)
            ->setParameter('from', \DateTimeImmutable::createFromInterface($from)->setTime(0, 0))
            ->setParameter('to', \DateTimeImmutable::createFromInterface($to)->setTime(0, 0))
            ->orderBy('price.date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneForStockAndDate(Stock $stock, \DateTimeInterface $date): ?StockPrice
    {
        return $this->createQueryBuilder('price')
            ->andWhere('price.stock = :stock')
            ->andWhere('price.date = :date')
            ->setParameter('stock', $stock)
            ->setParameter('date', \DateTimeImmutable::createFromInterface($date)->setTime(0, 0))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLatestForStock(Stock $stock): ?StockPrice
    {
        return $this->createQueryBuilder('price')
            ->andWhere('price.stock = :stock')
            ->setParameter('stock', $stock)
            ->orderBy('price.date', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
