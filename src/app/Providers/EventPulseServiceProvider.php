<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\RequireScope;
use EventPulse\Application\Notification\Channel\ChannelDispatcher;
use EventPulse\Application\Notification\Channel\ChannelDriver;
use EventPulse\Application\Notification\Channel\WebhookEndpointResolver;
use EventPulse\Application\Notification\NotificationDispatchQueue;
use EventPulse\Application\Notification\Retry\RetryPolicy;
use EventPulse\Application\Shared\Clock;
use EventPulse\Application\Shared\DomainEventDispatcher;
use EventPulse\Application\Shared\NullDomainEventDispatcher;
use EventPulse\Application\Shared\SystemClock;
use EventPulse\Domain\Notification\Enum\Channel;
use EventPulse\Domain\Notification\Repository\NotificationRepository;
use EventPulse\Infrastructure\Notification\Channel\EmailChannelDriver;
use EventPulse\Infrastructure\Notification\Channel\SmsChannelDriver;
use EventPulse\Infrastructure\Notification\Channel\UnconfiguredWebhookEndpointResolver;
use EventPulse\Infrastructure\Notification\Channel\WebhookChannelDriver;
use EventPulse\Infrastructure\Notification\Persistence\EloquentNotificationRepository;
use EventPulse\Infrastructure\Notification\Queue\LaravelNotificationDispatchQueue;
use EventPulse\Infrastructure\Notification\Retry\ChannelRetryPolicy;
use EventPulse\Infrastructure\Notification\Retry\RetrySettings;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Random\Engine\Secure;
use Random\Randomizer;

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
 *  - `RetryPolicy`                (application) → `ChannelRetryPolicy` (Day 6 introduces this).
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
        $this->registerRetryPolicy();
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
     * in a singleton means the driver instances are constructed once
     * per worker process — appropriate for `Mailer` and `HttpFactory`
     * which are stateful and benefit from connection reuse.
     *
     * The list of drivers is kept in this single method so adding a
     * new channel is one line here in addition to the new driver
     * class. The dispatcher's constructor will throw at boot if any
     * case of `Channel` is missing from this list.
     */
    private function registerChannelDispatcher(): void
    {
        $this->app->singleton(EmailChannelDriver::class, function (Application $app): EmailChannelDriver {
            // Strict resolution: every email dispatched by EventPulse must
            // carry a deterministic From address. Falling back to a hard-
            // coded "noreply@eventpulse.local" if the env is unset would
            // hide misconfiguration in dev and silently send mail with a
            // bogus sender in production. We'd rather fail at boot, where
            // the operator sees the cause directly, than ship the bug.
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

    /**
     * Build the `RetryPolicy` singleton from the spec table in config.
     *
     * The configuration shape (see `config/eventpulse.php`) mirrors
     * specification §5.2 one-to-one — adding a per-tenant override or a
     * fourth channel later is a config edit, not a code edit.
     *
     * The `Randomizer` uses the cryptographically-secure engine
     * (`Random\Engine\Secure`) rather than a Mersenne Twister with a
     * derived seed. Cryptographic security is not strictly necessary
     * for jitter, but `Secure` is the safe default — it cannot be
     * predicted by a co-tenant who learns one retry instant, which
     * prevents a synchronisation attack against a downstream receiver
     * that might happen to be rate-limited by source IP. The cost
     * difference is negligible at retry-event rates (a few per second
     * at worst).
     *
     * Tests substitute a seeded `Mt19937` engine via the test container
     * binding to make backoff assertions deterministic.
     */
    private function registerRetryPolicy(): void
    {
        $this->app->singleton(RetryPolicy::class, function (Application $app): RetryPolicy {
            /** @var array<string, array{max_attempts:int, base_delay_seconds:int, max_delay_seconds:int, jitter_fraction:float}> $raw */
            $raw = (array) $app['config']->get('eventpulse.retry', []);

            $settings = [];

            foreach (Channel::cases() as $channel) {
                $row = $raw[$channel->value] ?? null;

                if (! is_array($row)) {
                    throw new \RuntimeException(sprintf(
                        'EventPulse retry policy is missing a configuration row for channel "%s". '
                        . 'Expected config/eventpulse.php to define eventpulse.retry.%s.',
                        $channel->value,
                        $channel->value,
                    ));
                }

                $settings[$channel->value] = new RetrySettings(
                    maxAttempts:      (int)   $row['max_attempts'],
                    baseDelaySeconds: (int)   $row['base_delay_seconds'],
                    maxDelaySeconds:  (int)   $row['max_delay_seconds'],
                    jitterFraction:   (float) $row['jitter_fraction'],
                );
            }

            return new ChannelRetryPolicy(
                settings:   $settings,
                randomizer: new Randomizer(new Secure()),
            );
        });
    }
}
