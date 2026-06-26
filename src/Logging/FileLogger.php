<?php

declare(strict_types=1);

namespace App\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

final class FileLogger extends AbstractLogger
{
    private const ERROR_LEVELS = [
        LogLevel::EMERGENCY,
        LogLevel::ALERT,
        LogLevel::CRITICAL,
        LogLevel::ERROR,
    ];

    public function __construct(private readonly string $logDir)
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0775, true);
        }

        $level = strtolower((string) $level);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $line = sprintf(
            "[%s] %s: %s%s\n",
            $now->format(\DateTimeInterface::ATOM),
            strtoupper($level),
            (string) $message,
            $context !== [] ? ' '.json_encode($this->normalizeContext($context), JSON_UNESCAPED_SLASHES) : '',
        );

        foreach ($this->pathsForLevel($level, $now) as $path) {
            file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * @return list<string>
     */
    private function pathsForLevel(string $level, \DateTimeImmutable $now): array
    {
        $date = $now->format('Y-m-d');
        $paths = [$this->logDir.'/app-'.$date.'.log'];

        if ($level === LogLevel::WARNING) {
            $paths[] = $this->logDir.'/warnings-'.$date.'.log';
            $paths[] = $this->logDir.'/issues-'.$date.'.log';
        }

        if (in_array($level, self::ERROR_LEVELS, true)) {
            $paths[] = $this->logDir.'/errors-'.$date.'.log';
            $paths[] = $this->logDir.'/issues-'.$date.'.log';
        }

        return $paths;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function normalizeContext(array $context): array
    {
        foreach ($context as $key => $value) {
            if ($value instanceof \Throwable) {
                $context[$key] = [
                    'class' => $value::class,
                    'message' => $value->getMessage(),
                    'file' => $value->getFile(),
                    'line' => $value->getLine(),
                ];
                continue;
            }

            if (is_object($value)) {
                $context[$key] = $value::class;
            }
        }

        return $context;
    }
}
