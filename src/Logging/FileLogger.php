<?php

declare(strict_types=1);

namespace App\Logging;

use Psr\Log\AbstractLogger;

final class FileLogger extends AbstractLogger
{
    public function __construct(private readonly string $path)
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $line = sprintf(
            "[%s] %s: %s%s\n",
            (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            strtoupper((string) $level),
            (string) $message,
            $context !== [] ? ' '.json_encode($this->normalizeContext($context), JSON_UNESCAPED_SLASHES) : '',
        );

        file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
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
