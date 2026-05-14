<?php

declare(strict_types=1);

namespace EventPulse\Application\WebhookDestination\Command;

use EventPulse\Application\Shared\Clock;
use EventPulse\Application\Shared\DomainEventDispatcher;
use EventPulse\Domain\Notification\ValueObject\CorrelationId;
use EventPulse\Domain\WebhookDestination\Exception\WebhookDestinationNotFoundException;
use EventPulse\Domain\WebhookDestination\Repository\WebhookDestinationRepository;
use EventPulse\Domain\WebhookDestination\ValueObject\WebhookDestinationId;

/**
 * Handles `DisableWebhookDestinationCommand`.
 *
 * Loads the aggregate by id+api_key_id (tenant isolation enforced by the
 * repository), calls `disable()`, persists, and releases events.
 *
 * Throws `WebhookDestinationNotFoundException` (renders as 404) when the
 * destination does not exist or belongs to a different API key.
 *
 * Throws `WebhookDestinationAlreadyDisabledException` (renders as 409) when
 * the destination has already been disabled.
 */
final class DisableWebhookDestinationHandler
{
    public function __construct(
        private readonly WebhookDestinationRepository $repository,
        private readonly Clock $clock,
        private readonly DomainEventDispatcher $eventDispatcher,
    ) {}

    public function __invoke(DisableWebhookDestinationCommand $command): void
    {
        $id          = WebhookDestinationId::fromString($command->destinationId);
        $destination = $this->repository->findById($id, $command->apiKeyId);

        if ($destination === null) {
            throw WebhookDestinationNotFoundException::forId($id);
        }

        $correlationId = $command->correlationId === null
            ? CorrelationId::generate()
            : CorrelationId::fromString($command->correlationId);

        $destination->disable(
            now:           $this->clock->now(),
            correlationId: $correlationId,
        );

        $this->repository->save($destination);

        foreach ($destination->pullPendingEvents() as $event) {
            $this->eventDispatcher->dispatch($event);
        }
    }
}
