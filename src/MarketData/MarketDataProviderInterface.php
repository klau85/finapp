<?php

declare(strict_types=1);

namespace App\MarketData;

use App\Dto\OhlcDto;
use App\Dto\QuoteDto;
use App\Entity\Stock;

interface MarketDataProviderInterface
{
    public function supports(Stock $stock): bool;

    public function getCurrentQuote(Stock $stock): QuoteDto;

    /**
     * @return list<OhlcDto>
     */
    public function getDailyOhlc(Stock $stock, \DateTimeInterface $from, \DateTimeInterface $to): array;
}
