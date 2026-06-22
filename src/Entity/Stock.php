<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StockRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StockRepository::class)]
#[ORM\Table(name: 'stock')]
#[ORM\Index(columns: ['symbol'], name: 'idx_stock_symbol')]
#[ORM\Index(columns: ['exchange'], name: 'idx_stock_exchange')]
class Stock
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $symbol = '';

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $companyName = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $exchange = null;

    #[ORM\Column(length: 3)]
    private string $currency = 'USD';

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $sector = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $country = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function setSymbol(string $symbol): self
    {
        $this->symbol = strtoupper($symbol);

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getCompanyName(): ?string
    {
        return $this->companyName;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = strtoupper($currency);

        return $this;
    }

    public function setCompanyName(?string $companyName): self
    {
        $this->companyName = $companyName;

        return $this;
    }
}
