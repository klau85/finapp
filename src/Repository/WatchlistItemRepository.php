<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WatchlistItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WatchlistItem> */
class WatchlistItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WatchlistItem::class);
    }
}
