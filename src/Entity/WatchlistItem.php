<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WatchlistItemRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WatchlistItemRepository::class)]
#[ORM\Table(name: 'watchlist_item')]
#[ORM\UniqueConstraint(name: 'uniq_watchlist_user_stock', columns: ['user_id', 'stock_id'])]
#[ORM\Index(columns: ['user_id'], name: 'idx_watchlist_item_user')]
#[ORM\Index(columns: ['stock_id'], name: 'idx_watchlist_item_stock')]
class WatchlistItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Stock::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Stock $stock = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
