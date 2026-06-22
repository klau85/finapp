<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class QuoteDto
{
    public function __construct(
        public string $symbol,
        public string $price,
        public ?string $change,
        public ?string $changePercent,
        public ?string $currency,
        public ?\DateTimeImmutable $marketTime,
        public string $provider,
    ) {
    }
}
