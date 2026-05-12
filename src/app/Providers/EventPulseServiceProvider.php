<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\RequireScope;
use App\Http\Middleware\ThrottleApiRequests;
use App\Http\Middleware\ThrottleIpRequests;
use EventPulse\Application\Notification\Channel\ChannelDispatcher;
use EventPulse\Application\Notification\Channel\ChannelDriver;
use EventPulse\Application\Notification\Channel\WebhookEndpointResolver;
use EventPulse\Application\Notification\DeadLetter\Query\DeadLetteredNotificationsRepository;
use EventPulse\Application\Notification\NotificationDispatchQueue;
use EventPulse\Application\Notification\Retry\RetryPolicy;
use EventPulse\Application\Shared\Clock;
use EventPulse\Application\Shared\DomainEventDispatcher;
use EventPulse\Application\Shared\SystemClock;
use EventPulse\Application\WebhookDestination\Command\DisableWebhookDestinationHandler;
use EventPulse\Application\WebhookDestination\Command\RegisterWebhookDestinationHandler;
use EventPulse\Application\WebhookDestination\Query\ListWebhookDestinationsQueryHandler;
use EventPulse\Domain\Notification\Enum\Channel;
use EventPulse\Domain\Notification\Repository\NotificationRepository;
use EventPulse\Domain\WebhookDestination\Repository\WebhookDestinationRepository;
use EventPulse\Infrastructure\Logging\StructuredLogDomainEventDispatcher;
use EventPulse\Infrastructure\Notification\Channel\EloquentWebhookEndpointResolver;
use EventPulse\Infrastructure\Notification\Channel\EmailChannelDriver;
use EventPulse\Infrastructure\Notification\Channel\SmsChannelDriver;
use EventPulse\Infrastructure\Notification\Channel\WebhookChannelDriver;
use EventPulse\Infrastructure\Notification\Persistence\EloquentDeadLetteredNotificationsRepository;
use EventPulse\Infrastructure\Notification\Persistence\EloquentNotificationRepository;
use EventPulse\Infrastructure\Notification\Queue\LaravelNotificationDispatchQueue;
use EventPulse\Infrastructure\Notification\Retry\ChannelRetryPolicy;
use EventPulse\Infrastructure\Notification\Retry\RetrySettings;
use EventPulse\Infrastructure\WebhookDestination\Persistence\EloquentWebhookDestinationRepository;
use Illuminate\Contracts\Encryption\Encrypter;
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
 *  - `NotificationRepository`               (domain)      → `EloquentNotificationRepository`.
 *  - `Clock`                                (application) → `SystemClock`.
 *  - `DomainEventDispatcher`                (application) → `StructuredLogDomainEventDispatcher`.
 *  - `NotificationDispatchQueue`            (application) → `LaravelNotificationDispatchQueue`.
 *  - `WebhookEndpointResolver`              (application) → `EloquentWebhookEndpointResolver` (Day 9).
 *  - `WebhookDestinationRepository`         (domain)      → `EloquentWebhookDestinationRepository` (Day 9).
 *  - `ChannelDispatcher`                    (application) → constructed once with the three channel drivers.
 *  - `RetryPolicy`                          (application) → `ChannelRetryPolicy`.
 *  - `DeadLetteredNotificationsRepository`  (application) → `EloquentDeadLetteredNotificationsRepository`.
 *
 * Day 9 changes:
 *  1. `WebhookEndpointResolver` binding swapped from `UnconfiguredWebhookEndpointResolver`
 *     to `EloquentWebhookEndpointResolver`.
 *  2. `WebhookDestinationRepository` registered for the new CRUD operations.
 *  3. Three new application-layer handlers registered as singletons.
 *
 * Day 10 changes:
 *  1. `throttle.api` middleware alias registered → `ThrottleApiRequests`.
 *  2. `throttle.ip` middleware alias registered  → `ThrottleIpRequests`.
 */
final class EventPulseServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        Clock::class                               => SystemClock::class,
        DomainEventDispatcher::class               => StructuredLogDomainEventDispatcher::class,
        NotificationRepository::class              => EloquentNotificationRepository::class,
        NotificationDispatchQueue::class           => LaravelNotificationDispatchQueue::class,
        DeadLetteredNotificationsRepository::class => EloquentDeadLetteredNotificationsRepository::class,
    ];

    public function register(): void
    {
        $this->registerWebhookEndpointResolver();
        $this->registerWebhookDestinationRepository();
        $this->registerChannelDispatcher();
        $this->registerRetryPolicy();
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('auth.api-key',  AuthenticateApiKey::class);
        $router->aliasMiddleware('scope',         RequireScope::class);
        $router->aliasMiddleware('throttle.api',  ThrottleApiRequests::class);
        $router->aliasMiddleware('throttle.ip',   ThrottleIpRequests::class);
    }

    // ---------------------------------------------------------------------------
    // Day 9 — webhook destination infrastructure
    // ---------------------------------------------------------------------------

    private function registerWebhookEndpointResolver(): void
    {
        $resolverClass = EloquentWebhookEndpointResolver::class;
        
        $this->app->singleton(
            WebhookEndpointResolver::class,
            function (Application $app) use ($resolverClass): WebhookEndpointResolver {
                return new $resolverClass(
                    encrypter: $app->make(Encrypter::class),
                    logger:    $app->make(LoggerInterface::class),
                );
            },
        );
    }

    private function registerWebhookDestinationRepository(): void
    {
        $repositoryInterface = WebhookDestinationRepository::class;
        $repositoryClass     = EloquentWebhookDestinationRepository::class;

        if (!class_exists($repositoryClass)) {
            return;
        }

        $this->app->singleton(
            $repositoryInterface,
            fn (Application $app) => new $repositoryClass(encrypter: $app->make(Encrypter::class)),
        );

        foreach ([
            RegisterWebhookDestinationHandler::class,
            DisableWebhookDestinationHandler::class,
            ListWebhookDestinationsQueryHandler::class,
        ] as $handlerClass) {
            if (class_exists($handlerClass)) {
                $this->app->singleton($handlerClass);
            }
        }
    }

    // ---------------------------------------------------------------------------
    // Channel dispatch
    // ---------------------------------------------------------------------------

    private function registerChannelDispatcher(): void
    {
        $this->app->singleton(EmailChannelDriver::class, function (Application $app): EmailChannelDriver {
            $config      = $app['config']->get('mail.from', []);
            $fromAddress = is_string($config['address'] ?? null) ? trim($config['address']) : '';
            $fromName    = is_string($config['name']    ?? null) ? trim($config['name'])    : '';

            if ($fromAddress === '') {
                throw new \RuntimeException(
                    'EventPulse email driver requires mail.from.address to be configured '
                    . '(set MAIL_FROM_ADDRESS in your environment).',
                );
            }

            if ($fromName === '') {
                throw new \RuntimeException(
                    'EventPulse email driver requires mail.from.name to be configured '
                    . '(set MAIL_FROM_NAME in your environment).',
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
            return new SmsChannelDriver(logger: $app->make(LoggerInterface::class));
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

    // ---------------------------------------------------------------------------
    // Retry policy
    // ---------------------------------------------------------------------------

    private function registerRetryPolicy(): void
    {
        $this->app->singleton(RetryPolicy::class, function (Application $app): RetryPolicy {
            /** @var array<string, array{max_attempts:int, base_delay_seconds:int, max_delay_seconds:int, jitter_fraction:float}> $raw */
            $raw      = (array) $app['config']->get('eventpulse.retry', []);
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
