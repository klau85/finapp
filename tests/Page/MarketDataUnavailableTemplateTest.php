<?php

declare(strict_types=1);

namespace App\Tests\Page;

use PHPUnit\Framework\TestCase;

final class MarketDataUnavailableTemplateTest extends TestCase
{
    public function testPortfolioTemplateContainsUnavailableMessage(): void
    {
        $template = file_get_contents(__DIR__.'/../../templates/portfolio/index.html.twig');

        self::assertIsString($template);
        self::assertStringContainsString('Market data is unavailable at this moment.', $template);
        self::assertStringContainsString('Unavailable', $template);
    }

    public function testStockTemplateContainsChartUnavailableMessage(): void
    {
        $template = file_get_contents(__DIR__.'/../../templates/stock/show.html.twig');

        self::assertIsString($template);
        self::assertStringContainsString('Chart data is unavailable at this moment.', $template);
        self::assertStringContainsString('timeframe ==', $template);
    }
}
