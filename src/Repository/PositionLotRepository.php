<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PositionLot;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PositionLot> */
class PositionLotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PositionLot::class);
    }

    public function deleteForUser(User $user): int
    {
        return $this->createQueryBuilder('positionLot')
            ->delete()
            ->andWhere('positionLot.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    /**
     * @return list<PositionLot>
     */
    public function findOpenForUser(User $user): array
    {
        return $this->createQueryBuilder('positionLot')
            ->addSelect('brokerAccount', 'stock', 'buyTransaction')
            ->join('positionLot.brokerAccount', 'brokerAccount')
            ->join('positionLot.stock', 'stock')
            ->join('positionLot.buyTransaction', 'buyTransaction')
            ->andWhere('positionLot.user = :user')
            ->andWhere('positionLot.quantityRemaining > 0')
            ->setParameter('user', $user)
            ->orderBy('brokerAccount.displayName', 'ASC')
            ->addOrderBy('stock.symbol', 'ASC')
            ->addOrderBy('positionLot.openedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
