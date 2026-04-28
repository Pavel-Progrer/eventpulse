<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\RequireScope;
use EventPulse\Application\Notification\NotificationDispatchQueue;
use EventPulse\Application\Shared\Clock;
use EventPulse\Application\Shared\DomainEventDispatcher;
use EventPulse\Application\Shared\NullDomainEventDispatcher;
use EventPulse\Application\Shared\SystemClock;
use EventPulse\Domain\Notification\Repository\NotificationRepository;
use EventPulse\Infrastructure\Notification\Persistence\EloquentNotificationRepository;
use EventPulse\Infrastructure\Notification\Queue\LaravelNotificationDispatchQueue;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

/**
 * EventPulse-specific bindings.
 *
 * The container is the seam between Domain/Application interfaces and their
 * Infrastructure implementations:
 *  - `NotificationRepository`     (domain)      → `EloquentNotificationRepository`.
 *  - `Clock`                      (application) → `SystemClock`.
 *  - `DomainEventDispatcher`      (application) → `NullDomainEventDispatcher` (Day 8 swaps in the real one).
 *  - `NotificationDispatchQueue`  (application) → `LaravelNotificationDispatchQueue`.
 *
 * The provider also registers the route middleware aliases so routes can use
 * `auth.api-key` and `scope:notifications:write` rather than fully qualified
 * class names.
 *
 * Why a dedicated provider instead of `AppServiceProvider`: keeps the
 * EventPulse-domain wiring co-located, so a reader scanning
 * `bootstrap/providers.php` sees one entry that says "this is the project's
 * domain bindings" without having to search through unrelated registrations.
 */
final class EventPulseServiceProvider extends ServiceProvider
{
    /**
     * Class-string mapping from interface to concrete implementation.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        Clock::class                     => SystemClock::class,
        DomainEventDispatcher::class     => NullDomainEventDispatcher::class,
        NotificationRepository::class    => EloquentNotificationRepository::class,
        NotificationDispatchQueue::class => LaravelNotificationDispatchQueue::class,
    ];

    public function register(): void
    {
        // No additional registrations beyond the $bindings declaration.
    }

    public function boot(Router $router): void
    {
        // Route middleware aliases. Using string aliases keeps `routes/api.php`
        // readable and decouples the route file from middleware class moves.
        $router->aliasMiddleware('auth.api-key', AuthenticateApiKey::class);
        $router->aliasMiddleware('scope', RequireScope::class);
    }
}