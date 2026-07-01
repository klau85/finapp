<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\JournalEntryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: JournalEntryRepository::class)]
#[ORM\Table(name: 'journal_entry')]
#[ORM\Index(columns: ['user_id', 'entry_date'], name: 'idx_journal_user_date')]
#[ORM\Index(columns: ['stock_id'], name: 'idx_journal_stock')]
#[ORM\Index(columns: ['transaction_id'], name: 'idx_journal_transaction')]
#[ORM\Index(columns: ['target_type'], name: 'idx_journal_target_type')]
#[ORM\Index(columns: ['entry_type'], name: 'idx_journal_entry_type')]
#[ORM\HasLifecycleCallbacks]
class JournalEntry
{
    public const TARGET_PORTFOLIO = 'PORTFOLIO';
    public const TARGET_STOCK = 'STOCK';
    public const TARGET_TRANSACTION = 'TRANSACTION';
    public const TARGET_TYPES = [self::TARGET_PORTFOLIO, self::TARGET_STOCK, self::TARGET_TRANSACTION];

    public const ENTRY_TYPES = [
        'GENERAL',
        'BULL_THESIS',
        'BEAR_THESIS',
        'RISK',
        'VALUATION',
        'EARNINGS',
        'MACRO',
        'BUY_REASON',
        'SELL_REASON',
        'REVIEW',
        'RESEARCH',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Stock::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Stock $stock = null;

    #[ORM\ManyToOne(targetEntity: Transaction::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Transaction $transaction = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::TARGET_TYPES)]
    private string $targetType = self::TARGET_PORTFOLIO;

    #[ORM\Column(length: 30)]
    #[Assert\Choice(choices: self::ENTRY_TYPES)]
    private string $entryType = 'GENERAL';

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Length(max: 180)]
    private ?string $title = null;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    private string $content = '';

    #[ORM\Column(type: 'date_immutable')]
    #[Assert\NotNull]
    private \DateTimeImmutable $entryDate;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = self::utcNow();
        $this->entryDate = $now->setTime(0, 0);
        $this->createdAt = $now;
        $this->updatedAt = $now;
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

    public function getStock(): ?Stock
    {
        return $this->stock;
    }

    public function setStock(?Stock $stock): self
    {
        $this->stock = $stock;

        return $this;
    }

    public function getTransaction(): ?Transaction
    {
        return $this->transaction;
    }

    public function setTransaction(?Transaction $transaction): self
    {
        $this->transaction = $transaction;

        return $this;
    }

    public function getTargetType(): string
    {
        return $this->targetType;
    }

    public function setTargetType(string $targetType): self
    {
        $this->targetType = strtoupper($targetType);

        return $this;
    }

    public function getEntryType(): string
    {
        return $this->entryType;
    }

    public function setEntryType(string $entryType): self
    {
        $this->entryType = strtoupper($entryType);

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $title = trim((string) $title);
        $this->title = $title !== '' ? $title : null;

        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): self
    {
        $this->content = trim($content);

        return $this;
    }

    public function getEntryDate(): \DateTimeImmutable
    {
        return $this->entryDate;
    }

    public function setEntryDate(\DateTimeImmutable $entryDate): self
    {
        $this->entryDate = $entryDate;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getTargetLabel(): string
    {
        if ($this->targetType === self::TARGET_STOCK) {
            return $this->stock?->getSymbol() ?? 'Stock';
        }

        if ($this->targetType === self::TARGET_TRANSACTION) {
            $transaction = $this->transaction;
            $symbol = $transaction?->getStock()?->getSymbol();

            return $transaction !== null && $symbol !== null
                ? sprintf('%s %s transaction', $transaction->getType(), $symbol)
                : 'Transaction';
        }

        return 'Portfolio';
    }

    public function getEntryTypeLabel(): string
    {
        return ucwords(strtolower(str_replace('_', ' ', $this->entryType)));
    }

    #[Assert\Callback]
    public function validateTarget(ExecutionContextInterface $context): void
    {
        if ($this->targetType === self::TARGET_STOCK && $this->stock === null) {
            $context->buildViolation('Select a stock for a stock journal entry.')
                ->atPath('stock')
                ->addViolation();
        }

        if ($this->targetType === self::TARGET_TRANSACTION && $this->transaction === null) {
            $context->buildViolation('Select a transaction for a transaction journal entry.')
                ->atPath('transaction')
                ->addViolation();
        }
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function normalizeTarget(): void
    {
        if ($this->targetType === self::TARGET_PORTFOLIO) {
            $this->stock = null;
            $this->transaction = null;
        } elseif ($this->targetType === self::TARGET_STOCK) {
            $this->transaction = null;
        } elseif ($this->targetType === self::TARGET_TRANSACTION) {
            $this->stock = null;
        }
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = self::utcNow();
    }

    private static function utcNow(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
