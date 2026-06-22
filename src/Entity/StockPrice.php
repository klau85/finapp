<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StockPriceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StockPriceRepository::class)]
#[ORM\Table(name: 'stock_price')]
#[ORM\UniqueConstraint(name: 'uniq_stock_price_stock_date', columns: ['stock_id', 'date'])]
#[ORM\Index(columns: ['stock_id'], name: 'idx_stock_price_stock')]
#[ORM\Index(columns: ['date'], name: 'idx_stock_price_date')]
class StockPrice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Stock::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Stock $stock = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8)]
    private string $open = '0.00000000';

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8)]
    private string $high = '0.00000000';

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8)]
    private string $low = '0.00000000';

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8)]
    private string $close = '0.00000000';

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?string $volume = null;

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
        $this->date = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $this->fetchedAt = $now;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): self
    {
        $this->date = $date->setTime(0, 0)->setTimezone(new \DateTimeZone('UTC'));

        return $this;
    }

    public function getOpen(): string
    {
        return $this->open;
    }

    public function setOpen(string $open): self
    {
        $this->open = $open;

        return $this;
    }

    public function getHigh(): string
    {
        return $this->high;
    }

    public function setHigh(string $high): self
    {
        $this->high = $high;

        return $this;
    }

    public function getLow(): string
    {
        return $this->low;
    }

    public function setLow(string $low): self
    {
        $this->low = $low;

        return $this;
    }

    public function getClose(): string
    {
        return $this->close;
    }

    public function setClose(string $close): self
    {
        $this->close = $close;

        return $this;
    }

    public function getVolume(): ?int
    {
        return $this->volume !== null ? (int) $this->volume : null;
    }

    public function setVolume(?int $volume): self
    {
        $this->volume = $volume !== null ? (string) $volume : null;

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
