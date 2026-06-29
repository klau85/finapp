<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PositionLotRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PositionLotRepository::class)]
#[ORM\Table(name: 'position_lot')]
#[ORM\Index(columns: ['user_id'], name: 'idx_position_lot_user')]
#[ORM\Index(columns: ['broker_account_id'], name: 'idx_position_lot_broker_account')]
#[ORM\Index(columns: ['stock_id'], name: 'idx_position_lot_stock')]
class PositionLot
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

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8)]
    private string $quantityOriginal = '0.00000000';

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8)]
    private string $quantityRemaining = '0.00000000';

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8)]
    private string $price = '0.00000000';

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8)]
    private string $feesAllocated = '0.00000000';

    #[ORM\Column]
    private \DateTimeImmutable $openedAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->openedAt = $now;
        $this->createdAt = $now;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;

        return $this;
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

    public function getBuyTransaction(): ?Transaction
    {
        return $this->buyTransaction;
    }

    public function getBrokerAccount(): ?BrokerAccount
    {
        return $this->brokerAccount;
    }

    public function getStock(): ?Stock
    {
        return $this->stock;
    }

    public function getQuantityRemaining(): string
    {
        return $this->quantityRemaining;
    }

    public function getQuantityOriginal(): string
    {
        return $this->quantityOriginal;
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function getFeesAllocated(): string
    {
        return $this->feesAllocated;
    }

    public function getOpenedAt(): \DateTimeImmutable
    {
        return $this->openedAt;
    }

    public function setQuantityOriginal(string $quantityOriginal): self
    {
        $this->quantityOriginal = $quantityOriginal;

        return $this;
    }

    public function setQuantityRemaining(string $quantityRemaining): self
    {
        $this->quantityRemaining = $quantityRemaining;

        return $this;
    }

    public function setPrice(string $price): self
    {
        $this->price = $price;

        return $this;
    }

    public function setFeesAllocated(string $feesAllocated): self
    {
        $this->feesAllocated = $feesAllocated;

        return $this;
    }

    public function setOpenedAt(\DateTimeImmutable $openedAt): self
    {
        $this->openedAt = $openedAt->setTimezone(new \DateTimeZone('UTC'));

        return $this;
    }
}
