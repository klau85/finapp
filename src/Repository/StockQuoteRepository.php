<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Stock;
use App\Entity\StockQuote;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<StockQuote> */
class StockQuoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StockQuote::class);
    }

    public function findLatestForStock(Stock $stock): ?StockQuote
    {
        return $this->createQueryBuilder('quote')
            ->andWhere('quote.stock = :stock')
            ->setParameter('stock', $stock)
            ->orderBy('quote.fetchedAt', 'DESC')
            ->addOrderBy('quote.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
