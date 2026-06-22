<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RealizedTradeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RealizedTradeRepository::class)]
#[ORM\Table(name: 'realized_trade')]
#[ORM\Index(columns: ['user_id'], name: 'idx_realized_trade_user')]
#[ORM\Index(columns: ['broker_account_id'], name: 'idx_realized_trade_broker_account')]
#[ORM\Index(columns: ['stock_id'], name: 'idx_realized_trade_stock')]
class RealizedTrade
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: BrokerAccount::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?BrokerAccount $brokerAccount = null;

    #[ORM\ManyToOne(targetEntity: Stock::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Stock $stock = null;

    #[ORM\ManyToOne(targetEntity: Transaction::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Transaction $buyTransaction = null;

    #[ORM\ManyToOne(targetEntity: Transaction::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Transaction $sellTransaction = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8)]
    private string $quantity = '0.00000000';

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8)]
    private string $buyPrice = '0.00000000';

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8)]
    private string $sellPrice = '0.00000000';

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8)]
    private string $feesAllocated = '0.00000000';

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8)]
    private string $profit = '0.00000000';

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private string $profitPercent = '0.0000';

    #[ORM\Column]
    private int $holdingDays = 0;

    #[ORM\Column]
    private \DateTimeImmutable $openedAt;

    #[ORM\Column]
    private \DateTimeImmutable $closedAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->openedAt = $now;
        $this->closedAt = $now;
        $this->createdAt = $now;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getProfit(): string
    {
        return $this->profit;
    }

    public function setBrokerAccount(BrokerAccount $brokerAccount): self
    {
        $this->brokerAccount = $brokerAccount;

        return $this;
    }

    public function setStock(Stock $stock): self
    {
        $this->stock = $stock;

        return $this;
    }

    public function setBuyTransaction(Transaction $buyTransaction): self
    {
        $this->buyTransaction = $buyTransaction;

        return $this;
    }

    public function setSellTransaction(Transaction $sellTransaction): self
    {
        $this->sellTransaction = $sellTransaction;

        return $this;
    }

    public function setQuantity(string $quantity): self
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function setBuyPrice(string $buyPrice): self
    {
        $this->buyPrice = $buyPrice;

        return $this;
    }

    public function setSellPrice(string $sellPrice): self
    {
        $this->sellPrice = $sellPrice;

        return $this;
    }

    public function setFeesAllocated(string $feesAllocated): self
    {
        $this->feesAllocated = $feesAllocated;

        return $this;
    }

    public function setProfit(string $profit): self
    {
        $this->profit = $profit;

        return $this;
    }

    public function setProfitPercent(string $profitPercent): self
    {
        $this->profitPercent = $profitPercent;

        return $this;
    }

    public function setHoldingDays(int $holdingDays): self
    {
        $this->holdingDays = $holdingDays;

        return $this;
    }

    public function setOpenedAt(\DateTimeImmutable $openedAt): self
    {
        $this->openedAt = $openedAt->setTimezone(new \DateTimeZone('UTC'));

        return $this;
    }

    public function setClosedAt(\DateTimeImmutable $closedAt): self
    {
        $this->closedAt = $closedAt->setTimezone(new \DateTimeZone('UTC'));

        return $this;
    }
}
