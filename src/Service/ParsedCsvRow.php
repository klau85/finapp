<?php

declare(strict_types=1);

namespace App\Service;

final readonly class ParsedCsvRow
{
    /**
     * @param array{
     *     date?: string,
     *     symbol?: string,
     *     type?: string,
     *     quantity?: string,
     *     price?: string,
     *     currency?: string,
     *     fees?: string,
     *     transactionDate?: string
     * } $data
     * @param list<string> $errors
     */
    public function __construct(
        public int $rowNumber,
        public array $data,
        public array $errors,
    ) {
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
