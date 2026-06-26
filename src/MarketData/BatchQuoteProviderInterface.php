<?php

declare(strict_types=1);

namespace App\MarketData;

use App\Dto\QuoteDto;
use App\Entity\Stock;

interface BatchQuoteProviderInterface
{
    /**
     * @param list<Stock> $stocks
     * @return array<string, QuoteDto> Quotes keyed by internal stock symbol.
     */
    public function getCurrentQuotes(array $stocks): array;
}
