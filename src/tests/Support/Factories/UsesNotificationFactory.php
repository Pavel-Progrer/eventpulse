<?php

declare(strict_types=1);

namespace Tests\Support\Factories;

use EventPulse\Domain\Notification\Repository\NotificationRepository;

/**
 * Convenience trait for feature tests that build notifications via
 * `NotificationFactory`. Resolves the factory from the test container
 * with the real `NotificationRepository` binding — exactly what
 * production code uses.
 *
 * Usage in a test class:
 *
 * ```php
 * use Tests\Support\Factories\UsesNotificationFactory;
 *
 * final class GetDlqTest extends TestCase
 * {
 *     use RefreshDatabase;
 *     use UsesNotificationFactory;
 *
 *     public function test_something(): void
 *     {
 *         $notification = $this->factory()
 *             ->dlqEntry($this->reader)
 *             ->save();
 *
 *         // ...
 *     }
 * }
 * ```
 *
 * Lazy-resolved per call so a test that doesn't use the factory pays
 * nothing for the trait being present. The container dependency is
 * the real `EloquentNotificationRepository` registered by
 * `EventPulseServiceProvider`, so any test that uses the factory
 * exercises the same persistence path as production.
 */
trait UsesNotificationFactory
{
    protected function factory(): NotificationFactory
    {
        return new NotificationFactory(
            $this->app->make(NotificationRepository::class),
        );
    }
}
