<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BrokerAccountRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BrokerAccountRepository::class)]
#[ORM\Table(name: 'broker_account')]
#[ORM\Index(columns: ['user_id'], name: 'idx_broker_account_user')]
#[ORM\Index(columns: ['broker_type'], name: 'idx_broker_account_broker_type')]
#[ORM\HasLifecycleCallbacks]
class BrokerAccount
{
    public const BROKER_TYPES = ['custom', 'xtb', 'revolut', 'ibkr'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::BROKER_TYPES)]
    private string $brokerType = 'custom';

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $displayName = '';

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $accountIdentifier = null;

    #[ORM\Column(length: 3)]
    #[Assert\NotBlank]
    #[Assert\Currency]
    private string $currency = 'USD';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = self::utcNow();
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

    public function getBrokerType(): string
    {
        return $this->brokerType;
    }

    public function setBrokerType(string $brokerType): self
    {
        $this->brokerType = $brokerType;

        return $this;
    }

    public function getBrokerTypeLabel(): string
    {
        return strtoupper($this->brokerType);
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): self
    {
        $this->displayName = $displayName;

        return $this;
    }

    public function getAccountIdentifier(): ?string
    {
        return $this->accountIdentifier;
    }

    public function setAccountIdentifier(?string $accountIdentifier): self
    {
        $this->accountIdentifier = $accountIdentifier ?: null;

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
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
