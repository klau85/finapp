<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TransactionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TransactionRepository::class)]
#[ORM\Table(name: 'transactions')]
#[ORM\Index(columns: ['user_id'], name: 'idx_transaction_user')]
#[ORM\Index(columns: ['broker_account_id'], name: 'idx_transaction_broker_account')]
#[ORM\Index(columns: ['stock_id'], name: 'idx_transaction_stock')]
#[ORM\Index(columns: ['transaction_date'], name: 'idx_transaction_date')]
#[ORM\Index(columns: ['type'], name: 'idx_transaction_type')]
class Transaction
{
    public const TYPES = ['BUY', 'SELL'];

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

    #[ORM\ManyToOne(targetEntity: ImportFile::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ImportFile $importFile = null;

    #[ORM\ManyToOne(targetEntity: Stock::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Stock $stock = null;

    #[ORM\Column]
    private \DateTimeImmutable $transactionDate;

    #[ORM\Column(length: 4)]
    private string $type = 'BUY';

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8)]
    private string $quantity = '0.00000000';

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8)]
    private string $price = '0.00000000';

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8)]
    private string $fees = '0.00000000';

    #[ORM\Column(length: 3)]
    private string $currency = 'USD';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->transactionDate = $now;
        $this->createdAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getBrokerAccount(): ?BrokerAccount
    {
        return $this->brokerAccount;
    }

    public function setBrokerAccount(BrokerAccount $brokerAccount): self
    {
        $this->brokerAccount = $brokerAccount;

        return $this;
    }

    public function setImportFile(?ImportFile $importFile): self
    {
        $this->importFile = $importFile;

        return $this;
    }

    public function getStock(): ?Stock
    {
        return $this->stock;
    }

    public function setStock(Stock $stock): self
    {
        $this->stock = $stock;

        return $this;
    }

    public function getTransactionDate(): \DateTimeImmutable
    {
        return $this->transactionDate;
    }

    public function setTransactionDate(\DateTimeImmutable $transactionDate): self
    {
        $this->transactionDate = $transactionDate->setTimezone(new \DateTimeZone('UTC'));

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = strtoupper($type);

        return $this;
    }

    public function getQuantity(): string
    {
        return $this->quantity;
    }

    public function setQuantity(string $quantity): self
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function setPrice(string $price): self
    {
        $this->price = $price;

        return $this;
    }

    public function getFees(): string
    {
        return $this->fees;
    }

    public function setFees(string $fees): self
    {
        $this->fees = $fees;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = strtoupper($currency);

        return $this;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;

        return $this;
    }
}
