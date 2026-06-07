<?php

declare(strict_types=1);

namespace EventPulse\Application\WebhookDestination\Command;

use EventPulse\Application\Shared\Clock;
use EventPulse\Application\Shared\DomainEventDispatcher;
use EventPulse\Domain\Notification\ValueObject\CorrelationId;
use EventPulse\Domain\WebhookDestination\Aggregate\WebhookDestination;
use EventPulse\Domain\WebhookDestination\Repository\WebhookDestinationRepository;
use EventPulse\Domain\WebhookDestination\ValueObject\WebhookDestinationId;

/**
 * Handles `RegisterWebhookDestinationCommand`.
 *
 * Responsibilities:
 *  1. Constructs the domain aggregate via `WebhookDestination::register()`.
 *  2. Persists it via the repository (passing the plaintext secret for
 *     encrypted storage — the aggregate never sees it again).
 *  3. Releases pending domain events to the dispatcher.
 *
 * Secret handling:
 *  The command carries the plaintext secret from the HTTP request. This
 *  handler passes it straight to `repository->save()` and never touches it
 *  otherwise. The result DTO carries it once more so the HTTP resource can
 *  include it in the creation response (the only time it is returned).
 *  After this handler returns, the plaintext secret is inaccessible — only
 *  the encrypted column value exists in the database.
 */
final class RegisterWebhookDestinationHandler
{
    public function __construct(
        private readonly WebhookDestinationRepository $repository,
        private readonly Clock $clock,
        private readonly DomainEventDispatcher $eventDispatcher,
    ) {}

    public function __invoke(RegisterWebhookDestinationCommand $command): RegisterWebhookDestinationResult
    {
        $id = WebhookDestinationId::generate();
        $now = $this->clock->now();
        $correlationId = $command->correlationId === null
            ? CorrelationId::generate()
            : CorrelationId::fromString($command->correlationId);

        $destination = WebhookDestination::register(
            id: $id,
            apiKeyId: $command->apiKeyId,
            url: $command->url,
            name: $command->name,
            now: $now,
            correlationId: $correlationId,
        );

        $this->repository->save($destination, $command->secret);

        foreach ($destination->pullPendingEvents() as $event) {
            $this->eventDispatcher->dispatch($event);
        }

        return new RegisterWebhookDestinationResult(
            id: $destination->id(),
            url: $destination->url(),
            name: $destination->name(),
            secret: $command->secret,
            status: $destination->status(),
            createdAt: $destination->createdAt(),
        );
    }
}
