<?php

declare(strict_types=1);

namespace App\Tests\Logging;

use App\Logging\FileLogger;
use PHPUnit\Framework\TestCase;

final class FileLoggerTest extends TestCase
{
    public function testWarningsAndErrorsAreWrittenToDailySeparateFiles(): void
    {
        $logDir = sys_get_temp_dir().'/finapp-logs-'.bin2hex(random_bytes(6));
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');

        $logger = new FileLogger($logDir);
        $logger->warning('Quote failed.', ['symbol' => 'NVDA']);
        $logger->error('OHLC failed.', ['symbol' => 'VOW.DE']);

        self::assertFileExists($logDir.'/app-'.$date.'.log');
        self::assertFileExists($logDir.'/warnings-'.$date.'.log');
        self::assertFileExists($logDir.'/errors-'.$date.'.log');
        self::assertFileExists($logDir.'/issues-'.$date.'.log');

        self::assertStringContainsString('Quote failed.', (string) file_get_contents($logDir.'/warnings-'.$date.'.log'));
        self::assertStringContainsString('OHLC failed.', (string) file_get_contents($logDir.'/errors-'.$date.'.log'));

        $issues = (string) file_get_contents($logDir.'/issues-'.$date.'.log');
        self::assertStringContainsString('Quote failed.', $issues);
        self::assertStringContainsString('OHLC failed.', $issues);
    }
}
