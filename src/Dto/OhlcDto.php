<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class OhlcDto
{
    public function __construct(
        public string $symbol,
        public \DateTimeImmutable $date,
        public string $open,
        public string $high,
        public string $low,
        public string $close,
        public ?int $volume,
        public string $provider,
    ) {
    }
}
