<?php

declare(strict_types=1);

namespace Tests\EventPulse\Unit\Application\Notification\Query;

use EventPulse\Application\Notification\Query\GetNotificationQuery;
use EventPulse\Application\Notification\Query\GetNotificationQueryHandler;
use EventPulse\Application\Notification\Query\NotificationNotFoundException;
use EventPulse\Tests\Unit\Application\Support\InMemoryNotificationRepository;
use EventPulse\Tests\Unit\Domain\Notification\Support\NotificationMother;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour: `GetNotificationQueryHandler` returns the aggregate when found
 * and owned by the querying API key, and throws `NotificationNotFoundException`
 * in all not-found cases: unknown id, wrong tenant, and syntactically invalid
 * id. The caller must not be able to distinguish these cases from the exception
 * type — all map to 404 at the HTTP boundary.
 *
 * Runs without the Laravel container — pure PHP.
 */
final class GetNotificationQueryHandlerTest extends TestCase
{
    // A valid UUID v4 that is guaranteed to not exist in an empty repository.
    private const string MISSING_ID = 'a0000000-0000-4000-8000-000000000001';

    private InMemoryNotificationRepository $repository;

    private GetNotificationQueryHandler $handler;

    protected function setUp(): void
    {
        $this->repository = new InMemoryNotificationRepository;
        $this->handler = new GetNotificationQueryHandler($this->repository);
    }

    #[Test]
    public function returns_notification_when_id_and_tenant_match(): void
    {
        $notification = NotificationMother::emailNotification(apiKeyId: 'api-key-abc');
        $this->repository->save($notification);

        $result = ($this->handler)(new GetNotificationQuery(
            notificationId: $notification->id()->toString(),
            apiKeyId: 'api-key-abc',
        ));

        self::assertTrue($notification->id()->equals($result->id()));
    }

    #[Test]
    public function throws_when_notification_id_does_not_exist(): void
    {
        $this->expectException(NotificationNotFoundException::class);

        ($this->handler)(new GetNotificationQuery(
            notificationId: self::MISSING_ID,
            apiKeyId: 'api-key-abc',
        ));
    }

    #[Test]
    public function throws_when_api_key_does_not_own_the_notification(): void
    {
        $notification = NotificationMother::emailNotification(apiKeyId: 'api-key-owner');
        $this->repository->save($notification);

        $this->expectException(NotificationNotFoundException::class);

        ($this->handler)(new GetNotificationQuery(
            notificationId: $notification->id()->toString(),
            apiKeyId: 'api-key-different-tenant',
        ));
    }

    #[Test]
    public function throws_when_notification_id_is_syntactically_invalid(): void
    {
        // A non-UUID-v4 string must surface as NotificationNotFoundException,
        // not the domain VO's InvalidArgumentException. The handler absorbs
        // the VO error so the exception type is consistent across all
        // not-found cases.
        $this->expectException(NotificationNotFoundException::class);

        ($this->handler)(new GetNotificationQuery(
            notificationId: 'not-a-uuid-at-all',
            apiKeyId: 'api-key-abc',
        ));
    }

    #[Test]
    public function unknown_id_and_wrong_tenant_produce_same_exception_type(): void
    {
        // Both "id doesn't exist" and "id belongs to another tenant" must throw
        // the same exception class. The HTTP layer maps both to 404 with no
        // distinction — a different exception type would allow a caller to
        // enumerate other tenants' notification ids by observing 404 vs 403.
        $notification = NotificationMother::emailNotification(apiKeyId: 'api-key-owner');
        $this->repository->save($notification);

        $wrongTenantException = null;
        $unknownIdException = null;

        try {
            ($this->handler)(new GetNotificationQuery(
                notificationId: $notification->id()->toString(),
                apiKeyId: 'api-key-other',
            ));
        } catch (NotificationNotFoundException $e) {
            $wrongTenantException = $e;
        }

        try {
            ($this->handler)(new GetNotificationQuery(
                notificationId: self::MISSING_ID,
                apiKeyId: 'api-key-owner',
            ));
        } catch (NotificationNotFoundException $e) {
            $unknownIdException = $e;
        }

        self::assertInstanceOf(NotificationNotFoundException::class, $wrongTenantException);
        self::assertInstanceOf(NotificationNotFoundException::class, $unknownIdException);
        self::assertSame($wrongTenantException::class, $unknownIdException::class);
    }
}
