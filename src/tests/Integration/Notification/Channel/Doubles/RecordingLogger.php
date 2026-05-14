<?php

declare(strict_types=1);

namespace Tests\Integration\Notification\Channel\Doubles;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Minimal recording PSR-3 logger.
 *
 * Why hand-rolled rather than `Psr\Log\Test\TestLogger`:
 *   that class shipped in `psr/log` 1.x but was removed when 3.x split
 *   the testing utility into a separate `php-fig/log-test` package. A
 *   Laravel 12 environment running `psr/log: ^3.0` may not have it
 *   available unless explicitly required, and depending on a
 *   conditionally-installed test class makes the suite fragile.
 *
 * Extending `AbstractLogger` means we only have to implement `log()` —
 * the level-specific methods (`info()`, `warning()`, etc.) the driver
 * calls are routed through it by the abstract base.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var array<int, array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level'   => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    public function hasRecord(string $level, string $message): bool
    {
        foreach ($this->records as $record) {
            if ($record['level'] === $level && $record['message'] === $message) {
                return true;
            }
        }

        return false;
    }
}
