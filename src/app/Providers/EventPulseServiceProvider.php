<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\RequireScope;
use EventPulse\Application\Notification\Channel\ChannelDispatcher;
use EventPulse\Application\Notification\Channel\ChannelDriver;
use EventPulse\Application\Notification\Channel\WebhookEndpointResolver;
use EventPulse\Application\Notification\NotificationDispatchQueue;
use EventPulse\Application\Shared\Clock;
use EventPulse\Application\Shared\DomainEventDispatcher;
use EventPulse\Application\Shared\NullDomainEventDispatcher;
use EventPulse\Application\Shared\SystemClock;
use EventPulse\Domain\Notification\Repository\NotificationRepository;
use EventPulse\Infrastructure\Notification\Channel\EmailChannelDriver;
use EventPulse\Infrastructure\Notification\Channel\SmsChannelDriver;
use EventPulse\Infrastructure\Notification\Channel\UnconfiguredWebhookEndpointResolver;
use EventPulse\Infrastructure\Notification\Channel\WebhookChannelDriver;
use EventPulse\Infrastructure\Notification\Persistence\EloquentNotificationRepository;
use EventPulse\Infrastructure\Notification\Queue\LaravelNotificationDispatchQueue;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * EventPulse-specific bindings.
 *
 * The container is the seam between Domain/Application interfaces and
 * their Infrastructure implementations:
 *  - `NotificationRepository`     (domain)      → `EloquentNotificationRepository`.
 *  - `Clock`                      (application) → `SystemClock`.
 *  - `DomainEventDispatcher`      (application) → `NullDomainEventDispatcher` (Day 8 swaps in the real one).
 *  - `NotificationDispatchQueue`  (application) → `LaravelNotificationDispatchQueue`.
 *  - `WebhookEndpointResolver`    (application) → `UnconfiguredWebhookEndpointResolver` (Day 9 swaps in an Eloquent-backed resolver).
 *  - `ChannelDispatcher`          (application) → constructed once with the three channel drivers.
 *
 * Why register `ChannelDispatcher` as a singleton with an explicit
 * driver list rather than via Laravel's tagged-binding mechanism:
 *  Laravel tags work, but reading `tag('eventpulse.channel-drivers')` at
 *  the call site does not tell you *which* drivers will be resolved —
 *  you have to grep for `->tag()`. An explicit closure here lists all
 *  three drivers in one place, so the registration is self-documenting
 *  and the constructor's exhaustiveness check fires at the same source
 *  location it's specified.
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
        WebhookEndpointResolver::class   => UnconfiguredWebhookEndpointResolver::class,
    ];

    public function register(): void
    {
        $this->registerChannelDispatcher();
    }

    public function boot(Router $router): void
    {
        // Route middleware aliases. Using string aliases keeps
        // `routes/api.php` readable and decouples the route file from
        // middleware class moves.
        $router->aliasMiddleware('auth.api-key', AuthenticateApiKey::class);
        $router->aliasMiddleware('scope', RequireScope::class);
    }

    /**
     * Build the `ChannelDispatcher` singleton with one driver per channel.
     *
     * Each driver is resolved lazily by name. Wrapping the construction
     * in a singleton means the driver instances are constructed once per
     * worker process — appropriate for `Mailer` and `HttpFactory` which
     * are stateful and benefit from connection reuse.
     *
     * The list of drivers is kept in this single method so adding a new
     * channel is one line here in addition to the new driver class. The
     * dispatcher's constructor will throw at boot if any case of `Channel`
     * is missing from this list.
     */
    private function registerChannelDispatcher(): void
    {
        // Each driver is registered against its concrete class so the
        // dispatcher can resolve it by class name; the binding for the
        // ChannelDriver interface itself is intentionally absent (there
        // is no single "the" driver — that's the whole point of the
        // strategy).
        $this->app->singleton(EmailChannelDriver::class, function (Application $app): EmailChannelDriver {
            // Strict resolution: every email dispatched by EventPulse must
            // carry a deterministic From address. Falling back to a hard-
            // coded "noreply@eventpulse.local" if the env is unset would
            // hide misconfiguration in dev and silently send mail with a
            // bogus sender in production. We'd rather fail at boot, where
            // the operator sees the cause directly, than ship the bug.
            //
            // The framework's standard env vars (MAIL_FROM_ADDRESS /
            // MAIL_FROM_NAME) are read by config/mail.php; this validates
            // they actually arrived.
            $config      = $app['config']->get('mail.from', []);
            $fromAddress = is_string($config['address'] ?? null) ? trim($config['address']) : '';
            $fromName    = is_string($config['name']    ?? null) ? trim($config['name'])    : '';

            if ($fromAddress === '') {
                throw new \RuntimeException(
                    'EventPulse email driver requires mail.from.address to be configured '
                    . '(set MAIL_FROM_ADDRESS in your environment).'
                );
            }

            if ($fromName === '') {
                throw new \RuntimeException(
                    'EventPulse email driver requires mail.from.name to be configured '
                    . '(set MAIL_FROM_NAME in your environment).'
                );
            }

            return new EmailChannelDriver(
                mailer:      $app->make(Mailer::class),
                logger:      $app->make(LoggerInterface::class),
                fromAddress: $fromAddress,
                fromName:    $fromName,
            );
        });

        $this->app->singleton(WebhookChannelDriver::class, function (Application $app): WebhookChannelDriver {
            return new WebhookChannelDriver(
                http:             $app->make(HttpFactory::class),
                endpointResolver: $app->make(WebhookEndpointResolver::class),
                logger:           $app->make(LoggerInterface::class),
                timeoutSeconds:   (int) $app['config']->get('eventpulse.webhook.timeout_seconds', 30),
            );
        });

        $this->app->singleton(SmsChannelDriver::class, function (Application $app): SmsChannelDriver {
            return new SmsChannelDriver(
                logger: $app->make(LoggerInterface::class),
            );
        });

        $this->app->singleton(ChannelDispatcher::class, function (Application $app): ChannelDispatcher {
            /** @var ChannelDriver[] $drivers */
            $drivers = [
                $app->make(EmailChannelDriver::class),
                $app->make(WebhookChannelDriver::class),
                $app->make(SmsChannelDriver::class),
            ];

            return new ChannelDispatcher($drivers);
        });
    }
}
