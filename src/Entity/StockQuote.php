<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StockQuoteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StockQuoteRepository::class)]
#[ORM\Table(name: 'stock_quote')]
#[ORM\Index(columns: ['stock_id'], name: 'idx_stock_quote_stock')]
#[ORM\Index(columns: ['fetched_at'], name: 'idx_stock_quote_fetched_at')]
class StockQuote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Stock::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Stock $stock = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8)]
    private string $price = '0.00000000';

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8, nullable: true)]
    private ?string $changeAmount = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $changePercent = null;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $currency = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $marketTime = null;

    #[ORM\Column(length: 40)]
    private string $provider = '';

    #[ORM\Column]
    private \DateTimeImmutable $fetchedAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->fetchedAt = $now;
        $this->createdAt = $now;
        $this->updatedAt = $now;
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

    public function getPrice(): string
    {
        return $this->price;
    }

    public function setPrice(string $price): self
    {
        $this->price = $price;

        return $this;
    }

    public function getChangeAmount(): ?string
    {
        return $this->changeAmount;
    }

    public function setChangeAmount(?string $changeAmount): self
    {
        $this->changeAmount = $changeAmount;

        return $this;
    }

    public function getChangePercent(): ?string
    {
        return $this->changePercent;
    }

    public function setChangePercent(?string $changePercent): self
    {
        $this->changePercent = $changePercent;

        return $this;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(?string $currency): self
    {
        $this->currency = $currency !== null ? strtoupper($currency) : null;

        return $this;
    }

    public function getMarketTime(): ?\DateTimeImmutable
    {
        return $this->marketTime;
    }

    public function setMarketTime(?\DateTimeImmutable $marketTime): self
    {
        $this->marketTime = $marketTime?->setTimezone(new \DateTimeZone('UTC'));

        return $this;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    public function getFetchedAt(): \DateTimeImmutable
    {
        return $this->fetchedAt;
    }

    public function setFetchedAt(\DateTimeImmutable $fetchedAt): self
    {
        $this->fetchedAt = $fetchedAt->setTimezone(new \DateTimeZone('UTC'));

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

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt->setTimezone(new \DateTimeZone('UTC'));

        return $this;
    }
}
